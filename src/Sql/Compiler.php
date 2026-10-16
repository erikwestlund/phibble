<?php

declare(strict_types=1);

namespace Phibble\Sql;

use DateTimeInterface;
use Phibble\Expr\Call;
use Phibble\Expr\Col;
use Phibble\Expr\Desc;
use Phibble\Expr\Expr;
use Phibble\Expr\Functions;
use Phibble\Expr\Lit;

/**
 * Compiles expressions to SQL with R's semantics where SQL differs:
 * aggregates return NA when any input is NA (unless na_rm), cumulative
 * functions stay NA after the first NA, ranks leave NA unranked. Anything
 * without a faithful translation throws Untranslatable.
 *
 * Mode 'row' (mutate, filter) turns aggregates and window functions into
 * window expressions partitioned by the groups; mode 'summary' (summarise)
 * emits plain aggregates.
 *
 * @internal
 */
final class Compiler
{
    /** Functions whose result is logical, so collected 0/1 become bools. */
    public const array LOGICAL = [
        '==', '!=', '<', '<=', '>', '>=', '&', '|', '!', 'is_na', 'in', 'between', 'near',
        'str_detect', 'str_starts', 'str_ends', 'any', 'all', 'cumall', 'cumany', 'as_bool',
    ];

    /**
     * @param list<string> $groups Partition columns for window expressions.
     * @param list<array{0: Expr, 1: bool}> $order The table's row order (expr, descending).
     */
    public function __construct(
        private readonly Dialect $dialect,
        private readonly array $groups = [],
        private readonly array $order = [],
        private readonly string $mode = 'row',
    ) {}

    public function compile(Expr $expr): Fragment
    {
        return match (true) {
            $expr instanceof Col => Fragment::raw($this->dialect->quote($expr->name)),
            $expr instanceof Lit => $this->literal($expr),
            $expr instanceof Call => $this->call($expr),
            $expr instanceof Desc => throw new Untranslatable('desc() is only allowed in arrange(), ranking functions and order_by.'),
        };
    }

    /** Whether the expression is logical-valued (for type hints). */
    public static function isLogical(Expr $expr): bool
    {
        return $expr instanceof Call && in_array($expr->fn, self::LOGICAL, true)
            || $expr instanceof Lit && is_bool($expr->value);
    }

    /** Whether the expression contains an aggregate or window function. */
    public static function hasWindow(Expr $expr): bool
    {
        if (! $expr instanceof Call) {
            return $expr instanceof Desc && self::hasWindow($expr->expr);
        }
        if (in_array(Functions::kind($expr->fn), ['agg', 'window'], true)) {
            return true;
        }
        foreach ([...$expr->args, ...array_values($expr->named)] as $arg) {
            if (self::hasWindow($arg)) {
                return true;
            }
        }

        return false;
    }

    /** ORDER BY terms with NA last in both directions. */
    public function orderBy(array $order, bool $reverse = false): Fragment
    {
        $terms = [];
        foreach ($order as [$expr, $desc]) {
            $sql = $this->compile($expr);
            // $reverse gives the exact reverse order, NA first.
            $terms[] = Fragment::format('({} IS NULL)'.($reverse ? ' DESC' : '').', {}'.($desc !== $reverse ? ' DESC' : ''), [$sql, $sql]);
        }

        return Fragment::join(', ', $terms);
    }

    private function literal(Lit $lit): Fragment
    {
        $value = $lit->value;

        return match (true) {
            $value === null => Fragment::raw('NULL'),
            $value === true => Fragment::raw('TRUE'),
            $value === false => Fragment::raw('FALSE'),
            is_array($value) => Fragment::join(', ', array_map(fn ($v) => $this->literal(new Lit($v)), $value)),
            $value instanceof DateTimeInterface => Fragment::param($lit->dtype === 'date' ? $value->format('Y-m-d') : $value->format('Y-m-d H:i:s')),
            is_float($value) && ! is_finite($value) => throw new Untranslatable('Infinite or NaN literals have no portable SQL form.'),
            default => Fragment::param($value),
        };
    }

