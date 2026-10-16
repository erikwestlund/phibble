<?php

declare(strict_types=1);

namespace Phibble;

use DateTimeInterface;
use InvalidArgumentException;
use Phibble\Column\Column;
use Phibble\Column\FactorColumn;
use Phibble\Column\Infer;

/**
 * dplyr 1.1 joins for in-memory frames.
 *
 * @internal Use Frame::join().
 */
final class Join
{
    private const array TYPES = ['inner', 'left', 'right', 'full', 'semi', 'anti', 'cross'];

    /**
     * @param list<string|array{0: string, 1: string}>|array<string, string> $by
     * @param array{0: string, 1: string} $suffix
     */
    public static function run(
        Frame $x,
        Frame $y,
        array $by,
        string $type = 'inner',
        array $suffix = ['.x', '.y'],
        ?bool $keep = null,
        bool $naMatches = true,
        string $multiple = 'all',
        ?string $relationship = null,
        string $unmatched = 'drop',
    ): Frame {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown join type '{$type}'. Use one of: ".implode(', ', self::TYPES).'.');
        }
        if (! in_array($multiple, ['all', 'any', 'first', 'last'], true)) {
            throw new InvalidArgumentException("multiple must be all, any, first or last, got '{$multiple}'.");
        }
        if (! in_array($relationship, [null, 'one-to-one', 'one-to-many', 'many-to-one', 'many-to-many'], true)) {
            throw new InvalidArgumentException("Unknown relationship '{$relationship}'.");
        }
        if (! in_array($unmatched, ['drop', 'error'], true)) {
            throw new InvalidArgumentException("unmatched must be drop or error, got '{$unmatched}'.");
        }

        [$pairs, $conditions, $closest] = self::conditions($by);
        if ($type === 'cross' && ($pairs !== [] || $conditions !== [] || $closest !== null)) {
            throw new InvalidArgumentException('A cross join takes no keys.');
        }
        if ($type !== 'cross' && $pairs === [] && $conditions === [] && $closest === null) {
            throw new InvalidArgumentException('Join keys are required; pass by.');
        }
        $nonEquality = $conditions !== [] || $closest !== null;
        if ($keep === false && $nonEquality) {
            throw new InvalidArgumentException("Can't set `keep = FALSE` when using an inequality, rolling, or overlap join.");
        }
        foreach ([...$pairs, ...array_map(fn ($c) => [$c[0], $c[2]], $conditions), ...($closest ? [[$closest[0], $closest[2]]] : [])] as [$left, $right]) {
            $x->storage($left);
            $y->storage($right);
        }

        $xKeys = self::keys($x, array_column($pairs, 0), $naMatches);
        $yKeys = self::keys($y, array_column($pairs, 1), $naMatches);

        $index = [];
        foreach ($yKeys as $j => $key) {
            if ($key !== null) {
                $index[$key][] = $j;
            }
        }
        $allY = $y->nrow() === 0 ? [] : range(0, $y->nrow() - 1);

        // Inequality and closest conditions, as column values.
        $checks = array_map(fn (array $c) => [$x->column($c[0]), $c[1], $y->column($c[2])], $conditions);
        $roll = $closest === null ? null : [$x->column($closest[0]), $closest[1], $y->column($closest[2])];

        // Matches per x row, in y order.
        $matches = [];
        foreach ($xKeys as $i => $key) {
            $found = match (true) {
                $type === 'cross' || $pairs === [] => $allY,
                $key === null => [],
                default => $index[$key] ?? [],
            };
            if ($checks !== [] || $roll !== null) {
                $found = self::filterConditions($i, $found, $checks, $roll, $naMatches);
            }
            $matches[$i] = match ($multiple) {
                'all' => $found,
                'any', 'first' => array_slice($found, 0, 1),
                'last' => array_slice($found, -1),
            };
        }
        $matchedY = [];
        foreach ($matches as $i => $found) {
            foreach ($found as $j) {
                $matchedY[$j][] = $i;
            }
        }

        if ($type !== 'cross') {
            self::checkRelationship($relationship, $matches, $matchedY, $type, $nonEquality);
            self::checkUnmatched($unmatched, $type, $matches, $matchedY, $y->nrow());
        }

        if ($type === 'semi' || $type === 'anti') {
            $rows = array_keys(array_filter($matches, fn (array $m) => ($m !== []) === ($type === 'semi')));

            return $x->take($rows);
        }

