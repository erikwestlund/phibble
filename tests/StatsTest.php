<?php

declare(strict_types=1);

use Phibble\DataFrame;

it('builds the x matrix and y vector for stats functions', function () {
    $frame = DataFrame::fromColumns(['x1' => [1, 2, null], 'x2' => [true, false, true], 'y' => [1.5, 2.5, 3.5]]);

    expect($frame->toMatrix(['x1', 'x2']))->toBe([[1.0, 1.0], [2.0, 0.0], [null, 1.0]])
        ->and($frame->toVector('y'))->toBe([1.5, 2.5, 3.5]);
});

it('refuses non-numeric matrix columns', function () {
    penguins()->toMatrix(['species']);
})->throws(InvalidArgumentException::class, "Column 'species' is string");

it('feeds stats_ols', function () {
    $frame = DataFrame::fromColumns(['x' => [1, 2, 3, 4, 5], 'y' => [3.0, 5.0, 7.0, 9.0, 11.0]]);

    $fit = stats_ols($frame->toMatrix(['x']), $frame->toVector('y'));

    expect($fit['coefficients'][0])->toEqualWithDelta(1.0, 1e-9)
        ->and($fit['coefficients'][1])->toEqualWithDelta(2.0, 1e-9);
})->skip(! function_exists('stats_ols'), 'php_stats extension not loaded');

it('passes NA through to stats_ols, which drops the row and warns once', function () {
    $frame = DataFrame::fromColumns([
        'x' => [1, 2, 3, 4, 5, null],
        'y' => [3.0, 5.0, 7.0, 9.0, 11.0, 2.0],
    ]);

    $warnings = [];
    set_error_handler(function (int $level, string $message) use (&$warnings) {
        $warnings[] = $message;

        return true;
    }, E_WARNING);
    try {
        $fit = stats_ols($frame->toMatrix(['x']), $frame->toVector('y'));
    } finally {
        restore_error_handler();
    }

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('dropped 1 of 6')
        ->and($fit['n_dropped'])->toBe(1)
        ->and($fit['coefficients'][1])->toEqualWithDelta(2.0, 1e-9);
})->skip(! function_exists('stats_ols'), 'php_stats extension not loaded');
