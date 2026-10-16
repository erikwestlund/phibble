<?php

declare(strict_types=1);

namespace Phibble\Sql;

use InvalidArgumentException;
use Phibble\Join;

/**
 * Joins two SqlTables on the same connection in SQL, with the column
 * layout and NA matching of Frame::join(). Row order is unspecified, as
 * with any SQL join; arrange() afterwards if it matters.
 *
 * @internal
 */
final class SqlJoin
{
    /**
     * @param list<string|array{0: string, 1: string}>|array<string, string> $by
     * @param array{0: string, 1: string} $suffix
     */
    public static function build(SqlTable $x, SqlTable $y, array $by, string $type, array $suffix, ?bool $keep, bool $naMatches): SqlTable
    {
        if (! in_array($type, ['inner', 'left', 'right', 'full', 'semi', 'anti', 'cross'], true)) {
            throw new InvalidArgumentException("Unknown join type '{$type}'.");
        }
        [$pairs, $inequalities, $closest] = Join::conditions($by);
        $hasConditions = $pairs !== [] || $inequalities !== [] || $closest !== null;
        if (($type === 'cross') === $hasConditions) {
            throw new InvalidArgumentException($type === 'cross' ? 'A cross join takes no keys.' : 'Join keys are required; pass by.');
        }
        if ($keep === false && ($inequalities !== [] || $closest !== null)) {
            throw new InvalidArgumentException("Can't set `keep = FALSE` when using an inequality, rolling, or overlap join.");
        }
        if (in_array($type, ['semi', 'anti'], true) && $closest !== null) {
            // Whether any row matches does not depend on which match is closest.
            $inequalities[] = $closest;
            $closest = null;
        }
        $keep = $keep === true;

        // Fix each side's row order into its row column first.
        $x = $x->ordered();
        $y = $y->ordered();
        $dialect = $x->dialect();
        $q = $dialect->quote(...);
        // Row order of the result: x's row keys, then y's (renamed to stay
        // unique). Sorting happens once, when rows are needed in order.
        $xRowKeys = $x->rowKeys();
        $yRowKeys = [];
        foreach ($y->rowKeys() as $k) {
            $yRowKeys[$k] = SqlTable::ROW.'_'.(count($xRowKeys) + count($yRowKeys));
        }
        $jx = 'j'.$x->alias().'x';
        $jy = 'j'.$x->alias().'y';
        $all = [...$pairs, ...array_map(fn ($c) => [$c[0], $c[2]], $inequalities), ...($closest ? [[$closest[0], $closest[2]]] : [])];
        foreach ($all as [$l, $r]) {
            if (! in_array($l, $x->columns(), true) || ! in_array($r, $y->columns(), true)) {
                throw new InvalidArgumentException("Join key {$l} = {$r} not found.");
            }
        }

        // Text keys compare in byte order, case-sensitively, as R does.
        $side = function (string $alias, string $column, bool $text) use ($q, $dialect): Fragment {
            $sql = Fragment::raw("{$alias}.{$q($column)}");

            return $text ? $dialect->collate($sql) : $sql;
        };
        $isText = fn (string $l, string $r) => in_array($l, $x->text(), true) || in_array($r, $y->text(), true);
        $conditions = [];
        foreach ($pairs as [$l, $r]) {
            $left = $side($jx, $l, $isText($l, $r));
            $right = $side($jy, $r, $isText($l, $r));
            $conditions[] = $naMatches ? $dialect->nullSafeEquals($left, $right) : Fragment::format('({} = {})', [$left, $right]);
        }
        // With NA matching, NA equals NA: it satisfies >= and <= but not > or <.
        foreach ([...$inequalities, ...($closest ? [$closest] : [])] as [$l, $op, $r]) {
            $left = $side($jx, $l, $isText($l, $r));
            $right = $side($jy, $r, $isText($l, $r));
            $conditions[] = $naMatches && in_array($op, ['>=', '<='], true)
                ? Fragment::format("(({} {$op} {}) OR ({} IS NULL AND {} IS NULL))", [$left, $right, $left, $right])
                : Fragment::format("({} {$op} {})", [$left, $right]);
        }
        $on = $conditions === [] ? null : Fragment::join(' AND ', $conditions);

        if ($type === 'semi' || $type === 'anti') {
            $sql = Fragment::format(
                "SELECT {$jx}.* FROM ({}) AS {$jx} WHERE ".($type === 'anti' ? 'NOT ' : '')."EXISTS (SELECT 1 FROM ({}) AS {$jy} WHERE {})",
                [$x->fragment(), $y->fragment(), $on],
            );

            return $x->derive($sql, $x->columns(), $x->groups(), $x->hints(), $xRowKeys);
        }

        $xKeys = array_column($pairs, 0);
        $yKeys = array_column($pairs, 1);
        $yOut = $keep ? $y->columns() : array_values(array_diff($y->columns(), $yKeys));
        $items = [];
        $columns = [];
        $hints = [];
        $text = [];
        foreach ($x->columns() as $name) {
            $isKey = in_array($name, $xKeys, true);
            $out = ($keep || ! $isKey) && in_array($name, $yOut, true) ? $name.$suffix[0] : $name;
            $source = "{$jx}.{$q($name)}";
            if ($isKey && ! $keep && in_array($type, ['right', 'full'], true)) {
                $source = "COALESCE({$source}, {$jy}.{$q($yKeys[array_search($name, $xKeys, true)])})";
            }
            $items[] = "{$source} AS {$q($out)}";
            $columns[] = $out;
            if (isset($x->hints()[$name])) {
                $hints[$out] = $x->hints()[$name];
            }
            if (in_array($name, $x->text(), true)) {
                $text[] = $out;
            }
        }
        foreach ($yOut as $name) {
            $out = in_array($name, $x->columns(), true) ? $name.$suffix[1] : $name;
            if (in_array($out, $columns, true)) {
                throw new InvalidArgumentException("Join would create a duplicate column '{$out}'; choose other suffixes.");
            }
            $items[] = "{$jy}.{$q($name)} AS {$q($out)}";
            $columns[] = $out;
            if (isset($y->hints()[$name])) {
                $hints[$out] = $y->hints()[$name];
            }
            if (in_array($name, $y->text(), true)) {
                $text[] = $out;
            }
        }

        $joinSql = match ($type) {
            'inner' => 'INNER JOIN',
            'left' => 'LEFT JOIN',
            'right' => 'RIGHT JOIN',
            'full' => 'FULL JOIN',
            'cross' => 'CROSS JOIN',
        };
        // Row keys: x order, matches in y order, then y-only rows (x keys NULL sort last).
        foreach ($xRowKeys as $k) {
            $items[] = "{$jx}.{$q($k)} AS {$q($k)}";
        }
        foreach ($yRowKeys as $k => $as) {
            $items[] = "{$jy}.{$q($k)} AS {$q($as)}";
        }
        $rowKeys = [...$xRowKeys, ...array_values($yRowKeys)];
        $xFrom = $x->fragment();
        if ($closest !== null) {
            // Number x's rows so the closest match can be picked per x row.
            // Text compares in byte order when choosing the closest match.
            $items[] = $isText($closest[0], $closest[2])
                ? $dialect->collate(Fragment::raw("{$jy}.{$q($closest[2])}"))->sql." AS {$q('__phibble_r')}"
                : "{$jy}.{$q($closest[2])} AS {$q('__phibble_r')}";
        }
        $template = 'SELECT '.implode(', ', $items)." FROM ({}) AS {$jx} {$joinSql} ({}) AS {$jy}";
        $parts = [$xFrom, $y->fragment()];
        if ($on !== null) {
            $template .= ' ON {}';
            $parts[] = $on;
        }
        $sql = Fragment::format($template, $parts);

        if ($closest !== null) {
            // Keep the largest y value for >= and >, the smallest for <= and <; ties keep all.
            $direction = in_array($closest[1], ['>=', '>'], true) ? 'DESC' : 'ASC';
            $r = $q('__phibble_r');
            $ranked = Fragment::format(
                'SELECT *, RANK() OVER (PARTITION BY '.implode(', ', array_map($q, $xRowKeys))." ORDER BY ({$r} IS NULL), {$r} {$direction}) AS {$q('__phibble_rank')} FROM ({}) AS {$jx}c",
                [$sql],
            );
            $sql = Fragment::format(
                'SELECT '.implode(', ', array_map($q, [...$columns, ...$rowKeys]))." FROM ({}) AS {$jx}k WHERE {$q('__phibble_rank')} = 1",
                [$ranked],
            );
        }

        return $x->derive(
            $sql,
            $columns,
            array_values(array_intersect($x->groups(), $columns)),
            $hints,
            $rowKeys,
            $text,
        );
    }
}
