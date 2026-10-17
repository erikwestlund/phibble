<?php

declare(strict_types=1);

use Phibble\CalendarDate;
use Phibble\Expr\Clock;
use Phibble\Expr\Expr;
use Phibble\DataFrame;
use Phibble\Type;

use function Phibble\Expr\{ceiling_date, col, day, days, difftime, dmy, fixed, floor_date, force_tz, hour, hours, lit, mdy, minute, minutes, month, months, now, parse_date_time, quarter, regex, round_date, second, str_count, str_detect, str_ends, str_extract, str_extract_all, str_length, str_pad, str_replace, str_replace_all, str_split, str_split_i, str_squish, str_starts, str_to_title, str_to_upper, str_trunc, today, wday, weeks, with_tz, yday, year, years, ymd, ymd_hms};

/*
 * phibble's lubridate 1.9.5 / stringr 1.6.0 reference cases
 * (tests/fixtures/expr/lubridate_stringr.json), run on PolarsTable.
 */
function lsExpectedPt(string $case): array
{
    static $all = null;
    $all ??= json_decode(file_get_contents(__DIR__.'/../fixtures/expr/lubridate_stringr.json'), true);

    return $all[$case];
}

function lsFramePt(): DataFrame
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

function lsFormatPt(mixed $v): mixed
{
    return match (true) {
        $v === null => null,
        $v instanceof CalendarDate => $v->format('Y-m-d'),
        $v instanceof DateTimeInterface => $v->format('Y-m-d H:i:s O'),
        is_array($v) => array_map(lsFormatPt(...), $v),
        is_int($v) => (float) $v,
        is_float($v) => \round($v, 9),
        default => $v,
    };
}

function lsWithPt(array $values): DataFrame
{
    return DataFrame::fromColumns(['v' => $values]);
}

dataset('lubridate and stringr on Polars', function () {
    $d = col('d');
    $t = col('t');
    $s = col('s');
    $v = col('v');

    return [
        'ymd' => [fn () => lsWithPt(['2024-01-31', '20240229', '2023/12/31', null, 'bad']), ymd($v)],
        'ymd_hms' => [fn () => lsWithPt(['2024-01-31 23:59:59', '2024-02-29T10:00:00', '2023/12/31 12:00', null, 'bad']), ymd_hms($v)],
        'dmy' => [fn () => lsWithPt(['31/01/2024', '29-02-2024', '31.12.2023', null, '32/01/2024']), dmy($v)],
        'mdy' => [fn () => lsWithPt(['01/31/2024', '02-29-2024', '12.31.2023', null, '13/01/2024']), mdy($v)],
        'parse_dt' => [fn () => lsWithPt(['2024-01-31 23:59:59', '31/01/2024 10:00:00', '2023-12-31 12:00:00', null, 'x']), parse_date_time($v, ['Ymd HMS', 'dmY HMS'])],
        'year' => [lsFramePt(...), year($d)],
        'month' => [lsFramePt(...), month($d)],
        'day' => [lsFramePt(...), day($d)],
        'wday' => [lsFramePt(...), wday($d)],
        'wday_mon' => [lsFramePt(...), wday($d, week_start: 1)],
        'yday' => [lsFramePt(...), yday($d)],
        'quarter' => [lsFramePt(...), quarter($d)],
        'hour' => [lsFramePt(...), hour($t)],
        'minute' => [lsFramePt(...), minute($t)],
        'second' => [lsFramePt(...), second($t)],
        'year_t' => [lsFramePt(...), year($t)],
        'floor_hour' => [lsFramePt(...), floor_date($t, 'hour')],
        'floor_15min' => [lsFramePt(...), floor_date($t, '15 minutes')],
        'floor_week' => [lsFramePt(...), floor_date($t, 'week')],
        'floor_month_d' => [lsFramePt(...), floor_date($d, 'month')],
        'floor_quarter_d' => [lsFramePt(...), floor_date($d, 'quarter')],
        'floor_year_d' => [lsFramePt(...), floor_date($d, 'year')],
        'ceiling_month_d' => [lsFramePt(...), ceiling_date($d, 'month')],
        'ceiling_hour' => [lsFramePt(...), ceiling_date($t, 'hour')],
        'round_day' => [lsFramePt(...), round_date($t, 'day')],
        'round_month_d' => [lsFramePt(...), round_date($d, 'month')],
        'plus_days' => [lsFramePt(...), $d->add(days(10))],
        'plus_months' => [lsFramePt(...), $d->add(months(1))],
        'plus_years' => [lsFramePt(...), $d->add(years(1))],
        'minus_weeks' => [lsFramePt(...), $d->sub(weeks(2))],
        'plus_hours' => [lsFramePt(...), $t->add(hours(5))],
        'plus_minutes' => [lsFramePt(...), $t->add(minutes(90))],
        'diff_days' => [lsFramePt(...), $d->sub(ymd(lit('2024-01-01')))],
        'difftime_hours' => [lsFramePt(...), difftime($t, ymd_hms(lit('2024-01-01 00:00:00')), units: 'hours')],
        'difftime_weeks' => [lsFramePt(...), difftime($d, ymd(lit('2023-12-31')), units: 'weeks')],
        'with_tz' => [lsFramePt(...), with_tz($t, 'America/New_York')],
        'force_tz' => [lsFramePt(...), force_tz($t, 'America/New_York')],

        'str_detect' => [lsFramePt(...), str_detect($s, 'a.p')],
        'str_detect_fixed' => [lsFramePt(...), str_detect($s, fixed('.'))],
        'str_detect_ic' => [lsFramePt(...), str_detect($s, regex('apple', ignore_case: true))],
        'str_detect_negate' => [lsFramePt(...), str_detect($s, '^a', negate: true)],
        'str_starts' => [lsFramePt(...), str_starts($s, 'a|A')],
        'str_ends' => [lsFramePt(...), str_ends($s, 't|E')],
        'str_count' => [lsFramePt(...), str_count($s, 'a')],
        'str_count_fixed' => [lsFramePt(...), str_count($s, fixed('p'))],
        'str_count_class' => [lsFramePt(...), str_count($s, '[aeiou]')],
        'str_extract' => [lsFramePt(...), str_extract($s, '[a-z]+')],
        'str_extract_all' => [lsFramePt(...), str_extract_all($s, 'p+')],
        'str_split' => [lsFramePt(...), str_split($s, ' ')],
        'str_split_i' => [lsFramePt(...), str_split_i($s, ' ', 2)],
        'str_split_i_neg' => [lsFramePt(...), str_split_i($s, ' ', -1)],
        'str_replace' => [lsFramePt(...), str_replace($s, 'a', 'A')],
        'str_replace_all' => [lsFramePt(...), str_replace_all($s, '([aeiou])', '<\1>')],
        'str_replace_all_fixed' => [lsFramePt(...), str_replace_all($s, fixed('.'), '!')],
        'str_replace_na' => [lsFramePt(...), str_replace($s, 'pie', lit(null))],
        'str_pad' => [fn () => lsWithPt(['a', 'abc', null, 'abcdef', 'ab']), str_pad($v, 5)],
        'str_pad_right' => [fn () => lsWithPt(['a', 'abc', null, 'abcdef', 'ab']), str_pad($v, 5, side: 'right', pad: '*')],
        'str_pad_both' => [fn () => lsWithPt(['a', 'abc', null, 'abcdef', 'ab']), str_pad($v, 6, side: 'both', pad: '-')],
        'str_trunc' => [lsFramePt(...), str_trunc($s, 8)],
        'str_trunc_left' => [lsFramePt(...), str_trunc($s, 8, side: 'left')],
        'str_trunc_center' => [lsFramePt(...), str_trunc($s, 9, side: 'center', ellipsis: '~')],
        'str_to_title' => [fn () => lsWithPt(['hello world', "o'neil mcdonald", 'ALL CAPS', null, 'x-ray vision']), str_to_title($v)],
        'str_to_upper' => [lsFramePt(...), str_to_upper($s)],
        'str_squish' => [lsFramePt(...), str_squish($s)],
        'str_length' => [lsFramePt(...), str_length($s)],
    ];
});