    private function call(Call $call): Fragment
    {
        $fn = $call->fn;
        $kind = Functions::kind($fn);
        if ($kind === 'agg') {
            return $this->aggregate($call);
        }
        if ($kind === 'window') {
            if ($this->mode === 'summary') {
                throw new Untranslatable("{$fn}() is a window function and cannot be used in summarise().");
            }

            return $this->window($call);
        }

        $a = fn (int $i) => $this->compile($call->args[$i]);
        $args = fn () => array_map(fn (Expr $e) => $this->compile($e), $call->args);
        $float = $this->dialect->castType('float');

        return match ($fn) {
            '+', '-', '*' => Fragment::format("({} {$fn} {})", [$a(0), $a(1)]),
            '/' => Fragment::format("(CAST({} AS {$float}) / NULLIF({}, 0))", [$a(0), $a(1)]),
            '^' => Fragment::format("POWER(CAST({} AS {$float}), {})", [$a(0), $a(1)]),
            '%%' => Fragment::format("({} - {} * FLOOR(CAST({} AS {$float}) / NULLIF({}, 0)))", [$a(0), $a(1), $a(0), $a(1)]),
            'neg' => Fragment::format('(-{})', [$a(0)]),
            '==' => Fragment::format('({} = {})', [$a(0), $a(1)]),
            '!=' => Fragment::format('({} <> {})', [$a(0), $a(1)]),
            '<', '<=', '>', '>=' => Fragment::format("({} {$fn} {})", [$a(0), $a(1)]),
            '&' => Fragment::format('({} AND {})', [$a(0), $a(1)]),
            '|' => Fragment::format('({} OR {})', [$a(0), $a(1)]),
            '!' => Fragment::format('(NOT {})', [$a(0)]),
            'is_na' => Fragment::format('({} IS NULL)', [$a(0)]),
            'if_else' => Fragment::format('(CASE WHEN {} THEN {} WHEN NOT {} THEN {} ELSE {} END)', [
                $a(0), $a(1), $a(0), $a(2), $call->option('missing') ? $this->compile($call->option('missing')) : Fragment::raw('NULL'),
            ]),
            'case_when' => $this->caseWhen($call),
            'coalesce' => Fragment::format('COALESCE('.implode(', ', array_fill(0, count($call->args), '{}')).')', $args()),
            'in' => $this->in($call),
            'between' => Fragment::format('({} BETWEEN {} AND {})', [$a(0), $a(1), $a(2)]),
            'na_if' => Fragment::format('NULLIF({}, {})', [$a(0), $a(1)]),
            'near' => Fragment::format('(ABS({} - {}) < {})', [$a(0), $a(1), $call->option('tol') ? $this->compile($call->option('tol')) : Fragment::param(sqrt(PHP_FLOAT_EPSILON))]),
            'abs' => Fragment::format('ABS({})', [$a(0)]),
            'sqrt' => Fragment::format("SQRT(CAST({} AS {$float}))", [$a(0)]),
            'exp' => Fragment::format("EXP(CAST({} AS {$float}))", [$a(0)]),
            'log' => $call->option('base')
                ? Fragment::format("(LN(CAST({} AS {$float})) / LN(CAST({} AS {$float})))", [$a(0), $this->compile($call->option('base'))])
                : Fragment::format("LN(CAST({} AS {$float}))", [$a(0)]),
            'log10' => Fragment::format("(LN(CAST({} AS {$float})) / LN(10))", [$a(0)]),
            'floor' => Fragment::format("FLOOR(CAST({} AS {$float}))", [$a(0)]),
            'ceiling' => Fragment::format("CEIL(CAST({} AS {$float}))", [$a(0)]),
            'round' => throw new Untranslatable('round() rounds half to even in R; SQL ROUND does not.'),
            'as_int' => Fragment::format('CAST({} AS '.$this->dialect->castType('int').')', [$a(0)]),
            'as_float' => Fragment::format("CAST({} AS {$float})", [$a(0)]),
            'as_string' => throw new Untranslatable('as_string() formats numbers differently in SQL.'),
            'as_bool' => throw new Untranslatable('as_bool() has no portable SQL translation.'),
            'str_lower' => Fragment::format('LOWER({})', [$a(0)]),
            'str_upper' => Fragment::format('UPPER({})', [$a(0)]),
            'str_length' => $this->dialect->length($a(0)),
            'str_trim' => $this->trim($call),
            'str_sub' => $this->substring($call),
            'str_detect' => $this->negate($call, Fragment::format('({} > 0)', [$this->dialect->position($a(0), $this->literalPattern($call->args[1]))])),
            'str_starts' => $this->negate($call, Fragment::format('(SUBSTR({}, 1, {}) = {})', [
                $a(0), $this->dialect->length($this->literalPattern($call->args[1])), $this->literalPattern($call->args[1]),
            ])),
            'str_ends' => $this->negate($call, Fragment::format('({} >= {} AND SUBSTR({}, {} - {} + 1) = {})', [
                $this->dialect->length($a(0)), $this->dialect->length($this->literalPattern($call->args[1])),
                $a(0), $this->dialect->length($a(0)), $this->dialect->length($this->literalPattern($call->args[1])), $this->literalPattern($call->args[1]),
            ])),
            'str_replace' => $this->replaceFirst($a(0), $this->literalPattern($call->args[1]), $this->replacement($call->args[2])),
            'str_replace_all' => Fragment::format('REPLACE({}, {}, {})', [$a(0), $this->literalPattern($call->args[1]), $this->replacement($call->args[2])]),
            'paste0' => $this->paste($call->args, null),
            'paste' => $this->paste($call->args, $call->option('sep') ?? new Lit(' ')),
        };
    }