        $xRows = [];
        $yRows = [];
        foreach ($matches as $i => $found) {
            foreach ($found as $j) {
                $xRows[] = $i;
                $yRows[] = $j;
            }
            if ($found === [] && in_array($type, ['left', 'full'], true)) {
                $xRows[] = $i;
                $yRows[] = null;
            }
        }
        if (in_array($type, ['right', 'full'], true)) {
            for ($j = 0; $j < $y->nrow(); $j++) {
                if (! isset($matchedY[$j])) {
                    $xRows[] = null;
                    $yRows[] = $j;
                }
            }
        }

        return self::assemble($x, $y, $pairs, $xRows, $yRows, $suffix, $keep === true);
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs
     * @param list<?int> $xRows
     * @param list<?int> $yRows
     * @param array{0: string, 1: string} $suffix
     */
    private static function assemble(Frame $x, Frame $y, array $pairs, array $xRows, array $yRows, array $suffix, bool $keep): Frame
    {
        $xKeyNames = array_column($pairs, 0);
        $yKeyNames = array_column($pairs, 1);
        $yOut = $keep ? $y->columns() : array_values(array_diff($y->columns(), $yKeyNames));
        $xNames = $x->columns();

        $columns = [];
        foreach ($xNames as $name) {
            $isKey = in_array($name, $xKeyNames, true);
            $column = $x->storage($name)->take($xRows);
            if ($isKey && ! $keep) {
                // A merged key takes the common type of both keys (vctrs), filled
                // from y where the row came from y only.
                $right = $yKeyNames[array_search($name, $xKeyNames, true)];
                $column = self::keyColumn($x->storage($name), $y->storage($right), $column, $y->storage($right)->take($yRows), $xRows, $name);
            }
            $outName = ($keep || ! $isKey) && in_array($name, $yOut, true) ? $name.$suffix[0] : $name;
            $columns[$outName] = $column;
        }
        foreach ($yOut as $name) {
            $outName = in_array($name, $xNames, true) ? $name.$suffix[1] : $name;
            if (isset($columns[$outName])) {
                throw new InvalidArgumentException("Join would create a duplicate column '{$outName}'; choose other suffixes.");
            }
            $columns[$outName] = $y->storage($name)->take($yRows);
        }

        $out = Frame::fromColumns($columns);
        $groups = array_values(array_filter($x->groups(), fn (string $g) => isset($columns[$g])));

        return $groups === [] ? $out : $out->groupBy($groups, $x->dropsEmptyGroups());
    }

    /**
     * The merged key column: x's key values, filled from y's where the row
     * came from y only, in the common type of both keys as vctrs gives it:
     * factor and text give text, int and double give double, factors with
     * different levels give the union of levels.
     *
     * @param list<?int> $xRows
     */
    private static function keyColumn(Column $xKey, Column $yKey, Column $left, Column $right, array $xRows, string $name): Column
    {
        $values = $left->values();
        if (in_array(null, $xRows, true)) {
            $fill = $right->values();
            foreach ($xRows as $k => $i) {
                if ($i === null) {
                    $values[$k] = $fill[$k];
                }
            }
        }

        [$a, $b] = [$xKey->type(), $yKey->type()];
        if ($xKey instanceof FactorColumn && $yKey instanceof FactorColumn) {
            $same = $xKey->levels() === $yKey->levels();
            $levels = array_values(array_unique([...$xKey->levels(), ...$yKey->levels()]));

            return FactorColumn::fromValues($values, $levels, $same && $xKey->isOrdered() && $yKey->isOrdered());
        }
        if ($a === Type::Factor || $b === Type::Factor) {
            return in_array(Type::String, [$a, $b], true)
                ? Infer::column(array_map(fn ($v) => $v === null ? null : (string) $v, $values), Type::String, $name)
                : ($a === Type::Factor && ! in_array(null, $xRows, true) ? $left : Infer::column($values, null, $name));
        }
        $numeric = [Type::Bool, Type::Int, Type::Float];
        if (in_array($a, $numeric, true) && in_array($b, $numeric, true)) {
            $type = in_array(Type::Float, [$a, $b], true) ? Type::Float : (in_array(Type::Int, [$a, $b], true) ? Type::Int : Type::Bool);

            return Infer::column($values, $type, $name);
        }
        if ($a === $b) {
            return in_array(null, $xRows, true) ? Infer::column($values, $a, $name) : $left;
        }

        return Infer::column($values, null, $name);
    }

