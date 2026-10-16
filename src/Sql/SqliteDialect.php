<?php

declare(strict_types=1);

namespace Phibble\Sql;

final class SqliteDialect extends Dialect
{
    public function name(): string
    {
        return 'SQLite';
    }

    public function castType(string $type): string
    {
        return ['int' => 'INTEGER', 'float' => 'REAL', 'string' => 'TEXT'][$type];
    }

    public function position(Fragment $haystack, Fragment $needle): Fragment
    {
        return Fragment::format('INSTR({}, {})', [$haystack, $needle]);
    }

    public function length(Fragment $x): Fragment
    {
        return Fragment::format('LENGTH({})', [$x]);
    }

    public function nullSafeEquals(Fragment $a, Fragment $b): Fragment
    {
        return Fragment::format('({} IS {})', [$a, $b]);
    }

    public function datePart(string $part, Fragment $x): Fragment
    {
        return match ($part) {
            'second' => Fragment::format("CAST(strftime('%f', {}) AS REAL)", [$x]),
            'dow' => Fragment::format("CAST(strftime('%w', {}) AS INTEGER)", [$x]),
            default => Fragment::format('CAST(strftime(\''.['year' => '%Y', 'month' => '%m', 'day' => '%d', 'hour' => '%H', 'minute' => '%M', 'yday' => '%j'][$part].'\', {}) AS INTEGER)', [$x]),
        };
    }

    public function secondsBetween(Fragment $x, Fragment $y): Fragment
    {
        // unixepoch() is exact; julianday() loses precision in the microseconds.
        return Fragment::format("(unixepoch({}, 'subsec') - unixepoch({}, 'subsec'))", [$x, $y]);
    }

    public function unicodeCase(): bool
    {
        // SQLite's UPPER() and LOWER() only change ASCII letters.
        return false;
    }

    public function hasPad(): bool
    {
        return false;
    }

    public function noLimit(): string
    {
        return 'LIMIT -1';
    }

    public function sampleStat(string $fn, Fragment $x, \Closure $aggregate): Fragment
    {
        // SQLite has no STDDEV_SAMP; compute from sums.
        $count = $aggregate(Fragment::format('COUNT({})', [$x]));
        $sum = $aggregate(Fragment::format('SUM({})', [$x]));
        $squares = $aggregate(Fragment::format('SUM({} * {})', [$x, $x]));
        $var = Fragment::format(
            '(CASE WHEN {} < 2 THEN NULL ELSE ({} - {} * {} / {}) / ({} - 1) END)',
            [$count, $squares, $sum, $sum, $count, $count],
        );

        return $fn === 'sd' ? Fragment::format('SQRT({})', [$var]) : $var;
    }
}
