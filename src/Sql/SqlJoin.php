<?php

declare(strict_types=1);

namespace Phibble\Sql;

use InvalidArgumentException;

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
    public static function build(SqlTable $x, SqlTable $y, array $by, string $type, array $suffix, bool $keep, bool $naMatches): SqlTable
    {
        if (! in_array($type, ['inner', 'left', 'right', 'full', 'semi', 'anti', 'cross'], true)) {
            throw new InvalidArgumentException("Unknown join type '{$type}'.");
        }
        $pairs = [];
        foreach ($by as $left => $right) {
            $pairs[] = match (true) {
                is_array($right) => [(string) $right[0], (string) $right[1]],
                is_string($left) => [$left, (string) $right],
                default => [(string) $right, (string) $right],
            };
        }
        if (($type === 'cross') !== ($pairs === [])) {
            throw new InvalidArgumentException($type === 'cross' ? 'A cross join takes no keys.' : 'Join keys are required; pass by.');
        }

        $dialect = $x->dialect();
        $q = $dialect->quote(...);
        $jx = 'j'.$x->alias().'x';
        $jy = 'j'.$x->alias().'y';
        foreach ($pairs as [$l, $r]) {
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
        $template = 'SELECT '.implode(', ', $items)." FROM ({}) AS {$jx} {$joinSql} ({}) AS {$jy}";
        $parts = [$x->fragment(), $y->fragment()];
        if ($on !== null) {
            $template .= ' ON {}';
            $parts[] = $on;
        }

        return $x->derive(
            Fragment::format($template, $parts),
            $columns,
            array_values(array_intersect($x->groups(), $columns)),
            $hints,
        );
    }
}
