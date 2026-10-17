<?php

declare(strict_types=1);

use Phibble\DataFrame;
use Phibble\Row;
use Phibble\Type;

it('is immutable', function () {
    $frame = penguins();
    $frame->mutate(['x' => 1])->filter(fn () => false)->groupBy(['species']);

    expect($frame->columns())->toBe(['species', 'island', 'mass', 'year'])
        ->and($frame->nrow())->toBe(5)
        ->and($frame->groups())->toBe([]);
});

it('selects columns in the given order', function () {
    expect(penguins()->select(['year', 'species'])->columns())->toBe(['year', 'species']);
});

it('keeps grouping columns when selecting', function () {
    expect(penguins()->groupBy(['island'])->select(['mass'])->columns())->toBe(['island', 'mass']);
});

it('filters rows, treating NA as false', function () {
    $heavy = penguins()->filter(fn (Row $r) => $r->mass === null ? null : $r->mass > 3780);

    expect($heavy->column('mass'))->toBe([3800.0, 5000.0, 5400.0]);
});

it('filters within groups', function () {
    $frame = penguins()
        ->groupBy(['species'])
        ->filter(fn (Row $r, DataFrame $g) => $r->year === max($g->column('year')));

    expect($frame->column('year'))->toBe([2008, 2009, 2008])
        ->and($frame->groups())->toBe(['species']);
});

it('mutates with closures, lists and scalars, in order', function () {
    $frame = penguins()->mutate([
        'kg' => fn (Row $r) => $r->mass === null ? null : $r->mass / 1000,
        'heavy' => fn (Row $r) => $r->kg === null ? null : $r->kg > 4,
        'id' => [1, 2, 3, 4, 5],
        'source' => 'palmer',
        'year' => fn (Row $r) => $r->year - 2000,
    ]);

    expect($frame->columns())->toBe(['species', 'island', 'mass', 'year', 'kg', 'heavy', 'id', 'source'])
        ->and($frame->column('kg'))->toBe([3.75, 3.8, 5.0, null, 5.4])
        ->and($frame->column('heavy'))->toBe([false, false, true, null, true])
        ->and($frame->column('source'))->toBe(array_fill(0, 5, 'palmer'))
        ->and($frame->column('year'))->toBe([7, 8, 7, 9, 8]);
});

it('mutates within groups', function () {
    $frame = penguins()
        ->groupBy(['species'])
        ->mutate(['n' => fn (Row $r, DataFrame $g) => $g->nrow()]);

    expect($frame->column('n'))->toBe([2, 2, 2, 1, 2]);
});

it('rejects mutate lists of the wrong length', function () {
    penguins()->mutate(['x' => [1, 2]]);
})->throws(InvalidArgumentException::class);

it('renames with new => old', function () {
    $frame = penguins()->groupBy(['species'])->rename(['sp' => 'species', 'body_mass' => 'mass']);

    expect($frame->columns())->toBe(['sp', 'island', 'body_mass', 'year'])
        ->and($frame->groups())->toBe(['sp']);
});

it('arranges by several keys, stably, with NA last', function () {
    $frame = penguins()->arrange([['year', 'desc'], 'mass']);
    expect($frame->column('mass'))->toBe([null, 3800.0, 5400.0, 3750.0, 5000.0]);

    $frame = penguins()->arrange([['mass', 'desc']]);
    expect($frame->column('mass'))->toBe([5400.0, 5000.0, 3800.0, 3750.0, null]);

    $frame = penguins()->arrange(['island']);
    expect($frame->column('species'))->toBe(['Adelie', 'Gentoo', 'Gentoo', 'Chinstrap', 'Adelie']);
});

it('arranges factors by level order', function () {
    $frame = penguins()->asFactor('species', ['Gentoo', 'Chinstrap', 'Adelie'])->arrange(['species']);

    expect($frame->column('species'))->toBe(['Gentoo', 'Gentoo', 'Chinstrap', 'Adelie', 'Adelie']);
});

it('rejects unknown sort directions', function () {
    penguins()->arrange([['mass', 'up']]);
})->throws(InvalidArgumentException::class);

