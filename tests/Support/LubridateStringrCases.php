<?php

declare(strict_types=1);

use Phibble\CalendarDate;
use Phibble\Expr\Expr;
use Phibble\DataFrame;

use function Phibble\Expr\{ceiling_date, col, day, days, difftime, dmy, fixed, floor_date, force_tz, hour, hours, lit, mdy, minute, minutes, month, months, now, parse_date_time, quarter, regex, round_date, second, str_count, str_detect, str_ends, str_extract, str_extract_all, str_length, str_pad, str_replace, str_replace_all, str_split, str_split_i, str_squish, str_starts, str_to_title, str_to_upper, str_trunc, today, wday, weeks, with_tz, yday, year, years, ymd, ymd_hms};

/*
 * Expected values are lubridate 1.9.5 and stringr 1.6.0 output for the same
 * inputs, written by tests/r/lubridate_stringr.R.
 */
function lsExpected(string $case): array
{
    static $all = null;
    $all ??= json_decode(file_get_contents(__DIR__.'/../fixtures/expr/lubridate_stringr.json'), true);

    return $all[$case];
}

function lsFrame(): DataFrame
{
    return DataFrame::fromCsvString(implode("\n", [
        'd,t,s',
        '2024-01-31,2024-01-31 23:59:59,Apple pie',
        '2024-02-29,2024-03-10 02:30:00,banana split',
        '2023-12-31,2023-12-31 12:00:00,NA',
        'NA,NA,"  cherry   tart  "',
        '2024-07-04,2024-07-04 08:15:30,apple.APPLE',
    ]), types: 'DTc', trimWs: false);
}

/** A value as the R script wrote it. */
function lsFormat(mixed $v): mixed
{
    return match (true) {
        $v === null => null,
        $v instanceof CalendarDate => $v->format('Y-m-d'),
        $v instanceof DateTimeInterface => $v->format('Y-m-d H:i:s O'),
        is_array($v) => array_map(lsFormat(...), $v),
        is_int($v) => (float) $v,
        is_float($v) => \round($v, 9),
        default => $v,
    };
}

function lsWith(array $values): DataFrame
{
    return DataFrame::fromColumns(['v' => $values]);
}

