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
