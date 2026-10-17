<?php

declare(strict_types=1);

use Phibble\DataFrame;
use Phibble\Type;

/*
 * Inputs, specs and expected output come from tidyr 1.3.2 via
 * tests/r/pivot.R. Numbers compare as floats, and list cells of length 1
 * compare as their single value, as jsonlite writes them.
 */
function pivotCases(): array
{
    static $cases = null;

    return $cases ??= json_decode(file_get_contents(__DIR__.'/fixtures/pivot/cases.json'), true);
}

function pivotNormalise(mixed $v): mixed
{
    return match (true) {
        is_array($v) && count($v) === 1 => pivotNormalise($v[0]),
        is_array($v) => array_map(pivotNormalise(...), $v),
        is_int($v) => (float) $v,
        default => $v,
    };
}

function pivotRows(array $columns, array $rows): array
{
    return array_map(fn (array $r) => array_map(fn (string $c) => pivotNormalise($r[$c] ?? null), $columns), $rows);
}

dataset('pivot cases', fn () => array_map(fn ($c) => [$c], array_keys(pivotCases())));

it('matches tidyr pivot specs', function (string $name) {
    $case = pivotCases()[$name];
    $frame = DataFrame::fromRows($case['data']);
    $args = $case['args'];
    if (isset($args['idColumns'])) {
        $args['idColumns'] = (array) $args['idColumns'];
    }

    [$out, $warnings] = quietly(fn () => $case['kind'] === 'longer'
        ? $frame->pivotLongerSpec($case['spec'], ...$args)
        : $frame->pivotWiderSpec($case['spec'], ...$args));

    expect($out->columns())->toBe($case['columns'])
        ->and(pivotRows($case['columns'], $out->toRows()))->toBe(pivotRows($case['columns'], $case['rows']));
    if ($case['warning'] !== null) {
        expect($warnings)->toHaveCount(1)->and($warnings[0])->toBe($case['warning']);
    } else {
        expect($warnings)->toBe([]);
    }
})->with('pivot cases');

it('combines value types with vctrs rules', function () {
    $out = DataFrame::fromColumns(['id' => [1], 'a' => [1], 'b' => [2.5]])
        ->pivotLongerSpec([['.name' => 'a', '.value' => 'v', 'k' => 'a'], ['.name' => 'b', '.value' => 'v', 'k' => 'b']]);

    expect($out->type('v'))->toBe(Type::Float)
        ->and(fn () => DataFrame::fromColumns(['id' => [1], 'a' => [1], 'b' => ['x']])
            ->pivotLongerSpec([['.name' => 'a', '.value' => 'v', 'k' => 'a'], ['.name' => 'b', '.value' => 'v', 'k' => 'b']]))
        ->toThrow(InvalidArgumentException::class, "Can't combine `a` <int> and `b` <chr>.");
});

it('keeps factor value columns and list-columns from duplicates', function () {
    $dupes = DataFrame::fromColumns(['id' => [1, 1, 2], 'key' => ['x', 'x', 'y'], 'val' => [1.0, 2.0, 3.0]]);
    [$out] = quietly(fn () => $dupes->pivotWiderSpec([
        ['.name' => 'x', '.value' => 'val', 'key' => 'x'],
        ['.name' => 'y', '.value' => 'val', 'key' => 'y'],
    ]));

    expect($out->type('x'))->toBe(Type::List)
        ->and($out->column('x'))->toBe([[1.0, 2.0], null])
        ->and($out->column('y'))->toBe([null, [3.0]]);
});