    /**
     * Aggregates. In summary mode they are plain aggregate calls; in row
     * mode every inner aggregate call gets OVER (PARTITION BY groups), and
     * the NA guards wrap those window calls.
     */
    private function aggregate(Call $call): Fragment
    {
        $fn = $call->fn;
        $rowMode = $this->mode !== 'summary';
        // Wrap one aggregate call: plain in summarise, a window in mutate/filter.
        $w = fn (Fragment $agg) => $rowMode ? $this->over($agg, withOrder: false) : $agg;
        if ($fn === 'n') {
            return $w(Fragment::raw('COUNT(*)'));
        }
        $naRm = $call->option('na_rm') instanceof Lit && $call->option('na_rm')->value === true;
        if ($call->option('na_rm') !== null && ! $call->option('na_rm') instanceof Lit) {
            throw new Untranslatable('na_rm must be a literal in SQL.');
        }
        $x = $this->compile($call->args[0]);
        $float = $this->dialect->castType('float');
        $countAll = $w(Fragment::raw('COUNT(*)'));
        $countX = $w(Fragment::format('COUNT({})', [$x]));
        // R returns NA when any input is NA unless na_rm.
        $guard = fn (Fragment $agg) => $naRm
            ? $agg
            : Fragment::format('(CASE WHEN {} > {} THEN NULL ELSE {} END)', [$countAll, $countX, $agg]);

        return match ($fn) {
            'sum' => $guard(Fragment::format('COALESCE({}, 0)', [$w(Fragment::format('SUM({})', [$x]))])),
            'mean' => $guard($w(Fragment::format("AVG(CAST({} AS {$float}))", [$x]))),
            'min' => $guard($w(Fragment::format('MIN({})', [$x]))),
            'max' => $guard($w(Fragment::format('MAX({})', [$x]))),
            'sd', 'var' => $guard($this->dialect->sampleStat($fn, Fragment::format("CAST({} AS {$float})", [$x]), $w)),
            'median' => $rowMode
                ? throw new Untranslatable('median() is not available as a window function in SQL.')
                : $guard($this->dialect->quantile($x, 0.5)),
            'quantile' => $rowMode
                ? throw new Untranslatable('quantile() is not available as a window function in SQL.')
                : $this->quantile($call, $x, $naRm),
            'iqr' => $rowMode || ! $naRm
                ? throw new Untranslatable('iqr() is translated only in summarise with na_rm.')
                : Fragment::format('({} - {})', [$this->dialect->quantile($x, 0.75), $this->dialect->quantile($x, 0.25)]),
            'n_distinct' => match (true) {
                count($call->args) > 1 => throw new Untranslatable('n_distinct() of several columns is not translated.'),
                $rowMode => throw new Untranslatable('COUNT(DISTINCT) is not available as a window function in SQL.'),
                $naRm => Fragment::format('COUNT(DISTINCT {})', [$x]),
                default => Fragment::format('(COUNT(DISTINCT {}) + CASE WHEN {} > {} THEN 1 ELSE 0 END)', [$x, $countAll, $countX]),
            },
            'any' => Fragment::format(
                '(CASE WHEN {} > 0 THEN TRUE'.($naRm ? '' : ' WHEN {} > {} THEN NULL').' ELSE FALSE END)',
                $naRm
                    ? [$w(Fragment::format('SUM(CASE WHEN {} THEN 1 ELSE 0 END)', [$x]))]
                    : [$w(Fragment::format('SUM(CASE WHEN {} THEN 1 ELSE 0 END)', [$x])), $countAll, $countX],
            ),
            'all' => Fragment::format(
                '(CASE WHEN {} > 0 THEN FALSE'.($naRm ? '' : ' WHEN {} > {} THEN NULL').' ELSE TRUE END)',
                $naRm
                    ? [$w(Fragment::format('SUM(CASE WHEN NOT {} THEN 1 ELSE 0 END)', [$x]))]
                    : [$w(Fragment::format('SUM(CASE WHEN NOT {} THEN 1 ELSE 0 END)', [$x])), $countAll, $countX],
            ),
            'first', 'last', 'nth' => $rowMode
                ? $this->nthValue($call, $x)
                : throw new Untranslatable("{$fn}() in summarise is computed as a window first (see SqlTable::summarise())."),
        };
    }

