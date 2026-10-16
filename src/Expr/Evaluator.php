<?php

declare(strict_types=1);

namespace Phibble\Expr;

use DateTimeInterface;
use InvalidArgumentException;
use Phibble\Column\Infer;
use Phibble\Contracts\Table;
use Phibble\Type;

// Helpers in this namespace shadow these built-ins (see helpers.php).
use function abs;
use function exp;
use function floor;
use function log;
use function max;
use function min;
use function round;
use function sqrt;
use function str_replace;

/**
 * Evaluates expressions over one group of an in-memory table, with R's
 * semantics: NA propagates, logic is three-valued, aggregates return one
 * value, window functions run in row order.
 *
 * A result is either a list (one value per row) or a Scalar.
 *
 * @internal
 */
final class Evaluator
{
    private int $n;

    /** @var array<string, list<mixed>> */
    private array $cache = [];

    /** @var \Closure(string): list<mixed> */
    private \Closure $column;

    public function __construct(Table $table)
    {
        $this->n = $table->nrow();
        $this->column = $table->column(...);
    }

    /**
     * Evaluate over rows supplied column by column, e.g. a group's slice of
     * a larger table, without building a table for it.
     *
     * @param \Closure(string): list<mixed> $column Values of a column, by name.
     */
    public static function over(\Closure $column, int $rows): self
    {
        $evaluator = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $evaluator->n = $rows;
        $evaluator->column = $column;

        return $evaluator;
    }

    /** Evaluate to one value per row; scalars are repeated. */
    public function vector(Expr $expr): array
    {
        $result = $this->eval($expr);

        return $result instanceof Scalar ? array_fill(0, $this->n, $result->value) : $result;
    }

    /** Evaluate to a single value, as summarise() needs. */
    public function scalar(Expr $expr, string $name = ''): mixed
    {
        $result = $this->eval($expr);
        if ($result instanceof Scalar) {
            return $result->value;
        }
        if (count($result) !== 1) {
            throw new \LengthException(
                "summarise() '{$name}' must be size 1, not ".count($result).'. Use an aggregate such as mean() or n(), or reframe() for several rows per group.'
            );
        }

        return $result[0];
    }

    /** @return list<mixed>|Scalar */
    public function eval(Expr $expr): array|Scalar
    {
        return match (true) {
            $expr instanceof Col => $this->cache[$expr->name] ??= ($this->column)($expr->name),
            $expr instanceof Lit => new Scalar($expr->value),
            $expr instanceof Call => $this->call($expr),
            $expr instanceof Desc => throw new InvalidArgumentException('desc() is only allowed in arrange(), ranking functions and order_by.'),
        };
    }