    /**
     * Split $by into equality pairs, inequality conditions and at most one
     * closest (rolling) condition.
     *
     * - 'k' or [l, r] (or [l, '==', r]): equality
     * - [l, op, r] with op in >=, >, <=, <: inequality
     * - ['closest', l, op, r]: keep only the closest r per x row
     *
     * @return array{0: list<array{0: string, 1: string}>, 1: list<array{0: string, 1: string, 2: string}>, 2: ?array{0: string, 1: string, 2: string}}
     */
    /** @internal Shared with SqlJoin. */
    public static function conditions(array $by): array
    {
        $pairs = [];
        $conditions = [];
        $closest = null;
        foreach ($by as $left => $entry) {
            if (is_string($left)) {
                $pairs[] = [$left, (string) $entry];

                continue;
            }
            if (is_string($entry)) {
                $pairs[] = [$entry, $entry];

                continue;
            }
            if (! is_array($entry)) {
                throw new InvalidArgumentException('Each join condition must be a column name or an array.');
            }
            $entry = array_values($entry);
            if (count($entry) === 2) {
                $pairs[] = [(string) $entry[0], (string) $entry[1]];

                continue;
            }
            if (count($entry) === 4 && $entry[0] === 'closest') {
                if ($closest !== null) {
                    throw new InvalidArgumentException('A join can have only one closest() condition.');
                }
                if (! in_array($entry[2], ['>=', '>', '<=', '<'], true)) {
                    throw new InvalidArgumentException("closest() needs >=, >, <= or <, got '{$entry[2]}'.");
                }
                $closest = [(string) $entry[1], $entry[2], (string) $entry[3]];

                continue;
            }
            if (count($entry) === 3 && in_array($entry[1], ['==', '>=', '>', '<=', '<'], true)) {
                if ($entry[1] === '==') {
                    $pairs[] = [(string) $entry[0], (string) $entry[2]];
                } else {
                    $conditions[] = [(string) $entry[0], $entry[1], (string) $entry[2]];
                }

                continue;
            }
            throw new InvalidArgumentException('Unrecognised join condition: '.json_encode($entry).'.');
        }

        return [$pairs, $conditions, $closest];
    }

    /**
     * Keep the y rows that satisfy every inequality, then the closest ones.
     * With NA matching, NA equals NA: it satisfies ==, >= and <= but not
     * > or <. NA never matches a value.
     *
     * @param list<int> $candidates
     * @param list<array{0: list<mixed>, 1: string, 2: list<mixed>}> $checks
     * @param array{0: list<mixed>, 1: string, 2: list<mixed>}|null $roll
     * @return list<int>
     */
    private static function filterConditions(int $i, array $candidates, array $checks, ?array $roll, bool $naMatches): array
    {
        $holds = function (mixed $a, string $op, mixed $b) use ($naMatches): bool {
            if ($a === null || $b === null) {
                return $a === null && $b === null && $naMatches && in_array($op, ['>=', '<='], true);
            }
            $cmp = is_string($a) && is_string($b) ? strcmp($a, $b) <=> 0 : $a <=> $b;

            return match ($op) {
                '>=' => $cmp >= 0,
                '>' => $cmp > 0,
                '<=' => $cmp <= 0,
                '<' => $cmp < 0,
            };
        };

        $kept = [];
        foreach ($candidates as $j) {
            foreach ($checks as [$xs, $op, $ys]) {
                if (! $holds($xs[$i], $op, $ys[$j])) {
                    continue 2;
                }
            }
            if ($roll !== null && ! $holds($roll[0][$i], $roll[1], $roll[2][$j])) {
                continue;
            }
            $kept[] = $j;
        }
        if ($roll === null || $kept === []) {
            return $kept;
        }

        // Closest: the largest y value for >=/>, the smallest for <=/<. Ties keep all.
        $ys = $roll[2];
        $wantMax = in_array($roll[1], ['>=', '>'], true);
        $best = null;
        foreach ($kept as $j) {
            $v = $ys[$j];
            if ($best === null || ($v !== null && ($wantMax ? $v > $best : $v < $best))) {
                $best = $v;
            }
        }

        return array_values(array_filter($kept, fn (int $j) => $ys[$j] === $best || $ys[$j] == $best && $ys[$j] !== null));
    }

