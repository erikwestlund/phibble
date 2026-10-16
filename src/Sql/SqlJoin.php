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

        $dialect = $x->dialect();
        $q = $dialect->quote(...);
        $jx = 'j'.$x->alias().'x';
        $jy = 'j'.$x->alias().'y';
        $all = [...$pairs, ...array_map(fn ($c) => [$c[0], $c[2]], $inequalities), ...($closest ? [[$closest[0], $closest[2]]] : [])];
        foreach ($all as [$l, $r]) {
            if (! in_array($l, $x->columns(), true) || ! in_array($r, $y->columns(), true)) {
                throw new InvalidArgumentException("Join key {$l} = {$r} not found.");
            }
        }

        $conditions = [];
        foreach ($pairs as [$l, $r]) {
            $left = Fragment::raw("{$jx}.{$q($l)}");
            $right = Fragment::raw("{$jy}.{$q($r)}");
            $conditions[] = $naMatches ? $dialect->nullSafeEquals($left, $right) : Fragment::format('({} = {})', [$left, $right]);
        }
        // With NA matching, NA equals NA: it satisfies >= and <= but not > or <.
        foreach ([...$inequalities, ...($closest ? [$closest] : [])] as [$l, $op, $r]) {
            $left = "{$jx}.{$q($l)}";
            $right = "{$jy}.{$q($r)}";
            $conditions[] = Fragment::raw($naMatches && in_array($op, ['>=', '<='], true)
                ? "(({$left} {$op} {$right}) OR ({$left} IS NULL AND {$right} IS NULL))"
                : "({$left} {$op} {$right})");
        }
        $on = $conditions === [] ? null : Fragment::join(' AND ', $conditions);

        if ($type === 'semi' || $type === 'anti') {
            $sql = Fragment::format(
                "SELECT {$jx}.* FROM ({}) AS {$jx} WHERE ".($type === 'anti' ? 'NOT ' : '')."EXISTS (SELECT 1 FROM ({}) AS {$jy} WHERE {})",
                [$x->fragment(), $y->fragment(), $on],
            );

            return $x->derive($sql, $x->columns(), $x->groups(), $x->hints());
        }

        $xKeys = array_column($pairs, 0);
        $yKeys = array_column($pairs, 1);
        $yOut = $keep ? $y->columns() : array_values(array_diff($y->columns(), $yKeys));
        $items = [];
        $columns = [];
        $hints = [];
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
        }

        $joinSql = match ($type) {
            'inner' => 'INNER JOIN',
            'left' => 'LEFT JOIN',
            'right' => 'RIGHT JOIN',
            'full' => 'FULL JOIN',
            'cross' => 'CROSS JOIN',
        };
        $xFrom = $x->fragment();
        if ($closest !== null) {
            // Number x's rows so the closest match can be picked per x row.
            $xFrom = Fragment::format("SELECT *, ROW_NUMBER() OVER () AS {$q('__phibble_xid')} FROM ({}) AS {$jx}r", [$xFrom]);
            $items[] = "{$jx}.{$q('__phibble_xid')} AS {$q('__phibble_xid')}";
            $items[] = "{$jy}.{$q($closest[2])} AS {$q('__phibble_r')}";
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
                "SELECT *, RANK() OVER (PARTITION BY {$q('__phibble_xid')} ORDER BY ({$r} IS NULL), {$r} {$direction}) AS {$q('__phibble_rank')} FROM ({}) AS {$jx}c",
                [$sql],
            );
            $sql = Fragment::format(
                'SELECT '.implode(', ', array_map($q, $columns))." FROM ({}) AS {$jx}k WHERE {$q('__phibble_rank')} = 1",
                [$ranked],
            );
        }

        return $x->derive(
            $sql,
            $columns,
            array_values(array_intersect($x->groups(), $columns)),
            $hints,
        );
    }
}
