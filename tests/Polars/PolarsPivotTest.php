<?php

declare(strict_types=1);

use Phibble\Frame;
use Phibble\Polars\PolarsTable;

// tidyr 1.3.2 reference cases from phibble's tests/fixtures/pivot/cases.json.

function pivotCasesPt(): array
{
    static $cases = null;

    return $cases ??= json_decode(file_get_contents(__DIR__.'/../fixtures/pivot/cases.json'), true);
}

function pivotNormalisePt(mixed $v): mixed
{
    return match (true) {
        is_array($v) && count($v) === 1 => pivotNormalisePt($v[0]),
        is_array($v) => array_map(pivotNormalisePt(...), $v),
        is_int($v) => (float) $v,
        default => $v,
    };
}

dataset('polars pivot cases', fn () => array_map(fn ($c) => [$c], array_keys(pivotCasesPt())));

it('matches tidyr pivot specs on PolarsTable', function (string $name) {
    $case = pivotCasesPt()[$name];
    $table = pt(Frame::fromRows($case['data']));
    $args = $case['args'];
    if (isset($args['idColumns'])) {
        $args['idColumns'] = (array) $args['idColumns'];
    }
    $fallbacks = PolarsTable::$fallbacks;
    [$out, $warnings] = quietly(fn () => $case['kind'] === 'longer'
        ? $table->pivotLongerSpec($case['spec'], ...$args)
        : $table->pivotWiderSpec($case['spec'], ...$args));
    $rows = fn (array $rows) => array_map(fn (array $r) => array_map(fn (string $c) => pivotNormalisePt($r[$c] ?? null), $case['columns']), $rows);

    expect($out)->toBeInstanceOf(Phibble\Polars\PolarsTable::class)
        ->and($out->columns())->toBe($case['columns'])
        ->and($rows($out->toRows()))->toBe($rows($case['rows']))
        ->and(PolarsTable::$fallbacks)->toBe($fallbacks);
    if (isset($case['warning'])) {
        expect($warnings)->toContain($case['warning']);
    }
})->with('polars pivot cases');
