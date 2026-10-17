<?php

declare(strict_types=1);

use Phibble\DataFrame;
use Phibble\Type;

use function Phibble\Expr\{col, mean, n};

function joinX(): DataFrame
{
    return DataFrame::fromColumns(['k' => [1.0, 2.0, null, 2.0], 'a' => ['x1', 'x2', 'x3', 'x4'], 'v' => [1, 2, 3, 4]]);
}

function joinY(): DataFrame
{
    return DataFrame::fromColumns(['k' => [2.0, 3.0, null, 2.0], 'b' => ['y1', 'y2', 'y3', 'y4'], 'v' => [11, 12, 13, 14]]);
}

// Expected rows are dplyr 1.1.4 output for the same inputs.
it('left joins like dplyr', function () {
    $out = joinX()->join(joinY(), ['k'], 'left', relationship: 'many-to-many');

    expect($out->columns())->toBe(['k', 'a', 'v.x', 'b', 'v.y'])
        ->and($out->column('a'))->toBe(['x1', 'x2', 'x2', 'x3', 'x4', 'x4'])
        ->and($out->column('b'))->toBe([null, 'y1', 'y4', 'y3', 'y1', 'y4'])
        ->and($out->column('v.y'))->toBe([null, 11, 14, 13, 11, 14]);
});

it('full and right joins append unmatched y rows with coalesced keys', function () {
    $full = joinX()->join(joinY(), ['k'], 'full', relationship: 'many-to-many');
    $right = joinX()->join(joinY(), ['k'], 'right', relationship: 'many-to-many');

    expect($full->column('k'))->toBe([1.0, 2.0, 2.0, null, 2.0, 2.0, 3.0])
        ->and($full->column('a'))->toBe(['x1', 'x2', 'x2', 'x3', 'x4', 'x4', null])
        ->and($right->column('k'))->toBe([2.0, 2.0, null, 2.0, 2.0, 3.0])
        ->and($right->column('b'))->toBe(['y1', 'y4', 'y3', 'y1', 'y4', 'y2']);
});

it('keeps both key sets with keep', function () {
    $out = joinX()->join(joinY(), ['k'], 'inner', keep: true, relationship: 'many-to-many');

    expect($out->columns())->toBe(['k.x', 'a', 'v.x', 'k.y', 'b', 'v.y'])
        ->and($out->nrow())->toBe(5);
});

it('joins on differently named keys and suffixes clashes like dplyr', function () {
    $x = DataFrame::fromColumns(['id' => [1, 2], 'k' => [3, 4]]);
    $y = DataFrame::fromColumns(['key' => [1, 1], 'k' => [9, 8]]);
    $out = $x->join($y, [['id', 'key']], 'left');

    expect($out->toRows())->toBe([
        ['id' => 1, 'k.x' => 3, 'k.y' => 9],
        ['id' => 1, 'k.x' => 3, 'k.y' => 8],
        ['id' => 2, 'k.x' => 4, 'k.y' => null],
    ])
        ->and(DataFrame::fromColumns(['k' => [1], 'v' => [1]])->join(DataFrame::fromColumns(['v' => [1], 'k' => [1]]), [['k', 'v']], 'left')->columns())
        ->toBe(['k', 'v', 'k.y']);
});

it('warns on an unexpected many-to-many join', function () {
    [, $warnings] = quietly(fn () => joinX()->join(joinY(), ['k'], 'left'));

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toStartWith('Detected an unexpected many-to-many relationship between `x` and `y`.')
        ->and($warnings[0])->toContain('Row 2 of `x` matches multiple rows in `y`.')
        ->and($warnings[0])->toContain('Row 1 of `y` matches multiple rows in `x`.');
});

it('enforces relationship and unmatched', function () {
    expect(fn () => joinX()->join(joinY(), ['k'], 'left', relationship: 'many-to-one'))
        ->toThrow(InvalidArgumentException::class, 'Row 2 of `x` matches multiple rows in `y`.')
        ->and(fn () => joinX()->join(joinY(), ['k'], 'inner', multiple: 'first', unmatched: 'error'))
        ->toThrow(InvalidArgumentException::class, 'Row 1 of `x` does not have a match.');
});