it('iterates groups in sorted key order with ungrouped tables', function () {
    $groups = iterator_to_array(penguins()->groupBy(['species', 'year'])->eachGroup(), false);

    expect(array_column($groups, 'key'))->toBe([
        ['species' => 'Adelie', 'year' => 2007],
        ['species' => 'Adelie', 'year' => 2008],
        ['species' => 'Chinstrap', 'year' => 2009],
        ['species' => 'Gentoo', 'year' => 2007],
        ['species' => 'Gentoo', 'year' => 2008],
    ])
        ->and($groups[0]['table']->groups())->toBe([]);
});

it('yields one group for an ungrouped table', function () {
    $groups = iterator_to_array(penguins()->eachGroup(), false);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['key'])->toBe([])
        ->and($groups[0]['table']->nrow())->toBe(5);
});

it('summarises per group and drops the last grouping level', function () {
    $mean = fn (array $xs) => array_sum($xs) / count($xs);
    $result = penguins()
        ->dropNa()
        ->groupBy(['island', 'species'])
        ->summarise([
            'n' => fn (DataFrame $g) => $g->nrow(),
            'mass' => fn (DataFrame $g) => $mean($g->column('mass')),
        ]);

    expect($result->toRows())->toBe([
        ['island' => 'Biscoe', 'species' => 'Adelie', 'n' => 1, 'mass' => 3800.0],
        ['island' => 'Biscoe', 'species' => 'Gentoo', 'n' => 2, 'mass' => 5200.0],
        ['island' => 'Torgersen', 'species' => 'Adelie', 'n' => 1, 'mass' => 3750.0],
    ])
        ->and($result->groups())->toBe(['island']);
});

it('summarises an ungrouped table to one row', function () {
    $result = penguins()->summarise(['n' => fn (DataFrame $g) => $g->nrow()]);

    expect($result->toRows())->toBe([['n' => 5]]);
});

it('keeps group key types in summaries', function () {
    $result = penguins()->asFactor('species')->groupBy(['species'])->summarise(['n' => fn ($g) => $g->nrow()]);

    expect($result->type('species'))->toBe(Type::Factor);
});

it('finds distinct rows', function () {
    expect(penguins()->distinct(['species'])->column('species'))->toBe(['Adelie', 'Gentoo', 'Chinstrap'])
        ->and(penguins()->distinct(['species'])->columns())->toBe(['species'])
        ->and(penguins()->distinct()->nrow())->toBe(5)
        ->and(penguins()->distinct(['island', 'species'])->nrow())->toBe(4);
});

it('slices and takes heads', function () {
    expect(penguins()->head(2)->column('year'))->toBe([2007, 2008])
        ->and(penguins()->slice(3)->column('year'))->toBe([2009, 2008])
        ->and(penguins()->slice(1, 2)->column('year'))->toBe([2008, 2007])
        ->and(penguins()->tail(1)->column('year'))->toBe([2008])
        ->and(penguins()->head(0)->nrow())->toBe(0);
});

it('handles empty frames', function () {
    $empty = penguins()->filter(fn () => false);

    expect($empty->nrow())->toBe(0)
        ->and($empty->columns())->toBe(['species', 'island', 'mass', 'year'])
        ->and($empty->arrange(['mass'])->nrow())->toBe(0)
        ->and($empty->groupBy(['species'])->summarise(['n' => fn ($g) => $g->nrow()])->nrow())->toBe(0)
        ->and($empty->summarise(['n' => fn ($g) => $g->nrow()])->toRows())->toBe([['n' => 0]])
        ->and($empty->mutate(['x' => fn () => 1])->nrow())->toBe(0);
});

it('concatenates tables, keeping shared types and filling missing columns', function () {
    $a = penguins()->asFactor('species')->head(2);
    $b = penguins()->asFactor('species')->slice(2)->select(['species', 'mass']);
    $stacked = DataFrame::concat([$a, $b]);

    expect($stacked->nrow())->toBe(5)
        ->and($stacked->type('species'))->toBe(Type::Factor)
        ->and($stacked->levels('species'))->toBe(['Adelie', 'Chinstrap', 'Gentoo'])
        ->and($stacked->type('year'))->toBe(Type::Int)
        ->and($stacked->column('year'))->toBe([2007, 2008, null, null, null])
        ->and(DataFrame::concat([])->nrow())->toBe(0);
});
