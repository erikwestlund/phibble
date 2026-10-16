<?php

declare(strict_types=1);

/*
 * Expression helpers. Each builds a node of the Phibble\Expr tree:
 *
 *     use function Phibble\Expr\{col, mean, n};
 *
 *     $frame->groupBy(['g'])->summarise(['avg' => mean(col('x')), 'n' => n()]);
 *
 * Names follow dplyr. Some (min, max, round, floor, abs, ...) shadow PHP
 * built-ins inside this namespace only, so built-ins are called with a
 * leading backslash here. Options are named arguments: mean(col('x'), na_rm: true).
 */

namespace Phibble\Expr;

/** @internal */
function call_with(string $fn, array $args): Call
{
    $positional = [];
    $named = [];
    foreach ($args as $key => $value) {
        if (\is_string($key)) {
            $named[$key] = Expr::of($value);
        } else {
            $positional[] = Expr::of($value);
        }
    }

    return new Call($fn, $positional, $named);
}

function col(string $name): Col
{
    return new Col($name);
}

function lit(mixed $value): Lit
{
    return new Lit($value);
}

/** Any registered function by name: call('str_sub', col('s'), 1, 3). */
function call(string $fn, mixed ...$args): Call
{
    return call_with($fn, $args);
}

function desc(Expr|string $expr): Desc
{
    return new Desc(\is_string($expr) ? new Col($expr) : $expr);
}

// Scalar functions
function is_na(mixed $x): Call { return call_with('is_na', [$x]); }
function if_else(mixed $condition, mixed $true, mixed $false, mixed ...$options): Call { return call_with('if_else', [$condition, $true, $false, ...$options]); }
/** case_when(cond1, value1, cond2, value2, ..., default: value) */
function case_when(mixed ...$args): Call { return call_with('case_when', $args); }
function coalesce(mixed ...$args): Call { return call_with('coalesce', $args); }
function between(mixed $x, mixed $low, mixed $high): Call { return call_with('between', [$x, $low, $high]); }
function na_if(mixed $x, mixed $y): Call { return call_with('na_if', [$x, $y]); }
function near(mixed $x, mixed $y, mixed ...$options): Call { return call_with('near', [$x, $y, ...$options]); }
function abs(mixed $x): Call { return call_with('abs', [$x]); }
function sqrt(mixed $x): Call { return call_with('sqrt', [$x]); }
function exp(mixed $x): Call { return call_with('exp', [$x]); }
function log(mixed $x, mixed ...$options): Call { return call_with('log', [$x, ...$options]); }
function log10(mixed $x): Call { return call_with('log10', [$x]); }
function round(mixed $x, mixed ...$options): Call { return call_with('round', [$x, ...$options]); }
function floor(mixed $x): Call { return call_with('floor', [$x]); }
function ceiling(mixed $x): Call { return call_with('ceiling', [$x]); }
function as_int(mixed $x): Call { return call_with('as_int', [$x]); }
function as_float(mixed $x): Call { return call_with('as_float', [$x]); }
function as_string(mixed $x): Call { return call_with('as_string', [$x]); }
function as_bool(mixed $x): Call { return call_with('as_bool', [$x]); }
function str_lower(mixed $x): Call { return call_with('str_lower', [$x]); }
function str_upper(mixed $x): Call { return call_with('str_upper', [$x]); }
function str_length(mixed $x): Call { return call_with('str_length', [$x]); }
function str_trim(mixed $x, mixed ...$options): Call { return call_with('str_trim', [$x, ...$options]); }
function str_sub(mixed $x, mixed $start, mixed $end = -1): Call { return call_with('str_sub', [$x, $start, $end]); }
function str_detect(mixed $x, mixed $pattern, mixed ...$options): Call { return call_with('str_detect', [$x, $pattern, ...$options]); }
function str_starts(mixed $x, mixed $pattern, mixed ...$options): Call { return call_with('str_starts', [$x, $pattern, ...$options]); }
function str_ends(mixed $x, mixed $pattern, mixed ...$options): Call { return call_with('str_ends', [$x, $pattern, ...$options]); }
function str_replace(mixed $x, mixed $pattern, mixed $replacement): Call { return call_with('str_replace', [$x, $pattern, $replacement]); }
function str_replace_all(mixed $x, mixed $pattern, mixed $replacement): Call { return call_with('str_replace_all', [$x, $pattern, $replacement]); }
function paste0(mixed ...$args): Call { return call_with('paste0', $args); }
function paste(mixed ...$args): Call { return call_with('paste', $args); }

// Aggregates
function n(): Call { return call_with('n', []); }
function sum(mixed $x, mixed ...$options): Call { return call_with('sum', [$x, ...$options]); }
function mean(mixed $x, mixed ...$options): Call { return call_with('mean', [$x, ...$options]); }
function median(mixed $x, mixed ...$options): Call { return call_with('median', [$x, ...$options]); }
function min(mixed $x, mixed ...$options): Call { return call_with('min', [$x, ...$options]); }
function max(mixed $x, mixed ...$options): Call { return call_with('max', [$x, ...$options]); }
function sd(mixed $x, mixed ...$options): Call { return call_with('sd', [$x, ...$options]); }
function var_(mixed $x, mixed ...$options): Call { return call_with('var', [$x, ...$options]); }
function quantile(mixed $x, mixed $p, mixed ...$options): Call { return call_with('quantile', [$x, $p, ...$options]); }
function iqr(mixed $x, mixed ...$options): Call { return call_with('iqr', [$x, ...$options]); }
function n_distinct(mixed ...$args): Call { return call_with('n_distinct', $args); }
function first(mixed $x, mixed ...$options): Call { return call_with('first', [$x, ...$options]); }
function last(mixed $x, mixed ...$options): Call { return call_with('last', [$x, ...$options]); }
function nth(mixed $x, mixed $n, mixed ...$options): Call { return call_with('nth', [$x, $n, ...$options]); }
function any(mixed $x, mixed ...$options): Call { return call_with('any', [$x, ...$options]); }
function all(mixed $x, mixed ...$options): Call { return call_with('all', [$x, ...$options]); }

// Window functions
function lag(mixed $x, mixed ...$options): Call { return call_with('lag', [$x, ...$options]); }
function lead(mixed $x, mixed ...$options): Call { return call_with('lead', [$x, ...$options]); }
function row_number(mixed ...$x): Call { return call_with('row_number', $x); }
function min_rank(mixed $x): Call { return call_with('min_rank', [$x]); }
function dense_rank(mixed $x): Call { return call_with('dense_rank', [$x]); }
function percent_rank(mixed $x): Call { return call_with('percent_rank', [$x]); }
function cume_dist(mixed $x): Call { return call_with('cume_dist', [$x]); }
function ntile(mixed ...$args): Call { return call_with('ntile', $args); }
function cumsum(mixed $x): Call { return call_with('cumsum', [$x]); }
function cumprod(mixed $x): Call { return call_with('cumprod', [$x]); }
function cummean(mixed $x): Call { return call_with('cummean', [$x]); }
function cummin(mixed $x): Call { return call_with('cummin', [$x]); }
function cummax(mixed $x): Call { return call_with('cummax', [$x]); }
function cumall(mixed $x): Call { return call_with('cumall', [$x]); }
function cumany(mixed $x): Call { return call_with('cumany', [$x]); }
function consecutive_id(mixed ...$args): Call { return call_with('consecutive_id', $args); }
