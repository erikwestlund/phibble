<?php

declare(strict_types=1);

use Phibble\Expr\Call;
use Phibble\Expr\Expr;
use Phibble\Frame;

use function Phibble\Expr\{all, any, as_string, between, case_when, coalesce, col, consecutive_id, cumall, cumany, cummax, cummean, cumsum, cume_dist, dense_rank, desc, first, if_else, iqr, is_na, lag, last, lead, lit, log, max, mean, median, min, min_rank, n, n_distinct, na_if, nth, ntile, paste, paste0, percent_rank, quantile, round, row_number, sd, str_detect, str_length, str_lower, str_replace, str_replace_all, str_sub, str_trim, sum, var_};






dataset('mutate', fn () => mutateCases());

it('matches dplyr for mutate expressions', function (Closure $frame, Expr $expr) {
    $case = $this->dataName();
    $case = substr($case, strpos($case, '"') + 1, -1);
    if ($case === 'round') {
        // Each value has its own digits in the R case; evaluate row by row.
        $values = [0.5, 1.5, 2.5, -0.5, 2.675, 0.125, 1234.5];
        $digits = [0, 0, 0, 0, 2, 2, -1];
        $out = array_map(fn ($v, $d) => Frame::fromColumns(['v' => [$v]])->mutate(['out' => round(col('v'), digits: $d)])->column('out')[0], $values, $digits);
    } else {
        $out = $frame()->mutate(['out' => $expr])->column('out');
    }

    expect(normalise($out))->toBe(expected($case));
})->with('mutate');

it('matches dplyr for grouped mutate expressions', function () {
    $grouped = exprFrame()->groupBy(['g']);

    expect(normalise($grouped->mutate(['out' => col('x')->sub(mean(col('x'), na_rm: true))])->column('out')))->toBe(expected('grp_mean'))
        ->and(normalise($grouped->mutate(['out' => lag(col('i'))])->column('out')))->toBe(expected('grp_lag'))
        ->and(normalise($grouped->mutate(['out' => row_number()])->column('out')))->toBe(expected('grp_row_number'))
        ->and(normalise($grouped->mutate(['out' => cumsum(col('i'))])->column('out')))->toBe(expected('grp_cumsum'));
});

it('matches dplyr for summarise expressions', function (string $case, Expr $expr) {
    $out = exprFrame()->groupBy(['g'])->summarise(['out' => $expr])->column('out');

    expect(normalise($out))->toBe(expected($case));
})->with(summariseCases());


it('filters with expressions per group, dropping NA', function () {
    $frame = exprFrame()->groupBy(['g'])->filter(col('x')->gt(mean(col('x'), na_rm: true)));

    expect($frame->column('i'))->toBe([1, 4, 6])
        ->and(exprFrame()->filter(col('b'))->column('i'))->toBe([1, 4, 5]);
});

it('arranges by expressions', function () {
    expect(exprFrame()->arrange([desc(col('x')->mul(-1))])->column('i'))->toBe([2, 5, 1, 4, 6, 7, 3])
        ->and(exprFrame()->arrange([str_lower(col('s'))])->column('i'))->toBe([5, 1, 6, 2, 7, 4, 3]);
});

it('serialises to arrays and back', function () {
    $expr = if_else(col('x')->gt(2)->and(col('g')->isIn(['a', 'b'])), 'big', lit(null), missing: 'none');
    $array = $expr->toArray();

    expect($array['fn'])->toBe('if_else')
        ->and($array['named'])->toBe(['missing' => ['type' => 'lit', 'value' => 'none']])
        ->and($array['args'][0]['args'][1]['args'][1])->toBe(['type' => 'lit', 'value' => ['a', 'b']])
        ->and(Expr::fromArray(json_decode(json_encode($array), true)))->toEqual($expr)
        ->and($expr->columns())->toBe(['x', 'g'])
        ->and(lit(new DateTimeImmutable('2024-01-02', new DateTimeZone('UTC')))->toArray())
        ->toBe(['type' => 'lit', 'value' => '2024-01-02', 'dtype' => 'date']);
});

it('validates calls against the registry', function () {
    expect(fn () => new Call('nope', []))->toThrow(InvalidArgumentException::class, "Unknown function 'nope'")
        ->and(fn () => Phibble\Expr\call('mean'))->toThrow(InvalidArgumentException::class, 'mean() takes 1 positional argument(s), got 0.')
        ->and(fn () => mean(col('x'), bogus: true))->toThrow(InvalidArgumentException::class, "mean() has no argument 'bogus'.")
        ->and(fn () => Phibble\Expr\call('abs', desc(col('x'))))->toThrow(InvalidArgumentException::class, 'desc() is only allowed')
        ->and(fn () => exprFrame()->groupBy(['g'])->summarise(['out' => col('x')]))->toThrow(LengthException::class, 'must be size 1');
});
