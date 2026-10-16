<?php

declare(strict_types=1);

use Phibble\Column\FactorColumn;
use Phibble\Contracts\Table;
use Phibble\Exception\ColumnNotFound;
use Phibble\Frame;
use Phibble\Row;
use Phibble\Type;

it('builds from rows and round-trips', function () {
    $rows = [['a' => 1, 'b' => 'x'], ['a' => 2, 'b' => 'y']];
    $frame = Frame::fromRows($rows);

    expect($frame)->toBeInstanceOf(Table::class)
        ->and($frame->columns())->toBe(['a', 'b'])
        ->and($frame->nrow())->toBe(2)
        ->and($frame->ncol())->toBe(2)
        ->and(count($frame))->toBe(2)
        ->and($frame->toRows())->toBe($rows)
        ->and($frame->toColumns())->toBe(['a' => [1, 2], 'b' => ['x', 'y']]);
});

it('fills missing keys with NA and keeps first-appearance column order', function () {
    $frame = Frame::fromRows([['a' => 1], ['b' => 2.5, 'a' => 3]]);

    expect($frame->columns())->toBe(['a', 'b'])
        ->and($frame->column('b'))->toBe([null, 2.5]);
});

it('builds from columns and rejects ragged columns', function () {
    $frame = Frame::fromColumns(['x' => [1.0, 2.0], 'y' => [true, false]]);
    expect($frame->types())->toBe(['x' => Type::Float, 'y' => Type::Bool]);

    Frame::fromColumns(['x' => [1, 2], 'y' => [1]]);
})->throws(InvalidArgumentException::class, "Column 'y' has 1 values; expected 2.");

it('infers types from PHP values', function () {
    $frame = Frame::fromColumns([
        'f' => [1, 2.5, null],
        'i' => [1, null, 3],
        'b' => [true, false, null],
        's' => ['a', null, 'c'],
        'd' => [new DateTime('2024-01-02'), null, new DateTimeImmutable('2024-03-04')],
        'na' => [null, null, null],
    ]);

    expect($frame->types())->toBe([
        'f' => Type::Float, 'i' => Type::Int, 'b' => Type::Bool,
        's' => Type::String, 'd' => Type::Date, 'na' => Type::Bool,
    ])
        ->and($frame->column('f'))->toBe([1.0, 2.5, null])
        ->and($frame->column('d')[0])->toBeInstanceOf(DateTimeImmutable::class);
});

it('accepts explicit types', function () {
    $frame = Frame::fromColumns(['x' => [1, 2], 'g' => ['b', 'a']], types: ['x' => 'float', 'g' => Type::Factor]);

    expect($frame->column('x'))->toBe([1.0, 2.0])
        ->and($frame->type('g'))->toBe(Type::Factor)
        ->and($frame->levels('g'))->toBe(['a', 'b']);
});

it('rejects values it cannot cast', function () {
    Frame::fromColumns(['x' => ['abc']], types: ['x' => 'int']);
})->throws(InvalidArgumentException::class, "Cannot cast 'abc' to int in column 'x'.");

it('throws ColumnNotFound for unknown columns', function () {
    penguins()->column('beak');
})->throws(ColumnNotFound::class, "Column 'beak' not found.");

it('gives rows that read by property and by key', function () {
    $row = penguins()->row(0);

    expect($row)->toBeInstanceOf(Row::class)
        ->and($row->species)->toBe('Adelie')
        ->and($row['mass'])->toBe(3750.0)
        ->and(isset($row->mass))->toBeTrue()
        ->and($row->toArray())->toHaveKeys(['species', 'island', 'mass', 'year']);
});

it('keeps rows read-only', function () {
    $row = penguins()->row(0);
    $row['mass'] = 1;
})->throws(LogicException::class);

it('iterates rows', function () {
    $species = [];
    foreach (penguins() as $i => $row) {
        $species[$i] = $row->species;
    }

    expect($species)->toBe(['Adelie', 'Adelie', 'Gentoo', 'Chinstrap', 'Gentoo']);
});

it('stores factors as levels and codes', function () {
    $frame = penguins()->asFactor('species');
    $storage = $frame->storage('species');

    expect($storage)->toBeInstanceOf(FactorColumn::class)
        ->and($frame->levels('species'))->toBe(['Adelie', 'Chinstrap', 'Gentoo'])
        ->and($storage->codes())->toBe([0, 0, 2, 1, 2])
        ->and($frame->column('species'))->toBe(['Adelie', 'Adelie', 'Gentoo', 'Chinstrap', 'Gentoo']);
});

it('uses explicit factor levels and turns unknown values into NA', function () {
    $frame = penguins()->asFactor('species', ['Gentoo', 'Adelie']);

    expect($frame->levels('species'))->toBe(['Gentoo', 'Adelie'])
        ->and($frame->column('species'))->toBe(['Adelie', 'Adelie', 'Gentoo', null, 'Gentoo']);
});

it('sorts numeric factor levels numerically', function () {
    $frame = Frame::fromColumns(['n' => [10, 9, 100]])->asFactor('n');

    expect($frame->levels('n'))->toBe(['9', '10', '100']);
});

it('casts columns', function () {
    $frame = penguins()->cast(['year' => 'string', 'mass' => Type::Int]);

    expect($frame->column('year'))->toBe(['2007', '2008', '2007', '2009', '2008'])
        ->and($frame->column('mass'))->toBe([3750, 3800, 5000, null, 5400]);
});

it('drops rows with NA', function () {
    expect(penguins()->dropNa()->nrow())->toBe(4)
        ->and(penguins()->dropNa(['year'])->nrow())->toBe(5);
});

it('prints like a tibble', function () {
    $out = (string) penguins()->groupBy(['species'])->head(2);

    expect($out)->toBe(<<<'TXT'
        # A phibble: 2 × 4
        # Groups: species [1]
          species island    mass  year
          <chr>   <chr>     <dbl> <int>
        1 Adelie  Torgersen 3750  2007
        2 Adelie  Biscoe    3800  2008
        TXT);
});
