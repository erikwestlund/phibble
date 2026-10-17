<?php

declare(strict_types=1);

namespace Phibble\Polars;

use InvalidArgumentException;
use Phibble\Exception\ColumnNotFound;
use Phpolars\DataFrame as PolarsDataFrame;

use function Phpolars\{all_horizontal, coalesce, col, concat, lit};

/**
 * dplyr 1.1 joins in Polars, with the same row order, column naming,
 * key coalescing, relationship checks and messages as Phibble\Join.
 *
 * Matching runs as one Polars join over row indices; the output is then
 * assembled by gathering x and y rows.
 *
 * @internal
 */
final class Joiner
{
    private const array TYPES = ['inner', 'left', 'right', 'full', 'semi', 'anti', 'cross'];

    /**
     * @param list<string|array{0: string, 1: string}>|array<string, string> $by
     * @param array{0: string, 1: string} $suffix
     * @return array{0: PolarsDataFrame, 1: array<string, array{levels: list<string>, ordered: bool}>}
     */
    public static function run(
        PolarsTable $x,
        PolarsTable $y,
        array $by,
        string $type,
        array $suffix,
        ?bool $keep,
        bool $naMatches,
        string $multiple,
        ?string $relationship,
        string $unmatched,
    ): array {
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
        [$pairs, $conditions] = self::conditions($by);
        if ($type === 'cross' && ($pairs !== [] || $conditions !== [])) {
            throw new InvalidArgumentException('A cross join takes no keys.');
        }
        if ($type !== 'cross' && $pairs === [] && $conditions === []) {
            throw new InvalidArgumentException('Join keys are required; pass by.');
        }
        if ($conditions !== [] && $keep === false) {
            throw new InvalidArgumentException("Can't set `keep = FALSE` when using an inequality, rolling, or overlap join.");
        }
        $keep ??= false;
        $xdf = $x->polars();
        $ydf = $y->polars();
        foreach ([...$pairs, ...array_map(fn ($c) => [$c['left'], $c['right']], $conditions)] as [$left, $right]) {
            if (! in_array($left, $xdf->columns(), true)) {
                throw ColumnNotFound::named($left, $xdf->columns());
            }
            if (! in_array($right, $ydf->columns(), true)) {
                throw ColumnNotFound::named($right, $ydf->columns());
            }
        }
        $X = $xdf->withRowIndex('__xi');
        $Y = $ydf->withRowIndex('__yi');

        $matches = match (true) {
            $type === 'cross' => $X->select('__xi')->join($Y->select('__yi'), how: 'cross', maintainOrder: 'left_right'),
            $conditions !== [] => self::conditionMatches($X, $Y, $pairs, $conditions, $naMatches),
            default => self::matches($X, $Y, $pairs, $naMatches),
        };
        $matches = match ($multiple) {
            'all' => $matches,
            'any', 'first' => $matches->unique(subset: '__xi', keep: 'first', maintainOrder: true),
            'last' => $matches->unique(subset: '__xi', keep: 'last', maintainOrder: true),
        };
        if ($type !== 'cross') {
            self::checkRelationship($relationship, $matches, $conditions === [] ? $type : 'semi');
            self::checkUnmatched($unmatched, $type, $matches, $X, $Y);
        }
        if ($type === 'semi' || $type === 'anti') {
            $rows = $X->join($matches->select('__xi')->unique(), on: '__xi', how: $type, maintainOrder: 'left');

            return [$rows->drop('__xi'), $x->factorLevels()];
        }

        $rows = $matches;
        if ($type === 'left' || $type === 'full') {
            $lonely = $X->select('__xi')->join($matches, on: '__xi', how: 'anti')->withColumns(lit(null)->cast('UInt32')->alias('__yi'));
            $rows = concat([$rows, $lonely])->sort('__xi', maintainOrder: true);
        }
        if ($type === 'right' || $type === 'full') {
            $lonely = $Y->select('__yi')->join($matches, on: '__yi', how: 'anti')->select(lit(null)->cast('UInt32')->alias('__xi'), '__yi');
            $rows = concat([$rows, $lonely]);
        }

        return self::assemble($x, $y, $X, $Y, $rows, $pairs, $suffix, $keep);
    }

