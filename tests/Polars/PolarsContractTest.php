<?php

declare(strict_types=1);

use Phibble\Exception\ColumnNotFound;
use Phibble\Frame;
use Phibble\Type;
use Phibble\Polars\PolarsTable;

use function Phibble\Expr\{col, mean, n};

// phibble's contract v1 cases (dplyr 1.1.4 outputs), run on the Polars table.

function joinXPt(): PolarsTable
{
    return pt(Frame::fromColumns(['k' => [1.0, 2.0, null, 2.0], 'a' => ['x1', 'x2', 'x3', 'x4'], 'v' => [1, 2, 3, 4]]));
}

function joinYPt(): PolarsTable
{
    return pt(Frame::fromColumns(['k' => [2.0, 3.0, null, 2.0], 'b' => ['y1', 'y2', 'y3', 'y4'], 'v' => [11, 12, 13, 14]]));
}

function penguinsPt(): PolarsTable
{
    return pt(Frame::fromRows([
        ['species' => 'Adelie', 'island' => 'Torgersen', 'mass' => 3750.0, 'year' => 2007],
        ['species' => 'Adelie', 'island' => 'Biscoe', 'mass' => 3800.0, 'year' => 2008],
        ['species' => 'Gentoo', 'island' => 'Biscoe', 'mass' => 5000.0, 'year' => 2007],
        ['species' => 'Chinstrap', 'island' => 'Dream', 'mass' => null, 'year' => 2009],
        ['species' => 'Gentoo', 'island' => 'Biscoe', 'mass' => 5400.0, 'year' => 2008],
    ]));
}

it('left joins like dplyr', function () {
    $out = native(fn () => joinXPt()->join(joinYPt(), ['k'], 'left', relationship: 'many-to-many'));

    expect($out->columns())->toBe(['k', 'a', 'v.x', 'b', 'v.y'])
        ->and($out->column('a'))->toBe(['x1', 'x2', 'x2', 'x3', 'x4', 'x4'])
        ->and($out->column('b'))->toBe([null, 'y1', 'y4', 'y3', 'y1', 'y4'])
        ->and($out->column('v.y'))->toBe([null, 11, 14, 13, 11, 14]);
});

it('full and right joins append unmatched y rows with coalesced keys', function () {
    $full = joinXPt()->join(joinYPt(), ['k'], 'full', relationship: 'many-to-many');
    $right = joinXPt()->join(joinYPt(), ['k'], 'right', relationship: 'many-to-many');

    expect($full->column('k'))->toBe([1.0, 2.0, 2.0, null, 2.0, 2.0, 3.0])
        ->and($full->column('a'))->toBe(['x1', 'x2', 'x2', 'x3', 'x4', 'x4', null])
        ->and($right->column('k'))->toBe([2.0, 2.0, null, 2.0, 2.0, 3.0])
        ->and($right->column('b'))->toBe(['y1', 'y4', 'y3', 'y1', 'y4', 'y2']);
});

it('keeps both key sets with keep', function () {
    $out = joinXPt()->join(joinYPt(), ['k'], 'inner', keep: true, relationship: 'many-to-many');

    expect($out->columns())->toBe(['k.x', 'a', 'v.x', 'k.y', 'b', 'v.y'])
        ->and($out->nrow())->toBe(5);
});

it('joins on differently named keys and suffixes clashes like dplyr', function () {
    $x = pt(Frame::fromColumns(['id' => [1, 2], 'k' => [3, 4]]));
    $y = pt(Frame::fromColumns(['key' => [1, 1], 'k' => [9, 8]]));

    expect($x->join($y, [['id', 'key']], 'left')->toRows())->toBe([
        ['id' => 1, 'k.x' => 3, 'k.y' => 9],
        ['id' => 1, 'k.x' => 3, 'k.y' => 8],
        ['id' => 2, 'k.x' => 4, 'k.y' => null],
    ])
        ->and(pt(Frame::fromColumns(['k' => [1], 'v' => [1]]))->join(pt(Frame::fromColumns(['v' => [1], 'k' => [1]])), [['k', 'v']], 'left')->columns())
        ->toBe(['k', 'v', 'k.y']);
});