    /** @return list<mixed>|Scalar */
    private function call(Call $call): array|Scalar
    {
        $fn = $call->fn;
        $kind = Functions::kind($fn);
        if ($kind === 'agg') {
            return new Scalar($this->aggregate($call));
        }
        if ($kind === 'window') {
            return $this->window($call);
        }

        $args = array_map(fn (Expr $a) => $this->eval($a), $call->args);

        return match ($fn) {
            '+', '-', '*', '/', '%%', '^' => $this->numericBinary($fn, ...$args) ?? $this->map(fn ($a, $b) => self::arith($fn, $a, $b), ...$args),
            'neg' => $this->map(fn ($a) => $a === null ? null : -self::num($a), ...$args),
            '==', '!=', '<', '<=', '>', '>=' => $this->numericBinary($fn, ...$args) ?? $this->map(fn ($a, $b) => self::compare($fn, $a, $b), ...$args),
            '&' => $this->map(fn ($a, $b) => match (true) {
                $a === false || $b === false => false,
                $a === null || $b === null => null,
                default => (bool) $a && (bool) $b,
            }, ...$args),
            '|' => $this->map(fn ($a, $b) => match (true) {
                $a === true || $b === true => true,
                $a === null || $b === null => null,
                default => (bool) $a || (bool) $b,
            }, ...$args),
            '!' => $this->map(fn ($a) => $a === null ? null : ! $a, ...$args),
            'is_na' => $this->map(fn ($a) => $a === null || (is_float($a) && is_nan($a)), ...$args),
            'if_else' => $this->ifElse($args, $call->option('missing')),
            'case_when' => $this->caseWhen($args, $call->option('default')),
            'coalesce' => $this->map(function (...$values) {
                foreach ($values as $v) {
                    if ($v !== null) {
                        return $v;
                    }
                }

                return null;
            }, ...$args),
            'in' => $this->map(fn ($a, $set) => in_array($a, (array) $set, false) && ($a !== null || in_array(null, (array) $set, true))
                ? true
                : self::inLoose($a, (array) $set), ...$args),
            'between' => $this->map(fn ($x, $lo, $hi) => $x === null || $lo === null || $hi === null
                ? null
                : self::compare('>=', $x, $lo) && self::compare('<=', $x, $hi), ...$args),
            'na_if' => $this->map(fn ($x, $y) => self::compare('==', $x, $y) === true ? null : $x, ...$args),
            'near' => $this->map(fn ($x, $y, $tol) => $x === null || $y === null ? null : abs(self::num($x) - self::num($y)) < $tol,
                ...[...$args, $this->option($call, 'tol', sqrt(PHP_FLOAT_EPSILON))]),
            'abs' => $this->map(fn ($a) => $a === null ? null : abs(self::num($a)), ...$args),
            'sqrt' => $this->map(fn ($a) => $a === null ? null : (self::num($a) < 0 ? NAN : sqrt(self::num($a))), ...$args),
            'exp' => $this->map(fn ($a) => $a === null ? null : exp(self::num($a)), ...$args),
            'log' => $this->map(fn ($a, $base) => $a === null ? null : self::log(self::num($a), $base),
                ...[...$args, $this->option($call, 'base', M_E)]),
            'log10' => $this->map(fn ($a) => $a === null ? null : self::log(self::num($a), 10), ...$args),
            'round' => $this->map(fn ($a, $d) => $a === null ? null : self::round(self::num($a), (int) $d),
                ...[...$args, $this->option($call, 'digits', 0)]),
            'floor' => $this->map(fn ($a) => $a === null ? null : floor(self::num($a)), ...$args),
            'ceiling' => $this->map(fn ($a) => $a === null ? null : ceil(self::num($a)), ...$args),
            'as_int', 'as_float', 'as_string', 'as_bool' => $this->cast($call->args[0], substr($fn, 3)),
            'str_lower' => $this->map(fn ($s) => $s === null ? null : mb_strtolower(self::str($s)), ...$args),
            'str_upper' => $this->map(fn ($s) => $s === null ? null : mb_strtoupper(self::str($s)), ...$args),
            'str_length' => $this->map(fn ($s) => $s === null ? null : mb_strlen(self::str($s)), ...$args),
            'str_trim' => $this->map(fn ($s, $side) => $s === null ? null : match ($side) {
                'left' => ltrim(self::str($s)),
                'right' => rtrim(self::str($s)),
                default => trim(self::str($s)),
            }, ...[...$args, $this->option($call, 'side', 'both')]),
            'str_sub' => $this->map(fn ($s, $start, $end = -1) => $s === null || $start === null || $end === null
                ? null
                : self::strSub(self::str($s), (int) $start, (int) $end), ...$args),
            'str_detect', 'str_starts', 'str_ends' => $this->map(function ($s, $pattern, $negate) use ($fn) {
                if ($s === null || $pattern === null) {
                    return null;
                }
                $found = match ($fn) {
                    'str_detect' => str_contains(self::str($s), (string) $pattern),
                    'str_starts' => str_starts_with(self::str($s), (string) $pattern),
                    'str_ends' => str_ends_with(self::str($s), (string) $pattern),
                };

                return $negate ? ! $found : $found;
            }, ...[...$args, $this->option($call, 'negate', false)]),
            'str_replace' => $this->map(fn ($s, $p, $r) => $s === null || $p === null || $r === null
                ? null
                : self::replaceFirst(self::str($s), (string) $p, (string) $r), ...$args),
            'str_replace_all' => $this->map(fn ($s, $p, $r) => $s === null || $p === null || $r === null
                ? null
                : str_replace((string) $p, (string) $r, self::str($s)), ...$args),
            'paste0' => $this->map(fn (...$parts) => implode('', array_map(self::pasteable(...), $parts)), ...$args),
            'paste' => $this->map(fn (...$parts) => implode((string) array_pop($parts), array_map(self::pasteable(...), $parts)),
                ...[...$args, $this->option($call, 'sep', ' ')]),
        };
    }