    /**
     * first(), last() and nth() as window functions over the whole group,
     * in the table's order or order_by. nth() with a negative n counts
     * from the end, using the reversed order.
     */
    private function nthValue(Call $call, Fragment $x): Fragment
    {
        if ($call->option('default') !== null || $call->option('na_rm') !== null) {
            throw new Untranslatable("{$call->fn}() with default or na_rm is not translated.");
        }
        $n = match ($call->fn) {
            'first' => 1,
            'last' => -1,
            'nth' => $call->args[1] instanceof Lit && is_int($call->args[1]->value) ? $call->args[1]->value : 0,
        };
        if ($n === 0) {
            throw new Untranslatable('nth() needs a literal, non-zero integer n.');
        }
        $whole = ' ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING';
        $fn = abs($n) === 1 ? Fragment::format('FIRST_VALUE({})', [$x]) : Fragment::format('NTH_VALUE({}, '.abs($n).')', [$x]);

        return $this->over($fn, withOrder: true, orderBy: $call->option('order_by'), frame: $whole, reverse: $n < 0);
    }

    private function quantile(Call $call, Fragment $x, bool $naRm): Fragment
    {
        if (! $naRm) {
            throw new Untranslatable('quantile() without na_rm errors on NA in R; not translated.');
        }
        $p = $call->args[1];
        if (! $p instanceof Lit || ! is_numeric($p->value)) {
            throw new Untranslatable('quantile() needs a literal probability.');
        }

        return $this->dialect->quantile($x, (float) $p->value);
    }

