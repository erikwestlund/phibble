<?php

declare(strict_types=1);

use Phibble\Expr\Expr;
use Phibble\DataFrame;
use Phibble\Polars\PolarsTable;

use function Phibble\Expr\{col, cumsum, desc, lag, mean, round, row_number, str_lower};

// The same dplyr reference cases phibble's in-memory and SQL backends pass.

dataset('polars mutate', fn () => mutateCases());

it('matches dplyr for mutate expressions in Polars', function (Closure $frame, Expr $expr) {
    $case = $this->dataName();
    $case = substr($case, strpos($case, '"') + 1, -1);
    if ($case === 'round') {
        $values = [0.5, 1.5, 2.5, -0.5, 2.675, 0.125, 1234.5];
        $digits = [0, 0, 0, 0, 2, 2, -1];
        $out = array_map(fn ($v, $d) => native(fn () => pt(DataFrame::fromColumns(['v' => [$v]]))->mutate(['out' => round(col('v'), digits: $d)]))->column('out')[0], $values, $digits);
    } else {
        $out = native(fn () => pt($frame())->mutate(['out' => $expr]))->column('out');
    }

    expect(normalise($out))->toBe(expected($case));
})->with('polars mutate');

it('matches dplyr for grouped mutate expressions in Polars', function () {
    $grouped = pt(exprFrame())->groupBy(['g']);
    $run = fn (Expr $e) => normalise(native(fn () => $grouped->mutate(['out' => $e]))->column('out'));

    expect($run(col('x')->sub(mean(col('x'), na_rm: true))))->toBe(expected('grp_mean'))
        ->and($run(lag(col('i'))))->toBe(expected('grp_lag'))
        ->and($run(row_number()))->toBe(expected('grp_row_number'))
        ->and($run(cumsum(col('i'))))->toBe(expected('grp_cumsum'));
});

it('matches dplyr for summarise expressions in Polars', function (string $case, Expr $expr) {
    $out = native(fn () => pt(exprFrame())->groupBy(['g'])->summarise(['out' => $expr]))->column('out');

    expect(normalise($out))->toBe(expected($case));
})->with(summariseCases());

it('filters with expressions per group in Polars, dropping NA', function () {
    $frame = native(fn () => pt(exprFrame())->groupBy(['g'])->filter(col('x')->gt(mean(col('x'), na_rm: true))));

    expect($frame->column('i'))->toBe([1, 4, 6])
        ->and(native(fn () => pt(exprFrame())->filter(col('b')))->column('i'))->toBe([1, 4, 5]);
});

it('arranges by expressions in Polars', function () {
    expect(native(fn () => pt(exprFrame())->arrange([desc(col('x')->mul(-1))]))->column('i'))->toBe([2, 5, 1, 4, 6, 7, 3])
        ->and(native(fn () => pt(exprFrame())->arrange([str_lower(col('s'))]))->column('i'))->toBe([5, 1, 6, 2, 7, 4, 3]);
});

it('rejects summarise entries that are not size 1', function () {
    expect(fn () => pt(exprFrame())->groupBy(['g'])->summarise(['out' => col('x')]))->toThrow(LengthException::class, 'must be size 1');
});

it('raises like R for quantile() over NA without na_rm', function () {
    expect(fn () => pt(exprFrame())->summarise(['q' => Phibble\Expr\quantile(col('x'), 0.5)]))
        ->toThrow(InvalidArgumentException::class, "missing values and NaN's not allowed if 'na_rm' is FALSE")
        ->and(fn () => pt(exprFrame())->groupBy(['g'])->mutate(['q' => Phibble\Expr\iqr(col('x'))]))
        ->toThrow(InvalidArgumentException::class, "missing values and NaN's not allowed")
        ->and(pt(exprFrame())->summarise(['q' => Phibble\Expr\quantile(col('x'), 0.5, na_rm: true)])->column('q'))
        ->toBe(exprFrame()->summarise(['q' => Phibble\Expr\quantile(col('x'), 0.5, na_rm: true)])->column('q'));
});