it('handles multiple, naMatches, semi, anti and cross', function () {
    expect(joinX()->join(joinY(), ['k'], 'left', multiple: 'last')->column('b'))->toBe([null, 'y4', 'y3', 'y4'])
        ->and(joinX()->join(joinY(), ['k'], 'left', naMatches: false, multiple: 'first')->column('b'))->toBe([null, 'y1', null, 'y1'])
        ->and(joinX()->join(joinY(), ['k'], 'semi')->column('a'))->toBe(['x2', 'x3', 'x4'])
        ->and(joinX()->join(joinY(), ['k'], 'anti')->column('a'))->toBe(['x1'])
        ->and(joinX()->join(joinY(), ['k'], 'semi')->columns())->toBe(['k', 'a', 'v'])
        ->and(joinX()->head(2)->join(joinY()->head(2)->select(['b']), [], 'cross')->column('b'))->toBe(['y1', 'y2', 'y1', 'y2']);
});

it('matches 1 with 1.0 and factors with strings by label', function () {
    $x = DataFrame::fromColumns(['k' => [1, 2], 'g' => ['a', 'b']])->asFactor('g');
    $y = DataFrame::fromColumns(['k' => [1.0, 2.0], 'g' => ['b', 'a'], 'w' => [10, 20]]);

    expect($x->join($y, ['k'], 'left')->column('w'))->toBe([10, 20])
        ->and($x->join($y, ['g'], 'left')->column('w'))->toBe([20, 10]);
});

it('keeps x grouping through joins', function () {
    $out = joinX()->groupBy(['a'])->join(joinY(), ['k'], 'left', multiple: 'first');

    expect($out->groups())->toBe(['a']);
});

it('sorts groups like dplyr: NA last, factors by level', function () {
    $frame = DataFrame::fromColumns(['g' => ['b', 'a', null, 'b'], 'x' => [1, 2, 3, 4]]);
    $counts = $frame->groupBy(['g'])->summarise(['n' => fn ($t) => $t->nrow()]);

    expect($counts->toRows())->toBe([['g' => 'a', 'n' => 1], ['g' => 'b', 'n' => 2], ['g' => null, 'n' => 1]])
        ->and($frame->fctLevels('g', ['b', 'a'])->groupBy(['g'])->summarise(['n' => fn ($t) => $t->nrow()])->column('g'))
        ->toBe(['b', 'a', null]);
});

it('runs vectorised closures per group', function () {
    $frame = DataFrame::fromColumns(['g' => ['a', 'b', 'a', 'b', 'a'], 'x' => [1, 2, 3, 4, 5]])->groupBy(['g']);
    $cumsum = function (DataFrame $t): array {
        $total = 0;

        return array_map(function ($x) use (&$total) {
            return $total += $x;
        }, $t->column('x'));
    };
    $lag = fn (DataFrame $t) => [null, ...array_slice($t->column('x'), 0, -1)];

    $out = $frame->mutateGroups(['cs' => $cumsum, 'prev' => $lag, 'n' => fn (DataFrame $t) => $t->nrow()]);

    expect($out->column('cs'))->toBe([1, 2, 4, 6, 9])
        ->and($out->column('prev'))->toBe([null, null, 1, 2, 3])
        ->and($out->column('n'))->toBe([3, 2, 3, 2, 3])
        ->and($frame->filterGroups(fn (DataFrame $t) => array_map(fn ($x) => $x === max($t->column('x')), $t->column('x')))->column('x'))
        ->toBe([4, 5]);
});

it('treats an ungrouped table and single rows as one group', function () {
    $frame = DataFrame::fromColumns(['x' => [3]]);

    expect($frame->mutateGroups(['y' => fn (DataFrame $t) => [$t->column('x')[0] * 2]])->column('y'))->toBe([6])
        ->and(DataFrame::fromColumns(['x' => [1, 2]])->mutateGroups(['n' => fn (DataFrame $t) => $t->nrow()])->column('n'))->toBe([2, 2]);
});

it('rejects vectorised results of the wrong length', function () {
    DataFrame::fromColumns(['x' => [1, 2]])->mutateGroups(['y' => fn () => [1]]);
})->throws(InvalidArgumentException::class);

it('takes rows by index, with null giving an NA row', function () {
    $frame = penguins()->asFactor('species')->groupBy(['species'])->take([4, null, 0]);

    expect($frame->column('species'))->toBe(['Gentoo', null, 'Adelie'])
        ->and($frame->levels('species'))->toBe(['Adelie', 'Chinstrap', 'Gentoo'])
        ->and($frame->groups())->toBe(['species'])
        ->and(fn () => penguins()->take([99]))->toThrow(InvalidArgumentException::class);
});

