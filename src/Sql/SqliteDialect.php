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

    public function sampleStat(string $fn, Fragment $x): Fragment
    {
        // SQLite has no STDDEV_SAMP; compute from sums.
        $var = Fragment::format(
            '(CASE WHEN COUNT({}) < 2 THEN NULL ELSE (SUM({} * {}) - SUM({}) * SUM({}) / COUNT({})) / (COUNT({}) - 1) END)',
            [$x, $x, $x, $x, $x, $x, $x],
        );

        return $fn === 'sd' ? Fragment::format('SQRT({})', [$var]) : $var;
    }
}
