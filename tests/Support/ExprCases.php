<?php

declare(strict_types=1);

use Phibble\Expr\Expr;
use Phibble\DataFrame;

use function Phibble\Expr\{all, any, as_string, between, case_when, coalesce, col, consecutive_id, cumall, cumany, cummax, cummean, cumsum, cume_dist, dense_rank, desc, first, if_else, iqr, is_na, lag, last, lead, lit, log, max, mean, median, min, min_rank, n, n_distinct, na_if, nth, ntile, paste, paste0, percent_rank, quantile, round, row_number, sd, str_detect, str_length, str_lower, str_replace, str_replace_all, str_sub, str_trim, sum, var_};

function exprFrame(): DataFrame
{
    return DataFrame::fromColumns([
        'g' => ['a', 'b', 'a', 'b', 'a', 'b', null],
        'x' => [3.0, 1.0, null, 4.0, 1.0, 5.0, 9.0],
        'i' => [1, 2, 3, 4, 5, 6, 7],
        'b' => [true, null, false, true, true, false, null],
        's' => ['Apple', 'banana', null, 'cherry pie', '  pad  ', 'apple', 'Banana'],
    ]);
}

/** Compare numbers loosely, and treat Inf/NaN as NA (JSON can't hold them). */
function normalise(array $values): array
{
    return array_map(fn ($v) => match (true) {
        is_float($v) && ! is_finite($v) => null,
        is_int($v), is_float($v) => \round((float) $v, 9),
        default => $v,
    }, $values);
}

function expected(string $case): array
{
    static $all = null;
    $all ??= json_decode(file_get_contents(__DIR__.'/../fixtures/expr/expected.json'), true);

    return normalise($all[$case]);
}

/** Mutate with a temporary literal column holding $values. */
function withValues(array $values, string $name = 'v'): DataFrame
{
    return exprFrame()->withColumn($name, $values);
}

function mutateCases(): array
{
    $x = col('x');
    $i = col('i');
    $b = col('b');
    $s = col('s');
    $v = col('v');
    $w = col('w');

    return [
        'arith' => [fn () => exprFrame(), $x->mul(2)->add($i)->sub(1)],
        'div' => [fn () => withValues([1, 0, 2, 0, 5, 1, 1]), $i->div($v)],
        'mod' => [fn () => withValues([-7, 7, -7, 7, 5.5, -5.5, 0])->withColumn('w', [3, -3, -3, 3, 2, 2, 4]), $v->mod($w)],
        'pow' => [fn () => exprFrame(), $i->pow(2)],
        'cmp' => [fn () => exprFrame(), $x->ge(3)],
        'and' => [fn () => exprFrame(), $b->and($x->gt(2))],
        'or' => [fn () => exprFrame(), $b->or($x->gt(2))],
        'not' => [fn () => exprFrame(), $b->not()],
        'is_na' => [fn () => exprFrame(), is_na($x)],
        'if_else' => [fn () => exprFrame(), if_else($x->gt(2), 'big', 'small', missing: 'none')],
        'case_when' => [fn () => exprFrame(), case_when($x->gt(4), 'high', $x->gt(2), 'mid', default: 'low')],
        'coalesce' => [fn () => exprFrame(), coalesce($x, $i->mul(10))],
        'in_' => [fn () => exprFrame(), col('g')->isIn(['a', null])],
        'between' => [fn () => exprFrame(), between($x, 1, 3)],
        'na_if' => [fn () => exprFrame(), na_if($i, 3)],
        'round' => [fn () => withValues([0.5, 1.5, 2.5, -0.5, 2.675, 0.125, 1234.5])->withColumn('w', [0, 0, 0, 0, 2, 2, -1]),
            Phibble\Expr\call('round', $v, digits: 0)],
        'log' => [fn () => exprFrame(), log($i, base: 2)],
        'str_lower' => [fn () => exprFrame(), str_lower($s)],
        'str_length' => [fn () => exprFrame(), str_length($s)],
        'str_trim' => [fn () => exprFrame(), str_trim($s)],
        'str_sub' => [fn () => exprFrame(), str_sub($s, 2, -2)],
        'str_sub_neg' => [fn () => exprFrame(), str_sub($s, -3)],
        'str_detect' => [fn () => exprFrame(), str_detect($s, 'an')],
        'str_replace' => [fn () => exprFrame(), str_replace($s, 'a', 'A')],
        'str_replace_all' => [fn () => exprFrame(), str_replace_all($s, 'a', 'A')],
        'paste0' => [fn () => exprFrame(), paste0(col('g'), '-', $i)],
        'paste' => [fn () => exprFrame(), paste(col('g'), $x, sep: '/')],
        'as_string' => [fn () => exprFrame(), as_string($x->div(3))],
        'lag' => [fn () => exprFrame(), lag($x)],
        'lead2' => [fn () => exprFrame(), lead($i, n: 2, default: 0)],
        'lag_order' => [fn () => exprFrame(), lag($i, order_by: $x)],
        'row_number' => [fn () => exprFrame(), row_number($x)],
        'row_number_desc' => [fn () => exprFrame(), row_number(desc($x))],
        'min_rank' => [fn () => withValues([2, 1, 2, null, 3, 1, 2]), min_rank($v)],
        'dense_rank' => [fn () => withValues([2, 1, 2, null, 3, 1, 2]), dense_rank($v)],
        'percent_rank' => [fn () => withValues([2, 1, 2, null, 3, 1, 2]), percent_rank($v)],
        'cume_dist' => [fn () => withValues([2, 1, 2, null, 3, 1, 2]), cume_dist($v)],
        'ntile' => [fn () => exprFrame(), ntile($i, n: 3)],
        'ntile_x' => [fn () => exprFrame(), ntile($x, n: 2)],
        'cumsum' => [fn () => withValues([1.0, 2.0, null, 3.0, 4.0, 5.0, 6.0]), cumsum($v)],
        'cumsum_int' => [fn () => exprFrame(), cumsum($i)],
        'cummean' => [fn () => exprFrame(), cummean($i)],
        'cummax' => [fn () => withValues([1, 3, 2, 5, null, 1, 9]), cummax($v)],
        'cumall' => [fn () => withValues([true, null, true, false, true, null, true]), cumall($v)],
        'cumany' => [fn () => withValues([false, null, false, true, false, null, false]), cumany($v)],
        'consecutive_id' => [fn () => withValues(['a', 'a', 'b', 'b', 'a', null, null]), consecutive_id($v)],
    ];
}

function summariseCases(): array
{
    return [
    ['sm_n', n()],
    ['sm_sum', sum(col('x'))],
    ['sm_sum_rm', sum(col('x'), na_rm: true)],
    ['sm_mean', mean(col('x'), na_rm: true)],
    ['sm_median', median(col('x'), na_rm: true)],
    ['sm_sd', sd(col('x'), na_rm: true)],
    ['sm_var', var_(col('i'))],
    ['sm_min', min(col('i'))],
    ['sm_max_s', max(col('s'), na_rm: true)],
    ['sm_quantile', quantile(col('i'), 0.3)],
    ['sm_iqr', iqr(col('i'))],
    ['sm_n_distinct', n_distinct(col('x'))],
    ['sm_n_distinct_rm', n_distinct(col('x'), na_rm: true)],
    ['sm_first', first(col('x'))],
    ['sm_last_order', last(col('s'), order_by: col('i'))],
    ['sm_nth', nth(col('i'), -2)],
    ['sm_any', any(col('b'))],
    ['sm_all', all(col('b'))],
];
}