it('works rowwise', function () {
    $frame = DataFrame::fromColumns(['id' => ['a', 'b'], 'x' => [1, 4], 'y' => [3, 2]])->rowwise(['id']);
    $summary = $frame->summarise(['m' => fn (DataFrame $r) => max($r->column('x')[0], $r->column('y')[0])]);

    expect($frame->isRowwise())->toBeTrue()
        ->and($frame->groups())->toBe(['id'])
        ->and($frame->mutateGroups(['m' => fn (DataFrame $r) => max($r->column('x')[0], $r->column('y')[0])])->column('m'))->toBe([3, 4])
        ->and($summary->toRows())->toBe([['id' => 'a', 'm' => 3], ['id' => 'b', 'm' => 4]])
        ->and($summary->groups())->toBe(['id'])
        ->and($summary->isRowwise())->toBeFalse()
        ->and($frame->groupBy(['id'])->isRowwise())->toBeFalse()
        ->and($frame->ungroup()->isRowwise())->toBeFalse();
});

it('honours summarise .groups', function () {
    $grouped = penguins()->groupBy(['species', 'year']);

    expect($grouped->summarise(['n' => fn ($t) => $t->nrow()], 'keep')->groups())->toBe(['species', 'year'])
        ->and($grouped->summarise(['n' => fn ($t) => $t->nrow()], 'drop')->groups())->toBe([]);
});

it('nests and unnests like tidyr', function () {
    $frame = DataFrame::fromColumns(['g' => ['b', 'a', 'b'], 'x' => [1, 2, 3], 'y' => [4, 5, 6]]);
    $nested = $frame->nest(['x', 'y']);

    expect($nested->column('g'))->toBe(['b', 'a'])
        ->and($nested->type('data'))->toBe(Type::List)
        ->and($nested->column('data')[0]->toColumns())->toBe(['x' => [1, 3], 'y' => [4, 6]])
        ->and($frame->groupBy(['g'])->nest()->column('g'))->toBe(['b', 'a'])
        ->and($frame->groupBy(['g'])->nest()->groups())->toBe(['g']);

    $back = $nested->unnest('data');
    expect($back->columns())->toBe(['g', 'x', 'y'])
        ->and($back->toRows())->toBe([
            ['g' => 'b', 'x' => 1, 'y' => 4],
            ['g' => 'b', 'x' => 3, 'y' => 6],
            ['g' => 'a', 'x' => 2, 'y' => 5],
        ]);
});

it('unnests plain lists and drops empty rows unless asked', function () {
    $frame = DataFrame::fromColumns(['id' => [1, 2, 3], 'v' => [[10, 20], [], null]]);

    expect($frame->unnest('v')->toRows())->toBe([['id' => 1, 'v' => 10], ['id' => 1, 'v' => 20]])
        ->and($frame->unnest('v', keepEmpty: true)->column('id'))->toBe([1, 1, 2, 3]);
});

it('produces model data in the agreed shape', function () {
    $frame = DataFrame::fromColumns([
        'y' => [1.5, 2.5, null],
        'n' => [1, 2, 3],
        'b' => [true, false, null],
        's' => ['u', 'v', null],
        'g' => ['lo', 'hi', null],
        'o' => ['lo', 'hi', 'lo'],
    ])
        ->fctLevels('g', ['lo', 'hi', 'unused'])
        ->asOrdered('o', ['lo', 'hi'])
        ->withColumn('d', DataFrame::fromCsvString("d\n1970-01-03\n2024-01-02\nNA\n")->storage('d'));

    expect($frame->toModelData())->toBe([
        'y' => [1.5, 2.5, null],
        'n' => [1, 2, 3],
        'b' => [true, false, null],
        's' => ['u', 'v', null],
        'g' => ['levels' => ['lo', 'hi', 'unused'], 'codes' => [0, 1, null], 'ordered' => false],
        'o' => ['levels' => ['lo', 'hi'], 'codes' => [0, 1, 0], 'ordered' => true],
        'd' => [2.0, 19724.0, null],
    ])
        ->and(array_keys($frame->toModelData(['y', 'g'])))->toBe(['y', 'g']);
});

it('refuses list-columns in model data', function () {
    DataFrame::fromColumns(['l' => [[1], [2]]])->toModelData();
})->throws(InvalidArgumentException::class, "Column 'l' is a list-column");

it('binds columns and repairs clashing names like dplyr', function () {
    $out = DataFrame::bindCols([DataFrame::fromColumns(['a' => [1, 2], 'b' => [3, 4]]), DataFrame::fromColumns(['a' => [5, 6]])]);

    expect($out->columns())->toBe(['a...1', 'b', 'a...3'])
        ->and(fn () => DataFrame::bindCols([DataFrame::fromColumns(['a' => [1]]), DataFrame::fromColumns(['b' => [1, 2]])]))
        ->toThrow(InvalidArgumentException::class);
});

