<?php

declare(strict_types=1);

use Phibble\DataFrame;

/*
 * Output is compared byte for byte with tibble's, from fixtures written by
 * tests/r/print.R. Each frame here mirrors the R case of the same name.
 */

function printFixture(string $name): string
{
    return file_get_contents(__DIR__."/fixtures/print/{$name}.txt");
}

function utc(string $datetime): DateTimeImmutable
{
    return new DateTimeImmutable($datetime, new DateTimeZone('UTC'));
}

function printCases(): array
{
    $grouped = DataFrame::fromColumns([
        'species' => ['Adelie', 'Adelie', 'Gentoo', 'Chinstrap', 'Gentoo'],
        'island' => ['Torgersen', 'Biscoe', 'Biscoe', 'Dream', 'Biscoe'],
        'mass' => [3750.0, 3800.0, 5000.0, null, 5400.0],
        'year' => [2007, 2008, 2007, 2009, 2008],
    ])->groupBy(['species']);

    $wide = [];
    for ($i = 1; $i <= 15; $i++) {
        $wide["measurement_{$i}"] = array_map(fn (int $k) => round($k * $i * 1000 + $i / 7, 2), [1, 2, 3]);
    }
    $veryWide = [];
    for ($i = 1; $i <= 120; $i++) {
        $veryWide[sprintf('column_number_%03d', $i)] = [$i + 0.5];
    }
    $longNames = [];
    foreach (range('a', 'f') as $i => $letter) {
        $longNames['a_really_long_column_name_'.($i + 1)] = array_fill(0, 2, str_repeat($letter, 12));
    }

    return [
        'basic' => DataFrame::fromColumns([
            'a' => [1.5, 10.25, 100.0, null, -3.14159],
            'b' => [1, null, 300, -4, 5],
            'c' => ['x', null, 'long string here', 'y', 'z'],
        ]),
        'magnitudes' => DataFrame::fromColumns([
            'big' => [1e10, 2.5e10, 3.0],
            'small' => [1e-10, 0.5, 2.0],
            'tiny' => [0.000123, 0.0045, 0.1],
        ]),
        'scientific' => DataFrame::fromColumns([
            'x' => [123456789.123, 0.001],
            'y' => [1e15, 1.0],
            'z' => [0.1, 0.25],
        ]),
        'scientific_negative' => DataFrame::fromColumns([
            'x' => [1e-20, 1e20, null],
            'y' => [-1.5e-12, 2e8, 0.0],
        ]),
        'rounding' => DataFrame::fromColumns([
            'x' => [10.25, 2.675, 9.995, 0.125, 1234.5, 99.95, 9.9996],
            'y' => [-0.5, 0.05, 1 / 3, 2 / 3, 12345.678, 0.1 + 0.2, 7.0],
        ]),
        'specials' => DataFrame::fromColumns([
            'x' => [INF, -INF, NAN, 0.0, null],
            'y' => [0.0, 0.0, 0.0, 0.0, 0.0],
            'z' => [123456789, 1, null, -42, 0],
        ]),
        'many_rows' => DataFrame::fromColumns(['n' => range(1, 25), 's' => range('a', 'y')]),
        'twenty_rows' => DataFrame::fromColumns(['n' => range(1, 20)]),
        'thousands' => DataFrame::fromColumns(['id' => range(1, 1234)]),
        'dates' => DataFrame::fromColumns([
            'd' => ['2024-01-02', null],
            't' => [utc('2024-01-02 10:00:00'), utc('2024-03-04 00:00:00')],
            'l' => [true, null],
        ], types: ['d' => 'date']),
        'factors' => DataFrame::fromColumns([
            'f' => ['a', 'b', null],
            'o' => ['lo', 'hi', 'mid'],
        ])->asFactor('f')->asOrdered('o', ['lo', 'mid', 'hi']),
        'lists' => DataFrame::fromColumns(['x' => [
            [1, 2, 3], [1.5, 2.0], 'a', null,
            DataFrame::fromColumns(['a' => [1, 2], 'b' => [3, 4], 'c' => [5, 6]]),
            [1, 'b'],
        ]]),
        'empty' => DataFrame::fromColumns([]),
        'rows_no_cols' => DataFrame::fromRows([[], [], []]),
        'cols_no_rows' => DataFrame::fromColumns(['a' => [], 'b' => []], types: ['a' => 'float', 'b' => 'string']),
        'wide' => DataFrame::fromColumns($wide),
        'very_wide' => DataFrame::fromColumns($veryWide),
        'long_strings' => DataFrame::fromColumns([
            's' => [
                'a very long string that goes on and on and on and on and on and on and on and on and on',
                'short',
            ],
            'n' => [1.0, 2.0],
        ]),
        'ambiguous' => DataFrame::fromColumns(['s' => [' leading', 'trailing ', '', 'quote"s', null]]),
        'nonsyntactic' => DataFrame::fromColumns(['my col' => [1, 2], '1x' => ['a', 'b'], 'if' => [true, false]]),
        'long_names' => DataFrame::fromColumns($longNames),
        'grouped' => $grouped,
        'grouped_two' => $grouped->groupBy(['species', 'year']),
        'negatives' => DataFrame::fromColumns(['x' => [-1.0, -10.5, 100.25], 'y' => [-0.001, 0.02, -3.0]]),
    ];
}

it('prints like tibble', function (string $name) {
    expect((string) printCases()[$name])->toBe(printFixture($name));
})->with([
    'basic', 'magnitudes', 'scientific', 'scientific_negative', 'rounding', 'specials',
    'many_rows', 'twenty_rows', 'thousands', 'dates', 'factors', 'lists', 'empty',
    'rows_no_cols', 'cols_no_rows', 'wide', 'very_wide', 'long_strings', 'ambiguous',
    'nonsyntactic', 'long_names', 'grouped', 'grouped_two', 'negatives',
]);

it('prints the requested number of rows', function () {
    expect(DataFrame::fromColumns(['n' => range(1, 5)])->format(n: 4))->toBe(printFixture('n_rows'));
});

it('squeezes columns into a narrow width', function () {
    $frame = DataFrame::fromColumns([
        'x' => [123.456, 7.1, 0.5],
        's' => ['abcdefghijklmnopqrstuvwxyz', 'b', 'c'],
        't' => ['ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'B', 'C'],
    ]);

    expect($frame->format(width: 30))->toBe(printFixture('narrow'));
});

it('glimpses like tibble', function (string $name) {
    expect(printCases()[$name]->glimpse())->toBe(printFixture("glimpse_{$name}"));
})->with([
    'basic', 'magnitudes', 'dates', 'factors', 'lists', 'empty', 'cols_no_rows',
    'wide', 'long_strings', 'nonsyntactic', 'grouped',
]);

it('prints rowwise tables like dplyr', function () {
    $frame = DataFrame::fromColumns(['g' => ['a', 'b', 'a'], 'x' => [1, 2, 3]]);

    expect((string) $frame->rowwise())->toBe(printFixture('rowwise'))
        ->and((string) $frame->rowwise(['g']))->toBe(printFixture('rowwise_id'))
        ->and($frame->rowwise()->glimpse())->toBe(printFixture('glimpse_rowwise'));
})->skip(! method_exists(DataFrame::class, 'rowwise'), 'DataFrame::rowwise() not available yet');