    /**
     * Pairs for joins with comparisons: equality keys first (or every pair),
     * then the comparisons, then, for a rolling join, the closest y value per
     * x row with ties kept. Under naMatches, NA vs NA satisfies ==, >= and <=.
     *
     * @param list<array{0: string, 1: string}> $pairs
     * @param list<array{left: string, op: string, right: string, closest: bool}> $conditions
     */
    private static function conditionMatches(PolarsDataFrame $X, PolarsDataFrame $Y, array $pairs, array $conditions, bool $naMatches): PolarsDataFrame
    {
        $xs = $X->schema();
        $ys = $Y->schema();
        $kx = ['__xi' => col('__xi')];
        $ky = ['__yi' => col('__yi')];
        foreach ($pairs as $i => [$left, $right]) {
            $common = self::keyType($xs[$left], $ys[$right]);
            $kx["__k{$i}"] = $common === null ? col($left) : col($left)->cast($common);
            $ky["__k{$i}"] = $common === null ? col($right) : col($right)->cast($common);
        }
        foreach ($conditions as $i => $c) {
            $common = self::keyType($xs[$c['left']], $ys[$c['right']]);
            $kx["__cl{$i}"] = $common === null ? col($c['left']) : col($c['left'])->cast($common);
            $ky["__cr{$i}"] = $common === null ? col($c['right']) : col($c['right'])->cast($common);
        }
        $keys = array_map(fn ($i) => "__k{$i}", array_keys($pairs));
        $xk = $X->select($kx);
        $yk = $Y->select($ky);
        if ($keys === []) {
            // Rows with all comparison values present go through Polars' IEJoin;
            // rows with NA (which can match NA under naMatches) are paired by a
            // cross join and filtered below.
            $present = fn (string $prefix) => all_horizontal(...array_map(fn ($i) => col("{$prefix}{$i}")->isNotNull(), array_keys($conditions)));
            $xPresent = $xk->filter($present('__cl'));
            $yPresent = $yk->filter($present('__cr'));
            $ops = array_map(fn (array $c, int $i) => match ($c['op']) {
                '==' => col("__cl{$i}")->eq(col("__cr{$i}")),
                '>=' => col("__cl{$i}")->ge(col("__cr{$i}")),
                '>' => col("__cl{$i}")->gt(col("__cr{$i}")),
                '<=' => col("__cl{$i}")->le(col("__cr{$i}")),
                '<' => col("__cl{$i}")->lt(col("__cr{$i}")),
            }, $conditions, array_keys($conditions));
            $parts = [$xPresent->joinWhere($yPresent, ...$ops)];
            if ($naMatches) {
                $parts[] = $xk->filter($present('__cl')->not())->join($yk, how: 'cross');
                $parts[] = $xPresent->join($yk->filter($present('__cr')->not()), how: 'cross');
            }
            $candidates = concat($parts);
        } else {
            $candidates = $xk->join($yk, on: $keys, how: 'inner', nullsEqual: $naMatches);
        }

        $test = function (array $c, int $i) use ($naMatches) {
            $l = col("__cl{$i}");
            $r = col("__cr{$i}");
            $holds = match ($c['op']) {
                '==' => $l->eq($r),
                '>=' => $l->ge($r),
                '>' => $l->gt($r),
                '<=' => $l->le($r),
                '<' => $l->lt($r),
            };
            $holds = $holds->fillNull(false);
            if ($naMatches && in_array($c['op'], ['==', '>=', '<='], true)) {
                $holds = $holds->or($l->isNull()->and($r->isNull()));
            }

            return $holds;
        };
        foreach ($conditions as $i => $c) {
            $candidates = $candidates->filter($test($c, $i));
        }
        foreach ($conditions as $i => $c) {
            if ($c['closest']) {
                $r = col("__cr{$i}");
                $best = in_array($c['op'], ['>=', '>'], true) ? $r->max() : $r->min();
                $candidates = $candidates->filter($r->eqMissing($best->over('__xi')));
            }
        }

        return $candidates->select('__xi', '__yi')->sort(['__xi', '__yi'], maintainOrder: true);
    }

