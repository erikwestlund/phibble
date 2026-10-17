<?php

declare(strict_types=1);

use Phibble\CalendarDate;
use Phibble\Expr\Clock;
use Phibble\Expr\Expr;
use Phibble\DataFrame;
use Phibble\Type;

use function Phibble\Expr\{ceiling_date, col, day, days, difftime, dmy, fixed, floor_date, force_tz, hour, hours, lit, mdy, minute, minutes, month, months, now, parse_date_time, quarter, regex, round_date, second, str_count, str_detect, str_ends, str_extract, str_extract_all, str_length, str_pad, str_replace, str_replace_all, str_split, str_split_i, str_squish, str_starts, str_to_title, str_to_upper, str_trunc, today, wday, weeks, with_tz, yday, year, years, ymd, ymd_hms};

dataset('lubridate and stringr', fn () => lubridateStringrCases());

it('matches lubridate and stringr', function (Closure $frame, Expr $expr) {
    $case = $this->dataName();
    $case = substr($case, strpos($case, '"') + 1, -1);
    [$out] = quietly(fn () => $frame()->mutate(['out' => $expr])->column('out'));

    expect(lsFormat($out))->toBe(lsFormat(lsExpected($case)));
})->with('lubridate and stringr');

it('keeps dates as dates through expressions', function () {
    $out = lsFrame()->mutate(['d2' => col('d'), 'next' => col('d')->add(days(1)), 'm' => floor_date(col('d'), 'month')]);

    expect($out->type('d2'))->toBe(Type::Date)
        ->and($out->type('next'))->toBe(Type::Date)
        ->and($out->type('m'))->toBe(Type::Date)
        ->and(lsFrame()->mutate(['h' => floor_date(col('t'), 'hour')])->type('h'))->toBe(Type::DateTime);
});

it('reads the clock through today() and now()', function () {
    Clock::freeze(new DateTimeImmutable('2024-05-06 23:30:00', new DateTimeZone('UTC')));
    try {
        $out = DataFrame::fromColumns(['x' => [1]])->mutate(['d' => today(), 'nz' => today(tz: 'Pacific/Auckland'), 'n' => now()]);
        expect($out->column('d')[0]->format('Y-m-d'))->toBe('2024-05-06')
            ->and($out->column('nz')[0]->format('Y-m-d'))->toBe('2024-05-07')
            ->and($out->type('d'))->toBe(Type::Date)
            ->and($out->column('n')[0]->format('Y-m-d H:i:s'))->toBe('2024-05-06 23:30:00');
    } finally {
        Clock::freeze(null);
    }
});

it('rejects invalid regular expressions clearly', function () {
    lsFrame()->mutate(['x' => str_detect(col('s'), '(')]);
})->throws(InvalidArgumentException::class, 'Invalid regular expression');