    private function window(Call $call): Fragment
    {
        $fn = $call->fn;

        if ($fn === 'lag' || $fn === 'lead') {
            $n = $call->option('n');
            $default = $call->option('default');
            $parts = [$this->compile($call->args[0]), $n ? $this->compile($n) : Fragment::raw('1')];
            if ($default !== null) {
                $parts[] = $this->compile($default);
            }
            $sql = Fragment::format(strtoupper($fn).'('.implode(', ', array_fill(0, count($parts), '{}')).')', $parts);

            return $this->over($sql, withOrder: true, orderBy: $call->option('order_by'));
        }

        if (in_array($fn, Functions::RANKING, true)) {
            return $this->rank($call);
        }

        $x = $this->compile($call->args[0]);
        $frame = ' ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW';
        $win = fn (Fragment $f) => $this->over($f, withOrder: true, frame: $frame);
        // R's cumulative functions stay NA after the first NA.
        $guard = fn (Fragment $value) => Fragment::format('(CASE WHEN {} > {} THEN NULL ELSE {} END)', [
            $win(Fragment::raw('COUNT(*)')),
            $win(Fragment::format('COUNT({})', [$x])),
            $value,
        ]);

        return match ($fn) {
            'cumsum' => $guard($win(Fragment::format('SUM({})', [$x]))),
            'cummean' => $guard($win(Fragment::format('AVG(CAST({} AS '.$this->dialect->castType('float').'))', [$x]))),
            'cummin' => $guard($win(Fragment::format('MIN({})', [$x]))),
            'cummax' => $guard($win(Fragment::format('MAX({})', [$x]))),
            'cumall' => Fragment::format('(CASE WHEN {} > 0 THEN FALSE WHEN {} > {} THEN NULL ELSE TRUE END)', [
                $win(Fragment::format('SUM(CASE WHEN NOT {} THEN 1 ELSE 0 END)', [$x])),
                $win(Fragment::raw('COUNT(*)')),
                $win(Fragment::format('COUNT({})', [$x])),
            ]),
            'cumany' => Fragment::format('(CASE WHEN {} > 0 THEN TRUE WHEN {} > {} THEN NULL ELSE FALSE END)', [
                $win(Fragment::format('SUM(CASE WHEN {} THEN 1 ELSE 0 END)', [$x])),
                $win(Fragment::raw('COUNT(*)')),
                $win(Fragment::format('COUNT({})', [$x])),
            ]),
            'cumprod' => throw new Untranslatable('cumprod() has no SQL window aggregate.'),
            'consecutive_id' => throw new Untranslatable('consecutive_id() is not translated.'),
        };
    }

    /** Ranks over non-NA values only; NA rows stay NA. */
    private function rank(Call $call): Fragment
    {
        $fn = $call->fn;
        $sqlFn = match ($fn) {
            'row_number' => 'ROW_NUMBER()',
            'min_rank' => 'RANK()',
            'dense_rank' => 'DENSE_RANK()',
            'percent_rank' => 'PERCENT_RANK()',
            'cume_dist' => 'CUME_DIST()',
            'ntile' => 'NTILE('.$this->integerOption($call, 'n').')',
        };

        if ($call->args === []) {
            return $this->over(Fragment::raw($sqlFn), withOrder: true);
        }
        $arg = $call->args[0];
        $desc = $arg instanceof Desc;
        $x = $this->compile($desc ? $arg->expr : $arg);
        $partition = $this->partition(Fragment::format('({} IS NULL)', [$x]));
        $orderBy = Fragment::format('{}'.($desc ? ' DESC' : ''), [$x]);
        if ($fn === 'row_number' && $this->order !== []) {
            // dplyr breaks ties by row position; the table's order is the closest SQL has.
            $orderBy = Fragment::format('{}, {}', [$orderBy, $this->orderBy($this->order)]);
        }
        $over = Fragment::format('{} OVER ({} ORDER BY {})', [Fragment::raw($sqlFn), $partition, $orderBy]);

        if ($fn === 'percent_rank') {
            // R gives NaN (an NA) when there is a single value; SQL gives 0.
            $count = Fragment::format('COUNT({}) OVER ('.($partition->sql === '' ? '' : '{}').')', $partition->sql === '' ? [$x] : [$x, $partition]);
            $over = Fragment::format('(CASE WHEN {} = 1 THEN NULL ELSE {} END)', [$count, $over]);
        }

        return Fragment::format('(CASE WHEN {} IS NULL THEN NULL ELSE {} END)', [$x, $over]);
    }