    // ---------------------------------------------------------------
    // Aggregates
    // ---------------------------------------------------------------

    private function aggregate(Call $call): mixed
    {
        $fn = $call->fn;
        if ($fn === 'n') {
            return $this->n;
        }
        $naRm = (bool) $this->value($call, 'na_rm', false);

        if ($fn === 'n_distinct') {
            $columns = array_map(fn (Expr $a) => $this->vector($a), $call->args);
            $seen = [];
            for ($i = 0; $i < $this->n; $i++) {
                $tuple = array_map(fn (array $c) => $c[$i], $columns);
                if ($naRm && in_array(null, $tuple, true)) {
                    continue;
                }
                $seen[serialize(array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('U.u') : $v, $tuple))] = true;
            }

            return count($seen);
        }

        $x = $this->vector($call->args[0]);
        if (in_array($fn, ['first', 'last', 'nth'], true)) {
            return $this->nth($call, $x, $naRm);
        }

        $hasNa = in_array(null, $x, true);
        $present = array_values(array_filter($x, fn ($v) => $v !== null));
        if ($hasNa && ! $naRm) {
            if (in_array($fn, ['quantile', 'iqr'], true)) {
                throw new InvalidArgumentException("missing values and NaN's not allowed if 'na_rm' is FALSE");
            }
            if ($fn === 'any' && in_array(true, $present, false)) {
                return true;
            }
            if ($fn === 'all' && in_array(false, $present, false)) {
                return false;
            }

            return null;
        }

