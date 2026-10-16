<?php

declare(strict_types=1);

use Phibble\Frame;

/*
 * join_by reference cases shared by the Frame and SqlTable tests. Expected
 * output is dplyr 1.2's, written by tests/r/joins.R. between(), within()
 * and overlaps() are given in their expanded form, as phplyr sends them.
 */

function joinByX(): Frame
{
    return Frame::fromColumns(['id' => [1, 2, 3, 4], 't' => [5.0, 10.0, null, 20.0], 'g' => ['a', 'a', 'b', 'b']]);
}

function joinByY(): Frame
{
    return Frame::fromColumns(['t' => [4.0, 5.0, 5.0, 12.0, null], 'g' => ['a', 'a', 'a', 'b', 'b'], 'v' => [1, 2, 3, 4, 5]]);
}

function intervalX(): Frame
{
    return Frame::fromColumns(['id' => [1, 2, 3], 'lo' => [1.0, 5.0, 10.0], 'hi' => [4.0, 8.0, 12.0]]);
}

function intervalY(): Frame
{
    return Frame::fromColumns(['k' => [1, 2, 3], 'start' => [2.0, 6.0, 11.0], 'end' => [3.0, 9.0, 20.0]]);
}

/** @return array<string, array{0: Closure, 1: Closure, 2: array, 3: string, 4: array}> name => [x, y, by, type, options] */
function joinByCases(): array
{
    $plain = [fn () => joinByX(), fn () => joinByY()];
    $intervals = [fn () => intervalX(), fn () => intervalY()];

    return [
        'ge_eq' => [...$plain, ['g', ['t', '>=', 't']], 'left', []],
        'closest_ge' => [...$plain, ['g', ['closest', 't', '>=', 't']], 'left', []],
        'gt_only' => [...$plain, [['t', '>', 't']], 'inner', []],
        'closest_le' => [...$plain, ['g', ['closest', 't', '<=', 't']], 'left', []],
        'semi_lt' => [...$plain, [['t', '<', 't']], 'semi', []],
        'anti_lt' => [...$plain, [['t', '<', 't']], 'anti', []],
        'full_closest' => [...$plain, ['g', ['closest', 't', '>=', 't']], 'full', []],
        'right_ge' => [...$plain, ['g', ['t', '>=', 't']], 'right', []],
        'keep_true' => [...$plain, ['g', ['t', '>=', 't']], 'left', ['keep' => true]],
        'na_never' => [...$plain, ['g', ['t', '>=', 't']], 'left', ['naMatches' => false]],
        'between' => [...$intervals, [['lo', '>=', 'start'], ['lo', '<=', 'end']], 'inner', []],
        'within' => [...$intervals, [['lo', '>=', 'start'], ['hi', '<=', 'end']], 'inner', []],
        'overlaps' => [...$intervals, [['lo', '<=', 'end'], ['hi', '>=', 'start']], 'inner', []],
        'closest_gt_inner' => [...$plain, [['closest', 't', '>', 't']], 'inner', []],
    ];
}

/** @return array{columns: list<string>, rows: list<array<string, mixed>>} */
function joinByExpected(string $case): array
{
    static $all = null;
    $all ??= json_decode(file_get_contents(__DIR__.'/../fixtures/joins/expected.json'), true);

    return $all[$case];
}

/** Rows as lists in column order, numbers as floats, for comparison. */
function joinRows(array $columns, array $rows): array
{
    $out = array_map(fn (array $row) => array_map(
        fn (string $c) => is_int($row[$c] ?? null) || is_float($row[$c] ?? null) ? (float) $row[$c] : ($row[$c] ?? null),
        $columns,
    ), $rows);
    usort($out, fn ($a, $b) => json_encode($a) <=> json_encode($b));

    return $out;
}