it('warns on an unexpected many-to-many join', function () {
    [, $warnings] = quietly(fn () => joinXPt()->join(joinYPt(), ['k'], 'left'));

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toStartWith('Detected an unexpected many-to-many relationship between `x` and `y`.')
        ->and($warnings[0])->toContain('Row 2 of `x` matches multiple rows in `y`.')
        ->and($warnings[0])->toContain('Row 1 of `y` matches multiple rows in `x`.');
});

it('enforces relationship and unmatched', function () {
    expect(fn () => joinXPt()->join(joinYPt(), ['k'], 'left', relationship: 'many-to-one'))
        ->toThrow(InvalidArgumentException::class, 'Row 2 of `x` matches multiple rows in `y`.')
        ->and(fn () => joinXPt()->join(joinYPt(), ['k'], 'inner', multiple: 'first', unmatched: 'error'))
        ->toThrow(InvalidArgumentException::class, 'Row 1 of `x` does not have a match.');
});

it('handles multiple, naMatches, semi, anti and cross', function () {
    expect(joinXPt()->join(joinYPt(), ['k'], 'left', multiple: 'last')->column('b'))->toBe([null, 'y4', 'y3', 'y4'])
        ->and(joinXPt()->join(joinYPt(), ['k'], 'left', naMatches: false, multiple: 'first')->column('b'))->toBe([null, 'y1', null, 'y1'])
        ->and(joinXPt()->join(joinYPt(), ['k'], 'semi')->column('a'))->toBe(['x2', 'x3', 'x4'])
        ->and(joinXPt()->join(joinYPt(), ['k'], 'anti')->column('a'))->toBe(['x1'])
        ->and(joinXPt()->join(joinYPt(), ['k'], 'semi')->columns())->toBe(['k', 'a', 'v'])
        ->and(joinXPt()->head(2)->join(joinYPt()->head(2)->select(['b']), [], 'cross')->column('b'))->toBe(['y1', 'y2', 'y1', 'y2']);
});

it('matches 1 with 1.0 and factors with strings by label', function () {
    $x = pt(Frame::fromColumns(['k' => [1, 2], 'g' => ['a', 'b']])->asFactor('g'));
    $y = pt(Frame::fromColumns(['k' => [1.0, 2.0], 'g' => ['b', 'a'], 'w' => [10, 20]]));

    expect($x->join($y, ['k'], 'left')->column('w'))->toBe([10, 20])
        ->and($x->join($y, ['g'], 'left')->column('w'))->toBe([20, 10]);
});

it('keeps x grouping through joins', function () {
    expect(joinXPt()->groupBy(['a'])->join(joinYPt(), ['k'], 'left', multiple: 'first')->groups())->toBe(['a']);
});

it('sorts groups like dplyr: NA last, factors by level', function () {
    $frame = Frame::fromColumns(['g' => ['b', 'a', null, 'b'], 'x' => [1, 2, 3, 4]]);
    $counts = native(fn () => pt($frame)->groupBy(['g'])->summarise(['n' => n()]));

    expect($counts->toRows())->toBe([['g' => 'a', 'n' => 1], ['g' => 'b', 'n' => 2], ['g' => null, 'n' => 1]])
        ->and(pt($frame->fctLevels('g', ['b', 'a']))->groupBy(['g'])->summarise(['n' => n()])->column('g'))->toBe(['b', 'a', null])
        ->and(pt($frame->fctLevels('g', ['b', 'a']))->groupBy(['g'])->summarise(['n' => n()])->collect()->levels('g'))->toBe(['b', 'a']);
});

it('iterates groups in sorted order with ungrouped tables', function () {
    $groups = iterator_to_array(pt(Frame::fromColumns(['g' => ['b', 'a', null, 'b'], 'x' => [1, 2, 3, 4]]))->groupBy(['g'])->eachGroup());

    expect(array_column($groups, 'key'))->toBe([['g' => 'a'], ['g' => 'b'], ['g' => null]])
        ->and($groups[1]['table']->column('x'))->toBe([1, 4])
        ->and($groups[1]['table']->groups())->toBe([]);
});