it('matches dplyr join_by for inequality, rolling and overlap joins', function () {
    $failures = [];
    foreach (joinByCases() as $case => [$x, $y, $by, $type, $options]) {
        $expected = joinByExpected($case);
        $out = $x()->join($y(), $by, $type, ...$options);
        if ($out->columns() !== $expected['columns']) {
            $failures[$case] = ['columns' => $out->columns(), 'want' => $expected['columns']];

            continue;
        }
        // Row order is x order with matches in y order; compare exactly.
        $got = array_map(fn ($r) => array_values(array_map(fn ($v) => is_int($v) ? (float) $v : $v, $r)), $out->toRows());
        $want = array_map(fn ($r) => array_map(fn ($c) => is_int($r[$c] ?? null) ? (float) $r[$c] : ($r[$c] ?? null), $expected['columns']), $expected['rows']);
        if ($got !== $want) {
            $failures[$case] = ['got' => $got, 'want' => $want];
        }
    }

    expect($failures)->toBe([]);
});

it('rejects keep = false with inequality conditions', function () {
    joinByX()->join(joinByY(), [['t', '>=', 't']], 'left', keep: false);
})->throws(InvalidArgumentException::class, "Can't set `keep = FALSE` when using an inequality, rolling, or overlap join.");

function dropFrame(): DataFrame
{
    return DataFrame::fromColumns(['f' => ['a', 'a', 'c'], 's' => ['x', 'y', 'x'], 'v' => [1.0, 2.0, 3.0]])
        ->fctLevels('f', ['a', 'b', 'c']);
}

// Expected rows are dplyr 1.2.1 output for group_by(..., .drop = FALSE).
it('keeps empty factor-level groups with drop: false', function () {
    $out = dropFrame()->groupBy(['f'], drop: false)->summarise([
        'n' => n(), 's' => Phibble\Expr\sum(col('v')), 'm' => mean(col('v')),
    ]);

    expect($out->column('f'))->toBe(['a', 'b', 'c'])
        ->and($out->column('n'))->toBe([2, 0, 1])
        ->and($out->column('s'))->toBe([3.0, 0.0, 3.0])
        ->and(is_nan($out->column('m')[1]))->toBeTrue()
        ->and($out->levels('f'))->toBe(['a', 'b', 'c']);
});

it('expands empty groups hierarchically like dplyr', function () {
    $fs = dropFrame()->groupBy(['f', 's'], drop: false)->summarise(['n' => n()], 'drop');
    $sf = dropFrame()->groupBy(['s', 'f'], drop: false)->summarise(['n' => n()], 'drop');

    expect($fs->toRows())->toBe([
        ['f' => 'a', 's' => 'x', 'n' => 1],
        ['f' => 'a', 's' => 'y', 'n' => 1],
        ['f' => 'b', 's' => null, 'n' => 0],
        ['f' => 'c', 's' => 'x', 'n' => 1],
    ])
        ->and($sf->toRows())->toBe([
            ['s' => 'x', 'f' => 'a', 'n' => 1],
            ['s' => 'x', 'f' => 'b', 'n' => 0],
            ['s' => 'x', 'f' => 'c', 'n' => 1],
            ['s' => 'y', 'f' => 'a', 'n' => 1],
            ['s' => 'y', 'f' => 'b', 'n' => 0],
            ['s' => 'y', 'f' => 'c', 'n' => 0],
        ]);
});

it('carries drop: false through row verbs and resets it on regrouping', function () {
    $grouped = dropFrame()->groupBy(['f'], drop: false);

    expect($grouped->filter(col('v')->gt(1))->dropsEmptyGroups())->toBeFalse()
        ->and($grouped->filter(col('v')->gt(1))->ngroups())->toBe(3)
        ->and($grouped->mutate(['k' => n()])->column('k'))->toBe([2, 2, 1])
        ->and(iterator_to_array($grouped->eachGroup(), false)[1]['table']->nrow())->toBe(0)
        ->and($grouped->groupBy(['s'])->dropsEmptyGroups())->toBeTrue()
        ->and($grouped->ungroup()->dropsEmptyGroups())->toBeTrue()
        ->and(dropFrame()->groupBy(['f'])->ngroups())->toBe(2);
});

it('exposes group rows in group order', function () {
    $groups = dropFrame()->groupBy(['f'], drop: false)->groupRows();

    expect($groups)->toBe([
        ['key' => ['f' => 'a'], 'rows' => [0, 1]],
        ['key' => ['f' => 'b'], 'rows' => []],
        ['key' => ['f' => 'c'], 'rows' => [2]],
    ])
        ->and(dropFrame()->groupRows())->toBe([['key' => [], 'rows' => [0, 1, 2]]])
        ->and(count(dropFrame()->rowwise()->groupRows()))->toBe(3);
});