        return match ($fn) {
            'sum' => array_all($present, fn ($v) => is_int($v) || is_bool($v))
                ? array_sum(array_map(intval(...), $present))
                : array_sum(array_map(self::num(...), $present)),
            'mean' => $present === [] ? NAN : (float) array_sum(array_map(self::num(...), $present)) / count($present),
            'median' => $present === [] ? null : self::quantile(array_map(self::num(...), $present), 0.5),
            'min', 'max' => $present === [] ? ($fn === 'min' ? INF : -INF) : self::extreme($fn, $present),
            'sd', 'var' => self::variance(array_map(self::num(...), $present), $fn === 'sd'),
            'quantile' => self::quantile(array_map(self::num(...), $present), (float) $this->scalarArg($call->args[1])),
            'iqr' => self::quantile(array_map(self::num(...), $present), 0.75) - self::quantile(array_map(self::num(...), $present), 0.25),
            'any' => in_array(true, $present, false),
            'all' => ! in_array(false, $present, false),
        };
    }

    /** @param list<mixed> $x */
    private function nth(Call $call, array $x, bool $naRm): mixed
    {
        $default = $this->value($call, 'default', null);
        $order = $call->option('order_by');
        if ($order !== null) {
            $x = array_map(fn (int $i) => $x[$i], $this->order($order));
        }
        if ($naRm) {
            $x = array_values(array_filter($x, fn ($v) => $v !== null));
        }
        $n = match ($call->fn) {
            'first' => 1,
            'last' => -1,
            'nth' => (int) $this->scalarArg($call->args[1]),
        };
        $index = $n > 0 ? $n - 1 : count($x) + $n;

        return $n !== 0 && $index >= 0 && $index < count($x) ? $x[$index] : $default;
    }

    // ---------------------------------------------------------------
    // Window functions
    // ---------------------------------------------------------------

    /** @return list<mixed> */
    private function window(Call $call): array
    {
        $fn = $call->fn;

        if ($fn === 'lag' || $fn === 'lead') {
            $x = $this->vector($call->args[0]);
            $k = (int) $this->value($call, 'n', 1);
            $default = $this->value($call, 'default', null);
            $order = $call->option('order_by') === null ? range(0, max(0, $this->n - 1)) : $this->order($call->option('order_by'));
            if ($this->n === 0) {
                return [];
            }
            $shift = $fn === 'lag' ? -$k : $k;
            $out = array_fill(0, $this->n, null);
            foreach ($order as $pos => $row) {
                $from = $pos + $shift;
                $out[$row] = $from >= 0 && $from < $this->n ? $x[$order[$from]] : $default;
            }

            return $out;
        }

        if (in_array($fn, Functions::RANKING, true)) {
            return $this->rank($call);
        }

        if ($fn === 'consecutive_id') {
            $columns = array_map(fn (Expr $a) => $this->vector($a), $call->args);
            $out = [];
            $id = 0;
            $previous = null;
            for ($i = 0; $i < $this->n; $i++) {
                $key = serialize(array_map(fn (array $c) => $c[$i] instanceof DateTimeInterface ? $c[$i]->format('U.u') : $c[$i], $columns));
                if ($i === 0 || $key !== $previous) {
                    $id++;
                }
                $previous = $key;
                $out[] = $id;
            }

            return $out;
        }

        $x = $this->vector($call->args[0]);
        $out = [];
        $state = null;
        $poisoned = false;
        foreach ($x as $i => $v) {
            if ($fn === 'cumall' || $fn === 'cumany') {
                $stop = $fn === 'cumall' ? false : true;
                if ($state === $stop) {
                    $out[] = $stop;

                    continue;
                }
                if ($v !== null && (bool) $v === $stop) {
                    $state = $stop;
                } elseif ($v === null) {
                    $state = 'na';
                } elseif ($state === null) {
                    $state = ! $stop;
                }
                $out[] = $state === 'na' ? null : $state;

                continue;
            }
            if ($poisoned || $v === null) {
                $poisoned = true;
                $out[] = null;

                continue;
            }
            $state = match ($fn) {
                'cumsum' => ($state ?? 0) + (is_bool($v) ? (int) $v : $v),
                'cumprod' => ($state ?? 1) * (is_bool($v) ? (int) $v : $v),
                'cummean' => ($state ?? 0) + self::num($v),
                'cummin' => $state === null ? $v : min($state, $v),
                'cummax' => $state === null ? $v : max($state, $v),
            };
            $out[] = $fn === 'cummean' ? $state / ($i + 1) : $state;
        }

        return $out;
    }

    /** @return list<?int|?float> */
    private function rank(Call $call): array
    {
        $fn = $call->fn;
        if ($call->args === [] && $fn === 'row_number') {
            return $this->n === 0 ? [] : range(1, $this->n);
        }
        if ($call->args === []) {
            $values = range(0, max(0, $this->n - 1));
            $desc = false;
        } else {
            $arg = $call->args[0];
            $desc = $arg instanceof Desc;
            $values = $this->vector($desc ? $arg->expr : $arg);
        }
        if ($this->n === 0) {
            return [];
        }

        $present = array_keys(array_filter($values, fn ($v) => $v !== null));
        $isString = array_all($present, fn ($i) => is_string($values[$i]));
        usort($present, function (int $a, int $b) use ($values, $desc, $isString): int {
            $cmp = $isString ? strcmp($values[$a], $values[$b]) <=> 0 : $values[$a] <=> $values[$b];

            return ($desc ? -$cmp : $cmp) ?: $a <=> $b;
        });
        $m = count($present);
        $out = array_fill(0, $this->n, null);

        $rowNumber = [];
        foreach ($present as $pos => $i) {
            $rowNumber[$i] = $pos + 1;
        }
        if ($fn === 'row_number') {
            foreach ($rowNumber as $i => $r) {
                $out[$i] = $r;
            }

            return $out;
        }
        if ($fn === 'ntile') {
            $bins = (int) floor((float) $this->value($call, 'n', 1));
            if ($m === 0) {
                return $out;
            }
            $larger = $m % $bins;
            $largerSize = (int) ceil($m / $bins);
            $smallerSize = (int) floor($m / $bins);
            $threshold = $largerSize * $larger;
            foreach ($rowNumber as $i => $r) {
                $out[$i] = $r <= $threshold
                    ? intdiv($r + $largerSize - 1, $largerSize)
                    : intdiv($r - $threshold + $smallerSize - 1, max(1, $smallerSize)) + $larger;
            }

            return $out;
        }

        // Ties share the minimum rank; dense ranks count distinct values.
        $min = [];
        $dense = [];
        $last = null;
        $denseCounter = 0;
        $start = 0;
        foreach ($present as $pos => $i) {
            $key = serialize($values[$i] instanceof DateTimeInterface ? $values[$i]->format('U.u') : $values[$i]);
            if ($key !== $last) {
                $start = $pos + 1;
                $denseCounter++;
                $last = $key;
            }
            $min[$i] = $start;
            $dense[$i] = $denseCounter;
        }
        if ($fn === 'cume_dist') {
            // Share of values <= this one: the last position of each tie run.
            $maxPos = [];
            foreach ($present as $pos => $i) {
                $maxPos[$dense[$i]] = $pos + 1;
            }
        }

        foreach ($present as $i) {
            $out[$i] = match ($fn) {
                'min_rank' => $min[$i],
                'dense_rank' => $dense[$i],
                'percent_rank' => $m <= 1 ? NAN : ($min[$i] - 1) / ($m - 1),
                'cume_dist' => $maxPos[$dense[$i]] / $m,
            };
        }

        return $out;
    }

    /**
     * Row order by an order_by expression (NA last, stable).
     *
     * @return list<int>
     */
    private function order(Expr $by): array
    {
        $desc = $by instanceof Desc;
        $values = $this->vector($desc ? $by->expr : $by);
        $order = $this->n === 0 ? [] : range(0, $this->n - 1);
        usort($order, function (int $a, int $b) use ($values, $desc): int {
            [$x, $y] = [$values[$a], $values[$b]];
            if ($x === null || $y === null) {
                return (($x === null) <=> ($y === null)) ?: $a <=> $b;
            }
            $cmp = is_string($x) && is_string($y) ? strcmp($x, $y) <=> 0 : $x <=> $y;

            return ($desc ? -$cmp : $cmp) ?: $a <=> $b;
        });

        return $order;
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Apply $fn element-wise, repeating scalar arguments.
     *
     * @return list<mixed>|Scalar
     */
    private function map(\Closure $fn, array|Scalar ...$args): array|Scalar
    {
        $vectors = array_filter($args, fn ($a) => is_array($a));
        if ($vectors === []) {
            return new Scalar($fn(...array_map(fn (Scalar $s) => $s->value, $args)));
        }
        $out = [];
        // One and two arguments are the common cases; avoid building an
        // argument list per element for them.
        if (count($args) === 1) {
            foreach ($args[0] as $a) {
                $out[] = $fn($a);
            }

            return $out;
        }
        if (count($args) === 2) {
            [$a, $b] = $args;
            if ($a instanceof Scalar) {
                $av = $a->value;
                foreach ($b as $bv) {
                    $out[] = $fn($av, $bv);
                }
            } elseif ($b instanceof Scalar) {
                $bv = $b->value;
                foreach ($a as $av) {
                    $out[] = $fn($av, $bv);
                }
            } else {
                foreach ($a as $i => $av) {
                    $out[] = $fn($av, $b[$i]);
                }
            }

            return $out;
        }
        for ($i = 0; $i < $this->n; $i++) {
            $out[] = $fn(...array_map(fn ($a) => $a instanceof Scalar ? $a->value : $a[$i], $args));
        }

        return $out;
    }

    /**
     * Arithmetic and comparison on numbers without a call per element.
     * Returns null (so the general path runs) when a value is not an int
     * or float, or for operators with special cases.
     *
     * @return list<mixed>|null
     */
    private function numericBinary(string $op, array|Scalar $a, array|Scalar $b): ?array
    {
        if (! in_array($op, ['+', '-', '*', '<', '<=', '>', '>=', '==', '!='], true) || (! is_array($a) && ! is_array($b))) {
            return null;
        }
        $as = $a instanceof Scalar;
        $bs = $b instanceof Scalar;
        $av = $as ? $a->value : null;
        $bv = $bs ? $b->value : null;
        if (($as && ! (is_int($av) || is_float($av))) || ($bs && ! (is_int($bv) || is_float($bv)))) {
            return null;
        }
        $out = [];
        for ($i = 0; $i < $this->n; $i++) {
            $x = $as ? $av : $a[$i];
            $y = $bs ? $bv : $b[$i];
            if ($x === null || $y === null) {
                $out[] = null;

                continue;
            }
            if (! (is_int($x) || is_float($x)) || ! (is_int($y) || is_float($y))) {
                return null;
            }
            $out[] = match ($op) {
                '+' => $x + $y,
                '-' => $x - $y,
                '*' => $x * $y,
                '<' => $x < $y,
                '<=' => $x <= $y,
                '>' => $x > $y,
                '>=' => $x >= $y,
                '==' => $x == $y,
                '!=' => $x != $y,
            };
        }

        return $out;
    }

    /** @param list<array|Scalar> $args */
    private function ifElse(array $args, ?Expr $missing): array|Scalar
    {
        $args[] = $missing === null ? new Scalar(null) : $this->eval($missing);

        return $this->map(fn ($cond, $yes, $no, $na) => match (true) {
            $cond === null => $na,
            (bool) $cond => $yes,
            default => $no,
        }, ...$args);
    }

    /** @param list<array|Scalar> $args */
    private function caseWhen(array $args, ?Expr $default): array|Scalar
    {
        $args[] = $default === null ? new Scalar(null) : $this->eval($default);

        return $this->map(function (...$values) {
            $fallback = array_pop($values);
            for ($k = 0; $k < count($values); $k += 2) {
                if ($values[$k] === true || ($values[$k] !== null && $values[$k] !== false && (bool) $values[$k])) {
                    return $values[$k + 1];
                }
            }

            return $fallback;
        }, ...$args);
    }

    private function cast(Expr $arg, string $type): array|Scalar
    {
        $result = $this->eval($arg);
        $values = $result instanceof Scalar ? [$result->value] : $result;
        $cast = Infer::convert(Infer::column($values), Type::parse($type === 'int' ? 'int' : ($type === 'float' ? 'float' : ($type === 'string' ? 'string' : 'bool'))))->values();

        return $result instanceof Scalar ? new Scalar($cast[0]) : $cast;
    }

    /** A named option as a Scalar, for map(). */
    private function option(Call $call, string $name, mixed $default): Scalar
    {
        return new Scalar($this->value($call, $name, $default));
    }

    /** A named option's value. */
    private function value(Call $call, string $name, mixed $default): mixed
    {
        $expr = $call->option($name);

        return $expr === null ? $default : $this->scalarArg($expr);
    }

    private function scalarArg(Expr $expr): mixed
    {
        $value = $this->eval($expr);

        return $value instanceof Scalar ? $value->value : ($value[0] ?? null);
    }

    private static function num(mixed $v): int|float
    {
        return match (true) {
            is_int($v), is_float($v) => $v,
            is_bool($v) => (int) $v,
            is_string($v) && is_numeric($v) => $v + 0,
            $v instanceof DateTimeInterface => (float) $v->format('U.u'),
            default => throw new InvalidArgumentException('Non-numeric argument to a numeric function: '.var_export($v, true).'.'),
        };
    }

    private static function str(mixed $v): string
    {
        return match (true) {
            is_string($v) => $v,
            is_float($v) => Infer::formatFloat($v),
            is_bool($v) => $v ? 'TRUE' : 'FALSE',
            $v instanceof DateTimeInterface => $v->format('H:i:s') === '00:00:00' ? $v->format('Y-m-d') : $v->format('Y-m-d H:i:s'),
            default => (string) $v,
        };
    }

    private static function pasteable(mixed $v): string
    {
        return $v === null ? 'NA' : self::str($v);
    }

    private static function arith(string $op, mixed $a, mixed $b): int|float|null
    {
        if ($a === null || $b === null) {
            return null;
        }
        $a = self::num($a);
        $b = self::num($b);
        $ints = is_int($a) && is_int($b);

        return match ($op) {
            '+' => $a + $b,
            '-' => $a - $b,
            '*' => $a * $b,
            '/' => $b == 0 ? ($a == 0 || is_nan((float) $a) ? NAN : ($a > 0 ? INF : -INF)) : $a / $b,
            '^' => (float) ($a ** $b),
            '%%' => $b == 0 ? ($ints ? null : NAN) : ($ints ? $a - (int) floor($a / $b) * $b : $a - floor($a / $b) * $b),
        };
    }

    private static function compare(string $op, mixed $a, mixed $b): ?bool
    {
        if ($a === null || $b === null) {
            return null;
        }
        $numeric = fn ($v) => is_int($v) || is_float($v) || is_bool($v);
        $cmp = match (true) {
            $numeric($a) && $numeric($b) => self::num($a) <=> self::num($b),
            $a instanceof DateTimeInterface && $b instanceof DateTimeInterface => $a <=> $b,
            $a instanceof DateTimeInterface => strcmp(self::str($a), self::str($b)) <=> 0,
            default => strcmp(self::str($a), self::str($b)) <=> 0,
        };

        return match ($op) {
            '==' => $cmp === 0,
            '!=' => $cmp !== 0,
            '<' => $cmp < 0,
            '<=' => $cmp <= 0,
            '>' => $cmp > 0,
            '>=' => $cmp >= 0,
        };
    }

    /** @param list<mixed> $set */
    private static function inLoose(mixed $a, array $set): bool
    {
        if ($a === null) {
            return in_array(null, $set, true);
        }
        foreach ($set as $v) {
            if ($v !== null && self::compare('==', $a, $v) === true) {
                return true;
            }
        }

        return false;
    }

    private static function log(float|int $x, mixed $base): float
    {
        if ($x < 0) {
            return NAN;
        }
        if ($x == 0) {
            return -INF;
        }

        return $base == M_E ? log($x) : log($x) / log((float) $base);
    }

    /**
     * R's round() (R >= 4.0): of the two candidates at $digits, take the
     * one closer to the stored double; exact ties go to the even digit.
     * So round(2.675, 2) is 2.67, because 2.675 is stored as 2.67499...
     */
    private static function round(float|int $x, int $digits): float|int
    {
        if (is_int($x) && $digits >= 0) {
            return $x;
        }
        $x = (float) $x;
        if (! is_finite($x)) {
            return $x;
        }
        $scale = 10 ** $digits;
        $lo = floor($x * $scale) / $scale;
        $hi = ceil($x * $scale) / $scale;
        if ($lo === $hi) {
            return $lo;
        }
        $dLo = $x - $lo;
        $dHi = $hi - $x;
        if ($dLo !== $dHi) {
            return $dLo < $dHi ? $lo : $hi;
        }
        // An exact tie: choose the candidate whose last digit is even.
        return fmod(floor($x * $scale), 2.0) == 0.0 ? $lo : $hi;
    }

    private static function strSub(string $s, int $start, int $end): string
    {
        $length = mb_strlen($s);
        $from = $start < 0 ? $length + $start + 1 : max(1, $start);
        $to = $end < 0 ? $length + $end + 1 : min($end, $length);
        $from = max(1, $from);

        return $to < $from ? '' : mb_substr($s, $from - 1, $to - $from + 1);
    }

    private static function replaceFirst(string $s, string $pattern, string $replacement): string
    {
        $pos = $pattern === '' ? false : strpos($s, $pattern);

        return $pos === false ? $s : substr_replace($s, $replacement, $pos, strlen($pattern));
    }

    /** @param list<mixed> $present */
    private static function extreme(string $fn, array $present): mixed
    {
        $best = $present[0];
        $strings = is_string($best);
        foreach ($present as $v) {
            $cmp = $strings ? strcmp((string) $v, (string) $best) : $v <=> $best;
            if (($fn === 'min' && $cmp < 0) || ($fn === 'max' && $cmp > 0)) {
                $best = $v;
            }
        }

        return $best;
    }

    /** @param list<float|int> $x */
    private static function variance(array $x, bool $sd): ?float
    {
        $n = count($x);
        if ($n < 2) {
            return null;
        }
        $mean = array_sum($x) / $n;
        $ss = 0.0;
        foreach ($x as $v) {
            $ss += ($v - $mean) ** 2;
        }
        $var = $ss / ($n - 1);

        return $sd ? sqrt($var) : $var;
    }

    /**
     * R's default quantile (type 7).
     *
     * @param list<float|int> $x
     */
    private static function quantile(array $x, float $p): ?float
    {
        if ($x === []) {
            return null;
        }
        sort($x);
        $h = (count($x) - 1) * $p;
        $lo = (int) floor($h);
        $hi = (int) ceil($h);

        return (float) ($x[$lo] + ($h - $lo) * ($x[$hi] - $x[$lo]));
    }
}