it('runs closures by falling back to a Frame, keeping groups', function () {
    $grouped = pt(Frame::fromColumns(['g' => ['a', 'b', 'a', 'b', 'a'], 'x' => [1, 2, 3, 4, 5]]))->groupBy(['g']);
    $out = $grouped->mutateGroups(['cs' => fn (Frame $t) => array_map(fn ($i) => array_sum(array_slice($t->column('x'), 0, $i + 1)), array_keys($t->column('x')))]);

    expect($out)->toBeInstanceOf(PolarsTable::class)
        ->and($out->column('cs'))->toBe([1, 2, 4, 6, 9])
        ->and($out->groups())->toBe(['g'])
        ->and($grouped->filter(fn ($r) => $r->x > 2)->column('x'))->toBe([3, 4, 5])
        ->and($grouped->summarise(['m' => fn ($t) => max($t->column('x'))])->toRows())->toBe([['g' => 'a', 'm' => 5], ['g' => 'b', 'm' => 4]]);
});

it('takes rows by index, with null giving an NA row', function () {
    $frame = penguinsPt()->groupBy(['species'])->take([4, null, 0]);

    expect($frame->column('species'))->toBe(['Gentoo', null, 'Adelie'])
        ->and($frame->column('mass'))->toBe([5400.0, null, 3750.0])
        ->and($frame->groups())->toBe(['species'])
        ->and(fn () => penguinsPt()->take([99]))->toThrow(InvalidArgumentException::class);
});

it('works rowwise through the Frame fallback', function () {
    $frame = pt(Frame::fromColumns(['id' => ['a', 'b'], 'x' => [1, 4], 'y' => [3, 2]]))->rowwise(['id']);
    $summary = $frame->summarise(['m' => fn (Frame $r) => max($r->column('x')[0], $r->column('y')[0])]);

    expect($frame->isRowwise())->toBeTrue()
        ->and($frame->groups())->toBe(['id'])
        ->and($summary->toRows())->toBe([['id' => 'a', 'm' => 3], ['id' => 'b', 'm' => 4]])
        ->and($summary->groups())->toBe(['id'])
        ->and($frame->groupBy(['id'])->isRowwise())->toBeFalse();
});

it('keeps types and round-trips through collect', function () {
    $frame = Frame::fromColumns([
        'i' => [1, null], 'f' => [1.5, null], 'b' => [true, null], 's' => ['x', null],
        'd' => [new DateTimeImmutable('2024-01-02', new DateTimeZone('UTC')), null],
    ])->asFactor('s');
    $table = pt($frame);

    expect($table->types())->toBe(['i' => Type::Int, 'f' => Type::Float, 'b' => Type::Bool, 's' => Type::Factor, 'd' => Type::DateTime])
        ->and($table->collect()->toColumns())->toEqual($frame->toColumns())
        ->and($table->collect()->levels('s'))->toBe(['x']);
});

it('selects, renames, distinct, slices and counts like the contract', function () {
    $t = penguinsPt()->groupBy(['species']);

    expect($t->select(['mass'])->columns())->toBe(['species', 'mass'])
        ->and($t->rename(['kind' => 'species'])->groups())->toBe(['kind'])
        ->and($t->distinct(['island'])->column('island'))->toBe(['Torgersen', 'Biscoe', 'Dream'])
        ->and($t->slice(1, 2)->column('year'))->toBe([2008, 2007])
        ->and($t->head(1)->nrow())->toBe(1)
        ->and(count($t))->toBe(5)
        ->and(iterator_to_array($t)[0]->species)->toBe('Adelie')
        ->and(fn () => $t->select(['nope']))->toThrow(ColumnNotFound::class)
        ->and(fn () => $t->mutate(['z' => col('nope')]))->toThrow(ColumnNotFound::class);
});

it('mutates with lists, scalars and later entries seeing earlier ones', function () {
    $out = native(fn () => penguinsPt()->mutate(['k' => [1, 2, 3, 4, 5], 'c' => 'const', 'k2' => col('k')->mul(2), 'avg' => mean(col('mass'), na_rm: true)]));

    expect($out->column('k2'))->toBe([2, 4, 6, 8, 10])
        ->and($out->column('c'))->toBe(['const', 'const', 'const', 'const', 'const'])
        ->and($out->column('avg')[0])->toBe(4487.5);
});