    private function integerOption(Call $call, string $name): int
    {
        $value = $call->option($name);
        if (! $value instanceof Lit || ! is_numeric($value->value)) {
            throw new Untranslatable("{$call->fn}() needs a literal {$name}.");
        }

        return (int) floor((float) $value->value);
    }

    /**
     * Wrap a function in OVER (PARTITION BY groups [ORDER BY ...]).
     */
    private function over(Fragment $fn, bool $withOrder, ?Expr $orderBy = null, string $frame = '', bool $reverse = false): Fragment
    {
        $partition = $this->partition();
        $order = null;
        if ($withOrder) {
            $terms = $orderBy !== null
                ? [[$orderBy instanceof Desc ? $orderBy->expr : $orderBy, $orderBy instanceof Desc]]
                : $this->order;
            if ($terms === []) {
                throw new Untranslatable('Window functions need a row order in SQL: arrange() first or pass order_by.');
            }
            $order = $this->orderBy($terms, $reverse);
        }

        $parts = [$fn];
        $template = '{} OVER (';
        if ($partition->sql !== '') {
            $template .= '{}';
            $parts[] = $partition;
        }
        if ($order !== null) {
            $template .= ($partition->sql !== '' ? ' ' : '').'ORDER BY {}'.$frame;
            $parts[] = $order;
        }

        return Fragment::format($template.')', $parts);
    }

    private function partition(?Fragment $extra = null): Fragment
    {
        $terms = array_map(fn (string $g) => Fragment::raw($this->dialect->quote($g)), $this->groups);
        if ($extra !== null) {
            $terms[] = $extra;
        }

        return $terms === [] ? Fragment::raw('') : Fragment::format('PARTITION BY {}', [Fragment::join(', ', $terms)]);
    }

    private function caseWhen(Call $call): Fragment
    {
        $parts = [];
        $template = '(CASE';
        for ($i = 0; $i < count($call->args); $i += 2) {
            $template .= ' WHEN {} THEN {}';
            $parts[] = $this->compile($call->args[$i]);
            $parts[] = $this->compile($call->args[$i + 1]);
        }
        $template .= ' ELSE {} END)';
        $parts[] = $call->option('default') ? $this->compile($call->option('default')) : Fragment::raw('NULL');

        return Fragment::format($template, $parts);
    }

    /** R's %in%: NA is in the set only if NA is listed; the result is never NA. */
    private function in(Call $call): Fragment
    {
        $set = $call->args[1];
        if (! $set instanceof Lit || ! is_array($set->value)) {
            throw new Untranslatable('in() needs a literal list.');
        }
        $x = $this->compile($call->args[0]);
        $values = array_values(array_filter($set->value, fn ($v) => $v !== null));
        $hasNa = count($values) !== count($set->value);
        $member = $values === []
            ? Fragment::raw('FALSE')
            : Fragment::format('COALESCE({} IN ({}), FALSE)', [$x, $this->literal(new Lit($values))]);

        return $hasNa ? Fragment::format('({} OR {} IS NULL)', [$member, $x]) : $member;
    }

