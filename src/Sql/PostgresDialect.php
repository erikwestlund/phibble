<?php

declare(strict_types=1);

namespace Phibble\Sql;

final class PostgresDialect extends Dialect
{
    public function name(): string
    {
        return 'PostgreSQL';
    }

    public function castType(string $type): string
    {
        return ['int' => 'BIGINT', 'float' => 'DOUBLE PRECISION', 'string' => 'TEXT'][$type];
    }

    public function position(Fragment $haystack, Fragment $needle): Fragment
    {
        return Fragment::format('POSITION({} IN {})', [$needle, $haystack]);
    }

    public function nullSafeEquals(Fragment $a, Fragment $b): Fragment
    {
        return Fragment::format('({} IS NOT DISTINCT FROM {})', [$a, $b]);
    }

    public function supportsNullSafeFullJoin(): bool
    {
        // PostgreSQL only runs FULL JOIN on merge- or hash-joinable conditions.
        return false;
    }

    public function datePart(string $part, Fragment $x): Fragment
    {
        $field = ['year' => 'YEAR', 'month' => 'MONTH', 'day' => 'DAY', 'hour' => 'HOUR', 'minute' => 'MINUTE', 'second' => 'SECOND', 'yday' => 'DOY', 'dow' => 'DOW'][$part];

        return $part === 'second'
            ? Fragment::format("CAST(EXTRACT({$field} FROM CAST({} AS TIMESTAMP)) AS DOUBLE PRECISION)", [$x])
            : Fragment::format("CAST(EXTRACT({$field} FROM CAST({} AS TIMESTAMP)) AS INTEGER)", [$x]);
    }

    public function secondsBetween(Fragment $x, Fragment $y): Fragment
    {
        return Fragment::format('CAST(EXTRACT(EPOCH FROM (CAST({} AS TIMESTAMP) - CAST({} AS TIMESTAMP))) AS DOUBLE PRECISION)', [$x, $y]);
    }

    public function squish(Fragment $x): Fragment
    {
        return Fragment::format("BTRIM(REGEXP_REPLACE({}, '\\s+', ' ', 'g'))", [$x]);
    }

    public function noLimit(): string
    {
        return 'LIMIT ALL';
    }

    public function quantile(Fragment $x, float $p): Fragment
    {
        return Fragment::format("PERCENTILE_CONT({$p}) WITHIN GROUP (ORDER BY {})", [$x]);
    }
}