// join_by cases (dplyr 1.2) shared with phibble's Frame and SqlTable tests.

dataset('join_by', fn () => joinByCases());

it('matches dplyr join_by in Polars', function (Closure $x, Closure $y, array $by, string $type, array $options) {
    $case = $this->dataName();
    $case = substr($case, strpos($case, '"') + 1, -1);
    $out = native(fn () => pt($x())->join(pt($y()), $by, $type, ...$options));
    $expected = joinByExpected($case);

    expect($out->columns())->toBe($expected['columns'])
        ->and(joinRows($expected['columns'], $out->toRows()))->toBe(joinRows($expected['columns'], $expected['rows']));
})->with('join_by');

it('refuses keep: false with comparisons', function () {
    expect(fn () => pt(joinByX())->join(pt(joinByY()), [['t', '>=', 't']], 'left', keep: false))
        ->toThrow(InvalidArgumentException::class, "Can't set `keep = FALSE` when using an inequality, rolling, or overlap join.");
});

it('honours summarise .groups and groupBy(drop: false)', function () {
    $grouped = penguinsPt()->groupBy(['species', 'year']);
    $levels = pt(Frame::fromColumns(['g' => ['a', 'a'], 'x' => [1, 2]])->fctLevels('g', ['a', 'b']));

    expect($grouped->summarise(['n' => n()], 'keep')->groups())->toBe(['species', 'year'])
        ->and($grouped->summarise(['n' => n()], 'drop')->groups())->toBe([])
        ->and($grouped->summarise(['n' => n()])->groups())->toBe(['species'])
        ->and($levels->groupBy(['g'], drop: false)->dropsEmptyGroups())->toBeFalse()
        ->and($levels->groupBy(['g'], drop: false)->summarise(['n' => n()])->toRows())->toBe([['g' => 'a', 'n' => 2], ['g' => 'b', 'n' => 0]]);
});

it('lists group rows in group order', function () {
    $t = pt(Frame::fromColumns(['g' => ['b', 'a', null, 'b'], 'x' => [1, 2, 3, 4]]));

    expect($t->groupBy(['g'])->groupRows())->toBe([
        ['key' => ['g' => 'a'], 'rows' => [1]],
        ['key' => ['g' => 'b'], 'rows' => [0, 3]],
        ['key' => ['g' => null], 'rows' => [2]],
    ])
        ->and($t->groupRows())->toBe([['key' => [], 'rows' => [0, 1, 2, 3]]])
        ->and($t->head(0)->groupRows())->toBe([['key' => [], 'rows' => []]])
        ->and($t->groupBy(['g'])->groupRows())->toBe($t->collect()->groupBy(['g'])->groupRows());
});

it('ranks and orders factors by level, as phibble v0.1.0 does', function () {
    $frame = Frame::fromColumns(['f' => ['a', 'b', 'c', 'b'], 'x' => [1, 2, 3, 4]])->fctLevels('f', ['c', 'b', 'a']);

    expect(native(fn () => pt($frame)->mutate(['r' => Phibble\Expr\min_rank(col('f'))]))->column('r'))
        ->toBe($frame->mutate(['r' => Phibble\Expr\min_rank(col('f'))])->column('r'))
        ->and(pt($frame)->mutate(['p' => Phibble\Expr\lag(col('x'), order_by: col('f'))])->column('p'))
        ->toBe($frame->mutate(['p' => Phibble\Expr\lag(col('x'), order_by: col('f'))])->column('p'));
});

it('creates Polars tables with polars()', function () {
    $frame = Frame::fromColumns(['g' => ['a', 'b', 'a'], 'x' => [1, 2, 3]]);
    $out = Phibble\polars($frame)->groupBy(['g'])->summarise(['n' => Phibble\Expr\n()]);

    expect(Phibble\polars($frame))->toBeInstanceOf(Phibble\Polars\PolarsTable::class)
        ->and($out->collect()->toRows())->toBe($frame->groupBy(['g'])->summarise(['n' => Phibble\Expr\n()])->toRows())
        ->and(Phibble\polars([['a' => 1]])->collect()->column('a'))->toBe([1]);
});