    /**
     * One match key per row; null when the row can never match.
     *
     * @param list<string> $names
     * @return list<?string>
     */
    private static function keys(Frame $frame, array $names, bool $naMatches): array
    {
        $columns = array_map(fn (string $n) => $frame->column($n), $names);
        $keys = [];
        for ($i = 0; $i < $frame->nrow(); $i++) {
            $parts = [];
            foreach ($columns as $values) {
                $value = $values[$i];
                if ($value === null && ! $naMatches) {
                    $parts = null;

                    break;
                }
                $parts[] = self::normalise($value);
            }
            // Length-prefixed parts, so different keys can't concatenate alike.
            $keys[] = $parts === null ? null : implode('', array_map(fn (string $p) => strlen($p).':'.$p, $parts));
        }

        return $keys;
    }

    /** 1 and 1.0 match; factors match strings by label. */
    private static function normalise(mixed $value): string
    {
        return match (true) {
            $value === null => 'NA',
            is_bool($value) => $value ? 'b:1' : 'b:0',
            is_int($value) => 'n:'.$value,
            // Whole floats match ints (1 == 1.0); others compare exactly, by their bits.
            is_float($value) => is_finite($value) && floor($value) === $value && abs($value) < 2 ** 53
                ? 'n:'.(int) $value
                : 'f:'.bin2hex(pack('E', is_nan($value) ? NAN : $value)),
            $value instanceof DateTimeInterface => 'd:'.$value->format('U.u'),
            is_string($value) => 's:'.$value,
            default => 'o:'.serialize($value),
        };
    }

    /**
     * @param array<int, list<int>> $matches
     * @param array<int, list<int>> $matchedY
     */
    private static function checkRelationship(?string $relationship, array $matches, array $matchedY, string $type, bool $nonEquality = false): void
    {
        $xMulti = null;
        foreach ($matches as $i => $found) {
            if (count($found) > 1) {
                $xMulti = $i;

                break;
            }
        }
        $yMulti = null;
        foreach ($matchedY as $j => $found) {
            if (count($found) > 1) {
                $yMulti = $j;

                break;
            }
        }

        if (in_array($relationship, ['one-to-one', 'many-to-one'], true) && $xMulti !== null) {
            throw new InvalidArgumentException('Each row in `x` must match at most 1 row in `y`. Row '.($xMulti + 1).' of `x` matches multiple rows in `y`.');
        }
        if (in_array($relationship, ['one-to-one', 'one-to-many'], true) && $yMulti !== null) {
            throw new InvalidArgumentException('Each row in `y` must match at most 1 row in `x`. Row '.($yMulti + 1).' of `y` matches multiple rows in `x`.');
        }
        // dplyr only warns for equality joins; inequality joins expect many matches.
        if ($relationship === null && ! $nonEquality && $xMulti !== null && $yMulti !== null && ! in_array($type, ['semi', 'anti'], true)) {
            trigger_error(
                "Detected an unexpected many-to-many relationship between `x` and `y`.\n"
                .'ℹ Row '.($xMulti + 1)." of `x` matches multiple rows in `y`.\n"
                .'ℹ Row '.($yMulti + 1)." of `y` matches multiple rows in `x`.\n"
                .'ℹ If a many-to-many relationship is expected, set `relationship = "many-to-many"` to silence this warning.',
                E_USER_WARNING,
            );
        }
    }

    /**
     * @param array<int, list<int>> $matches
     * @param array<int, list<int>> $matchedY
     */
    private static function checkUnmatched(string $unmatched, string $type, array $matches, array $matchedY, int $yRows): void
    {
        if ($unmatched !== 'error') {
            return;
        }
        if (in_array($type, ['inner', 'right'], true)) {
            foreach ($matches as $i => $found) {
                if ($found === []) {
                    throw new InvalidArgumentException('Each row of `x` must have a match in `y`. Row '.($i + 1).' of `x` does not have a match.');
                }
            }
        }
        if (in_array($type, ['inner', 'left'], true)) {
            for ($j = 0; $j < $yRows; $j++) {
                if (! isset($matchedY[$j])) {
                    throw new InvalidArgumentException('Each row of `y` must be matched by `x`. Row '.($j + 1).' of `y` was not matched.');
                }
            }
        }
    }
}