    /**
     * Matching (x row, y row) pairs in x order, then y order.
     *
     * @param list<array{0: string, 1: string}> $pairs
     */
    private static function matches(PolarsDataFrame $X, PolarsDataFrame $Y, array $pairs, bool $naMatches): PolarsDataFrame
    {
        $xs = $X->schema();
        $ys = $Y->schema();
        $kx = ['__xi' => col('__xi')];
        $ky = ['__yi' => col('__yi')];
        foreach ($pairs as $i => [$left, $right]) {
            // 1 matches 1.0: compare numeric keys as floats when the types differ.
            $common = self::keyType($xs[$left], $ys[$right]);
            $kx["__k{$i}"] = $common === null ? col($left) : col($left)->cast($common);
            $ky["__k{$i}"] = $common === null ? col($right) : col($right)->cast($common);
        }
        $keys = array_map(fn ($i) => "__k{$i}", array_keys($pairs));

        return $X->select($kx)->join($Y->select($ky), on: $keys, how: 'inner', nullsEqual: $naMatches)
            ->select('__xi', '__yi')
            ->sort(['__xi', '__yi'], maintainOrder: true);
    }

    private static function keyType(string $a, string $b): ?string
    {
        if ($a === $b) {
            return null;
        }
        $numeric = fn (string $t) => str_starts_with($t, 'Int') || str_starts_with($t, 'UInt') || str_starts_with($t, 'Float');
        if ($numeric($a) && $numeric($b)) {
            return 'Float64';
        }
        if ($a === 'Null') {
            return $b;
        }
        if ($b === 'Null') {
            return $a;
        }

        return 'String';
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs
     * @param array{0: string, 1: string} $suffix
     * @return array{0: PolarsDataFrame, 1: array<string, array{levels: list<string>, ordered: bool}>}
     */
    private static function assemble(PolarsTable $x, PolarsTable $y, PolarsDataFrame $X, PolarsDataFrame $Y, PolarsDataFrame $rows, array $pairs, array $suffix, bool $keep): array
    {
        $xKeys = array_column($pairs, 0);
        $yKeys = array_column($pairs, 1);
        $xNames = $x->columns();
        $yOut = $keep ? $y->columns() : array_values(array_diff($y->columns(), $yKeys));
        $xFactors = $x->factorLevels();
        $yFactors = $y->factorLevels();
        $factors = [];

        $xRename = [];
        foreach ($xNames as $name) {
            $isKey = in_array($name, $xKeys, true);
            $out = ($keep || ! $isKey) && in_array($name, $yOut, true) ? $name.$suffix[0] : $name;
            $xRename[$name] = $out;
            if (isset($xFactors[$name])) {
                $factors[$out] = $xFactors[$name];
            }
        }
        $yRename = [];
        foreach ($yOut as $name) {
            $out = in_array($name, $xNames, true) ? $name.$suffix[1] : $name;
            if (in_array($out, $xRename, true)) {
                throw new InvalidArgumentException("Join would create a duplicate column '{$out}'; choose other suffixes.");
            }
            $yRename[$name] = $out;
            if (isset($yFactors[$name])) {
                $factors[$out] = $yFactors[$name];
            }
        }

        $ySelect = ['__yi' => col('__yi')];
        foreach ($yRename as $name => $out) {
            $ySelect[$out] = col($name);
        }
        $coalesce = ! $keep && $pairs !== [];
        if ($coalesce) {
            foreach ($pairs as $i => [, $right]) {
                $ySelect["__yk{$i}"] = col($right);
            }
        }
        $xSelect = ['__xi' => col('__xi')];
        foreach ($xRename as $name => $out) {
            $xSelect[$out] = col($name);
        }

        $out = $rows
            ->join($X->select($xSelect), on: '__xi', how: 'left', maintainOrder: 'left')
            ->join($Y->select($ySelect), on: '__yi', how: 'left', maintainOrder: 'left');
        if ($coalesce) {
            $fill = [];
            foreach ($pairs as $i => [$left]) {
                $fill[$xRename[$left]] = coalesce(col($xRename[$left]), col("__yk{$i}"));
            }
            $out = $out->withColumns($fill);
        }

        return [$out->select([...array_values($xRename), ...array_values($yRename)]), $factors];
    }

    /**
     * Split by into equality pairs and comparisons: 'k', [l, r], l => r,
     * [l, op, r], or ['closest', l, op, r].
     *
     * @return array{0: list<array{0: string, 1: string}>, 1: list<array{left: string, op: string, right: string, closest: bool}>}
     */
    private static function conditions(array $by): array
    {
        $pairs = [];
        $conditions = [];
        foreach ($by as $left => $right) {
            if (is_array($right) && count($right) === 4 && $right[0] === 'closest') {
                $conditions[] = ['left' => (string) $right[1], 'op' => self::op($right[2]), 'right' => (string) $right[3], 'closest' => true];
            } elseif (is_array($right) && count($right) === 3) {
                $op = self::op($right[1]);
                if ($op === '==') {
                    $pairs[] = [(string) $right[0], (string) $right[2]];
                } else {
                    $conditions[] = ['left' => (string) $right[0], 'op' => $op, 'right' => (string) $right[2], 'closest' => false];
                }
            } elseif (is_array($right) && count($right) === 2) {
                $pairs[] = [(string) $right[0], (string) $right[1]];
            } elseif (is_array($right)) {
                throw new InvalidArgumentException('Join conditions are [left, right], [left, op, right] or [\'closest\', left, op, right].');
            } else {
                $pairs[] = is_string($left) ? [$left, (string) $right] : [(string) $right, (string) $right];
            }
        }
        if (count(array_filter($conditions, fn ($c) => $c['closest'])) > 1) {
            throw new InvalidArgumentException('A join takes at most one closest() condition.');
        }

        return [$pairs, $conditions];
    }

    private static function op(mixed $op): string
    {
        return in_array($op, ['==', '>=', '>', '<=', '<'], true)
            ? $op
            : throw new InvalidArgumentException("Unknown join comparison '{$op}'; use ==, >=, >, <= or <.");
    }

    private static function checkRelationship(?string $relationship, PolarsDataFrame $matches, string $type): void
    {
        $first = function (string $side) use ($matches): ?int {
            $multi = $matches->groupBy($side, maintainOrder: true)->len()->filter(col('len')->gt(1));

            return $multi->height() === 0 ? null : (int) $multi->item(0, $side);
        };
        $xMulti = $first('__xi');
        $yMulti = $first('__yi');
        if (in_array($relationship, ['one-to-one', 'many-to-one'], true) && $xMulti !== null) {
            throw new InvalidArgumentException('Each row in `x` must match at most 1 row in `y`. Row '.($xMulti + 1).' of `x` matches multiple rows in `y`.');
        }
        if (in_array($relationship, ['one-to-one', 'one-to-many'], true) && $yMulti !== null) {
            throw new InvalidArgumentException('Each row in `y` must match at most 1 row in `x`. Row '.($yMulti + 1).' of `y` matches multiple rows in `x`.');
        }
        if ($relationship === null && $xMulti !== null && $yMulti !== null && ! in_array($type, ['semi', 'anti'], true)) {
            trigger_error(
                "Detected an unexpected many-to-many relationship between `x` and `y`.\n"
                .'ℹ Row '.($xMulti + 1)." of `x` matches multiple rows in `y`.\n"
                .'ℹ Row '.($yMulti + 1)." of `y` matches multiple rows in `x`.\n"
                .'ℹ If a many-to-many relationship is expected, set `relationship = "many-to-many"` to silence this warning.',
                E_USER_WARNING,
            );
        }
    }

    private static function checkUnmatched(string $unmatched, string $type, PolarsDataFrame $matches, PolarsDataFrame $X, PolarsDataFrame $Y): void
    {
        if ($unmatched !== 'error') {
            return;
        }
        if (in_array($type, ['inner', 'right'], true)) {
            $missing = $X->select('__xi')->join($matches, on: '__xi', how: 'anti');
            if ($missing->height() > 0) {
                throw new InvalidArgumentException('Each row of `x` must have a match in `y`. Row '.($missing->select(col('__xi')->min())->item() + 1).' of `x` does not have a match.');
            }
        }
        if (in_array($type, ['inner', 'left'], true)) {
            $missing = $Y->select('__yi')->join($matches, on: '__yi', how: 'anti');
            if ($missing->height() > 0) {
                throw new InvalidArgumentException('Each row of `y` must be matched by `x`. Row '.($missing->select(col('__yi')->min())->item() + 1).' of `y` was not matched.');
            }
        }
    }
}