it('matches lubridate and stringr on Polars', function (Closure $frame, Expr $expr) {
    $case = $this->dataName();
    $case = substr($case, strpos($case, '"') + 1, -1);
    // str_to_title needs ICU word breaks, so it runs on a DataFrame; the rest stay in Polars.
    $run = fn () => pt($frame())->mutate(['out' => $expr]);
    $out = ($case === 'str_to_title' ? $run() : native($run))->column('out');

    expect(lsFormatPt($out))->toBe(lsFormatPt(lsExpectedPt($case)));
})->with('lubridate and stringr on Polars');

it('keeps dates as dates through Polars expressions', function () {
    $out = pt(lsFramePt())->mutate(['d2' => col('d'), 'next' => col('d')->add(days(1)), 'm' => floor_date(col('d'), 'month')]);

    expect($out->types()['d2'])->toBe(Type::Date)
        ->and($out->types()['next'])->toBe(Type::Date)
        ->and($out->types()['m'])->toBe(Type::Date)
        ->and(pt(lsFramePt())->mutate(['h' => floor_date(col('t'), 'hour')])->types()['h'])->toBe(Type::DateTime);
});

it('reads the clock through today() and now() in Polars', function () {
    Clock::freeze(new DateTimeImmutable('2024-05-06 23:30:00', new DateTimeZone('UTC')));
    try {
        $out = native(fn () => pt(DataFrame::fromColumns(['x' => [1]]))->mutate(['d' => today(), 'nz' => today(tz: 'Pacific/Auckland'), 'n' => now()]));
        expect($out->column('d')[0]->format('Y-m-d'))->toBe('2024-05-06')
            ->and($out->column('nz')[0]->format('Y-m-d'))->toBe('2024-05-07')
            ->and($out->types()['d'])->toBe(Type::Date)
            ->and($out->column('n')[0]->format('Y-m-d H:i:s'))->toBe('2024-05-06 23:30:00');
    } finally {
        Clock::freeze(null);
    }
});

it('rejects invalid regular expressions clearly in Polars', function () {
    pt(lsFramePt())->mutate(['x' => str_detect(col('s'), '(')]);
})->throws(InvalidArgumentException::class, 'Invalid regular expression');