    private function trim(Call $call): Fragment
    {
        $side = $call->option('side');
        $side = $side instanceof Lit ? $side->value : 'both';

        return Fragment::format(match ($side) {
            'left' => 'LTRIM({})',
            'right' => 'RTRIM({})',
            default => 'TRIM({})',
        }, [$this->compile($call->args[0])]);
    }

    /** stringr's str_sub() for literal positions. */
    private function substring(Call $call): Fragment
    {
        $start = $call->args[1];
        $end = $call->args[2] ?? new Lit(-1);
        if (! $start instanceof Lit || ! $end instanceof Lit || ! is_int($start->value) || ! is_int($end->value)) {
            throw new Untranslatable('str_sub() needs literal integer positions in SQL.');
        }
        $x = $this->compile($call->args[0]);
        [$s, $e] = [$start->value, $end->value];
        if ($s >= 1 && $e === -1) {
            return Fragment::format("SUBSTR({}, {$s})", [$x]);
        }
        if ($s >= 1 && $e >= $s) {
            return Fragment::format("SUBSTR({}, {$s}, ".($e - $s + 1).')', [$x]);
        }
        throw new Untranslatable('str_sub() with negative or crossing positions is not translated.');
    }

    /**
     * A pattern SQL can match exactly: fixed('...'), or a plain string with
     * no regular-expression metacharacters (then regex and literal agree).
     */
    private function literalPattern(Expr $pattern): Fragment
    {
        if ($pattern instanceof Call && $pattern->fn === 'fixed' && $pattern->args[0] instanceof Lit && is_string($pattern->args[0]->value)) {
            return $this->compile($pattern->args[0]);
        }
        if ($pattern instanceof Lit && is_string($pattern->value) && strpbrk($pattern->value, '.^$*+?()[]{}|\\') === false) {
            return $this->compile($pattern);
        }
        throw new Untranslatable('Regular-expression patterns are evaluated in memory; use fixed() for literal matching in SQL.');
    }

    /** A replacement without group references (\1), which SQL REPLACE can't expand. */
    private function replacement(Expr $replacement): Fragment
    {
        if ($replacement instanceof Lit && is_string($replacement->value) && ! str_contains($replacement->value, '\\')) {
            return $this->compile($replacement);
        }
        throw new Untranslatable('Replacements with group references or non-literal values are evaluated in memory.');
    }

    /** str_replace(): the first occurrence only, with fixed patterns. */
    private function replaceFirst(Fragment $x, Fragment $pattern, Fragment $replacement): Fragment
    {
        $pos = $this->dialect->position($x, $pattern);
        $before = Fragment::format('SUBSTR({}, 1, {} - 1)', [$x, $pos]);
        $after = Fragment::format('SUBSTR({}, {} + {})', [$x, $pos, $this->dialect->length($pattern)]);

        return Fragment::format('(CASE WHEN {} = 0 THEN {} ELSE {} END)', [
            $pos, $x, $this->dialect->concat([$before, $replacement, $after]),
        ]);
    }

    private function negate(Call $call, Fragment $test): Fragment
    {
        $negate = $call->option('negate');

        return $negate instanceof Lit && $negate->value === true ? Fragment::format('(NOT {})', [$test]) : $test;
    }

    /**
     * paste0()/paste(): NA becomes "NA", numbers become text.
     *
     * @param list<Expr> $args
     */
    private function paste(array $args, ?Expr $sep): Fragment
    {
        foreach ($args as $arg) {
            if (! ($arg instanceof Lit && is_string($arg->value))) {
                // SQL formats numbers differently from R (3.0 vs 3), and column types are unknown here.
                throw new Untranslatable('paste() of non-literal values is evaluated in memory to match R formatting.');
            }
        }
        $parts = [];
        foreach ($args as $i => $arg) {
            if ($i > 0 && $sep !== null) {
                $parts[] = $this->compile($sep);
            }
            $parts[] = $this->compile($arg);
        }

        return count($parts) === 1 ? $parts[0] : $this->dialect->concat($parts);
    }
}
