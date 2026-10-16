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

// Patterns (stringr): a plain string is a regular expression
function fixed(mixed $pattern): Call { return call_with('fixed', [$pattern]); }
function regex(mixed $pattern, mixed ...$options): Call { return call_with('regex', [$pattern, ...$options]); }

// stringr
function str_count(mixed $x, mixed $pattern): Call { return call_with('str_count', [$x, $pattern]); }
function str_extract(mixed $x, mixed $pattern): Call { return call_with('str_extract', [$x, $pattern]); }
function str_extract_all(mixed $x, mixed $pattern): Call { return call_with('str_extract_all', [$x, $pattern]); }
function str_split(mixed $x, mixed $pattern): Call { return call_with('str_split', [$x, $pattern]); }
function str_split_i(mixed $x, mixed $pattern, mixed $i): Call { return call_with('str_split_i', [$x, $pattern, $i]); }
function str_pad(mixed $x, mixed $width, mixed ...$options): Call { return call_with('str_pad', [$x, $width, ...$options]); }
function str_trunc(mixed $x, mixed $width, mixed ...$options): Call { return call_with('str_trunc', [$x, $width, ...$options]); }
function str_to_title(mixed $x): Call { return call_with('str_to_title', [$x]); }
function str_to_upper(mixed $x): Call { return call_with('str_to_upper', [$x]); }
function str_to_lower(mixed $x): Call { return call_with('str_to_lower', [$x]); }
function str_squish(mixed $x): Call { return call_with('str_squish', [$x]); }

// lubridate
function ymd(mixed $x, mixed ...$options): Call { return call_with('ymd', [$x, ...$options]); }
function dmy(mixed $x, mixed ...$options): Call { return call_with('dmy', [$x, ...$options]); }
function mdy(mixed $x, mixed ...$options): Call { return call_with('mdy', [$x, ...$options]); }
function ymd_hms(mixed $x, mixed ...$options): Call { return call_with('ymd_hms', [$x, ...$options]); }
function dmy_hms(mixed $x, mixed ...$options): Call { return call_with('dmy_hms', [$x, ...$options]); }
function mdy_hms(mixed $x, mixed ...$options): Call { return call_with('mdy_hms', [$x, ...$options]); }
function parse_date_time(mixed $x, mixed $orders, mixed ...$options): Call { return call_with('parse_date_time', [$x, \is_array($orders) ? new Lit($orders) : $orders, ...$options]); }
function year(mixed $x): Call { return call_with('year', [$x]); }
function month(mixed $x): Call { return call_with('month', [$x]); }
function day(mixed $x): Call { return call_with('day', [$x]); }
function wday(mixed $x, mixed ...$options): Call { return call_with('wday', [$x, ...$options]); }
function yday(mixed $x): Call { return call_with('yday', [$x]); }
function quarter(mixed $x): Call { return call_with('quarter', [$x]); }
function hour(mixed $x): Call { return call_with('hour', [$x]); }
function minute(mixed $x): Call { return call_with('minute', [$x]); }
function second(mixed $x): Call { return call_with('second', [$x]); }
function floor_date(mixed $x, mixed $unit, mixed ...$options): Call { return call_with('floor_date', [$x, $unit, ...$options]); }
function ceiling_date(mixed $x, mixed $unit, mixed ...$options): Call { return call_with('ceiling_date', [$x, $unit, ...$options]); }
function round_date(mixed $x, mixed $unit, mixed ...$options): Call { return call_with('round_date', [$x, $unit, ...$options]); }
function seconds(mixed $n): Call { return call_with('seconds', [$n]); }
function minutes(mixed $n): Call { return call_with('minutes', [$n]); }
function hours(mixed $n): Call { return call_with('hours', [$n]); }
function days(mixed $n): Call { return call_with('days', [$n]); }
function weeks(mixed $n): Call { return call_with('weeks', [$n]); }
function months(mixed $n): Call { return call_with('months', [$n]); }
function years(mixed $n): Call { return call_with('years', [$n]); }
function difftime(mixed $x, mixed $y, mixed ...$options): Call { return call_with('difftime', [$x, $y, ...$options]); }
function with_tz(mixed $x, mixed $tz): Call { return call_with('with_tz', [$x, $tz]); }
function force_tz(mixed $x, mixed $tz): Call { return call_with('force_tz', [$x, $tz]); }
function today(mixed ...$options): Call { return call_with('today', $options); }
function now(mixed ...$options): Call { return call_with('now', $options); }