function lubridateStringrCases(): array
{
    $d = col('d');
    $t = col('t');
    $s = col('s');
    $v = col('v');

    return [
        'ymd' => [fn () => lsWith(['2024-01-31', '20240229', '2023/12/31', null, 'bad']), ymd($v)],
        'ymd_hms' => [fn () => lsWith(['2024-01-31 23:59:59', '2024-02-29T10:00:00', '2023/12/31 12:00', null, 'bad']), ymd_hms($v)],
        'dmy' => [fn () => lsWith(['31/01/2024', '29-02-2024', '31.12.2023', null, '32/01/2024']), dmy($v)],
        'mdy' => [fn () => lsWith(['01/31/2024', '02-29-2024', '12.31.2023', null, '13/01/2024']), mdy($v)],
        'parse_dt' => [fn () => lsWith(['2024-01-31 23:59:59', '31/01/2024 10:00:00', '2023-12-31 12:00:00', null, 'x']), parse_date_time($v, ['Ymd HMS', 'dmY HMS'])],
        'year' => [lsFrame(...), year($d)],
        'month' => [lsFrame(...), month($d)],
        'day' => [lsFrame(...), day($d)],
        'wday' => [lsFrame(...), wday($d)],
        'wday_mon' => [lsFrame(...), wday($d, week_start: 1)],
        'yday' => [lsFrame(...), yday($d)],
        'quarter' => [lsFrame(...), quarter($d)],
        'hour' => [lsFrame(...), hour($t)],
        'minute' => [lsFrame(...), minute($t)],
        'second' => [lsFrame(...), second($t)],
        'year_t' => [lsFrame(...), year($t)],
        'floor_hour' => [lsFrame(...), floor_date($t, 'hour')],
        'floor_15min' => [lsFrame(...), floor_date($t, '15 minutes')],
        'floor_week' => [lsFrame(...), floor_date($t, 'week')],
        'floor_month_d' => [lsFrame(...), floor_date($d, 'month')],
        'floor_quarter_d' => [lsFrame(...), floor_date($d, 'quarter')],
        'floor_year_d' => [lsFrame(...), floor_date($d, 'year')],
        'ceiling_month_d' => [lsFrame(...), ceiling_date($d, 'month')],
        'ceiling_hour' => [lsFrame(...), ceiling_date($t, 'hour')],
        'round_day' => [lsFrame(...), round_date($t, 'day')],
        'round_month_d' => [lsFrame(...), round_date($d, 'month')],
        'plus_days' => [lsFrame(...), $d->add(days(10))],
        'plus_months' => [lsFrame(...), $d->add(months(1))],
        'plus_years' => [lsFrame(...), $d->add(years(1))],
        'minus_weeks' => [lsFrame(...), $d->sub(weeks(2))],
        'plus_hours' => [lsFrame(...), $t->add(hours(5))],
        'plus_minutes' => [lsFrame(...), $t->add(minutes(90))],
        'diff_days' => [lsFrame(...), $d->sub(ymd(lit('2024-01-01')))],
        'difftime_hours' => [lsFrame(...), difftime($t, ymd_hms(lit('2024-01-01 00:00:00')), units: 'hours')],
        'difftime_weeks' => [lsFrame(...), difftime($d, ymd(lit('2023-12-31')), units: 'weeks')],
        'with_tz' => [lsFrame(...), with_tz($t, 'America/New_York')],
        'force_tz' => [lsFrame(...), force_tz($t, 'America/New_York')],

        'str_detect' => [lsFrame(...), str_detect($s, 'a.p')],
        'str_detect_fixed' => [lsFrame(...), str_detect($s, fixed('.'))],
        'str_detect_ic' => [lsFrame(...), str_detect($s, regex('apple', ignore_case: true))],
        'str_detect_negate' => [lsFrame(...), str_detect($s, '^a', negate: true)],
        'str_starts' => [lsFrame(...), str_starts($s, 'a|A')],
        'str_ends' => [lsFrame(...), str_ends($s, 't|E')],
        'str_count' => [lsFrame(...), str_count($s, 'a')],
        'str_count_fixed' => [lsFrame(...), str_count($s, fixed('p'))],
        'str_count_class' => [lsFrame(...), str_count($s, '[aeiou]')],
        'str_extract' => [lsFrame(...), str_extract($s, '[a-z]+')],
        'str_extract_all' => [lsFrame(...), str_extract_all($s, 'p+')],
        'str_split' => [lsFrame(...), str_split($s, ' ')],
        'str_split_i' => [lsFrame(...), str_split_i($s, ' ', 2)],
        'str_split_i_neg' => [lsFrame(...), str_split_i($s, ' ', -1)],
        'str_replace' => [lsFrame(...), str_replace($s, 'a', 'A')],
        'str_replace_all' => [lsFrame(...), str_replace_all($s, '([aeiou])', '<\1>')],
        'str_replace_all_fixed' => [lsFrame(...), str_replace_all($s, fixed('.'), '!')],
        'str_replace_na' => [lsFrame(...), str_replace($s, 'pie', lit(null))],
        'str_pad' => [fn () => lsWith(['a', 'abc', null, 'abcdef', 'ab']), str_pad($v, 5)],
        'str_pad_right' => [fn () => lsWith(['a', 'abc', null, 'abcdef', 'ab']), str_pad($v, 5, side: 'right', pad: '*')],
        'str_pad_both' => [fn () => lsWith(['a', 'abc', null, 'abcdef', 'ab']), str_pad($v, 6, side: 'both', pad: '-')],
        'str_trunc' => [lsFrame(...), str_trunc($s, 8)],
        'str_trunc_left' => [lsFrame(...), str_trunc($s, 8, side: 'left')],
        'str_trunc_center' => [lsFrame(...), str_trunc($s, 9, side: 'center', ellipsis: '~')],
        'str_to_title' => [fn () => lsWith(['hello world', "o'neil mcdonald", 'ALL CAPS', null, 'x-ray vision']), str_to_title($v)],
        'str_to_upper' => [lsFrame(...), str_to_upper($s)],
        'str_squish' => [lsFrame(...), str_squish($s)],
        'str_length' => [lsFrame(...), str_length($s)],
    ];
}

