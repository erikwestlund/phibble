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

    public function special(string $which): string
    {
        // SQLite stores infinities; NaN becomes NULL.
        return ['inf' => '9e999', '-inf' => '-9e999', 'nan' => 'NULL'][$which];
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
        // SQLite has no STDDEV_SAMP, and the sum-of-squares formula loses
        // precision badly for large values; compute it in memory instead.
        throw new Untranslatable('sd() and var() are evaluated in memory on SQLite.');
    }
}
