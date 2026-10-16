<?php

declare(strict_types=1);

namespace Phibble\Sql;

final class MysqlDialect extends Dialect
{
    public function name(): string
    {
        return 'MySQL';
    }

    public function quote(string $identifier): string
    {
        return '`'.str_replace(['`', '{}'], ['``', self::BRACES], $identifier).'`';
    }

    public function truncateToInt(Fragment $x): Fragment
    {
        return Fragment::format('CAST(TRUNCATE({}, 0) AS SIGNED)', [$x]);
    }

    public function castType(string $type): string
    {
        return ['int' => 'SIGNED', 'float' => 'DOUBLE', 'string' => 'CHAR'][$type];
    }

    public function concat(array $parts): Fragment
    {
        return Fragment::format('CONCAT('.implode(', ', array_fill(0, count($parts), '{}')).')', $parts);
    }

    public function position(Fragment $haystack, Fragment $needle): Fragment
    {
        return Fragment::format('LOCATE({}, {})', [$needle, $haystack]);
    }

    public function nullSafeEquals(Fragment $a, Fragment $b): Fragment
    {
        return Fragment::format('({} <=> {})', [$a, $b]);
    }

    public function noLimit(): string
    {
        return 'LIMIT 18446744073709551615';
    }

    public function collate(Fragment $x): Fragment
    {
        // The default collation is case-insensitive. utf8mb4_0900_bin compares code
        // points and, unlike utf8mb4_bin, does not ignore trailing spaces.
        return Fragment::format('(CONVERT({} USING utf8mb4) COLLATE utf8mb4_0900_bin)', [$x]);
    }

    public function isTextColumn(array $meta): bool
    {
        return in_array($meta['native_type'] ?? '', ['VAR_STRING', 'STRING', 'BLOB'], true);
    }

    public function datePart(string $part, Fragment $x): Fragment
    {
        return match ($part) {
            'second' => Fragment::format('(SECOND({}) + CAST(MICROSECOND({}) AS DOUBLE) / 1000000)', [$x, $x]),
            'dow' => Fragment::format('(DAYOFWEEK({}) - 1)', [$x]),
            default => Fragment::format(['year' => 'YEAR', 'month' => 'MONTH', 'day' => 'DAY', 'hour' => 'HOUR', 'minute' => 'MINUTE', 'yday' => 'DAYOFYEAR'][$part].'({})', [$x]),
        };
    }

    public function secondsBetween(Fragment $x, Fragment $y): Fragment
    {
        // DOUBLE first: MySQL's decimal division keeps only a few decimals.
        return Fragment::format('(CAST(TIMESTAMPDIFF(MICROSECOND, {}, {}) AS DOUBLE) / 1000000)', [$y, $x]);
    }

    public function squish(Fragment $x): Fragment
    {
        return Fragment::format("TRIM(REGEXP_REPLACE({}, '[[:space:]]+', ' '))", [$x]);
    }

    /** MySQL 8 uses ICU regular expressions, as stringr does. */
    public function regexMatch(Fragment $x, string $regex, bool $ignoreCase): Fragment
    {
        return Fragment::format('REGEXP_LIKE({}, {}, {})', [$x, Fragment::param($regex), Fragment::param($ignoreCase ? 'i' : 'c')]);
    }

    public function supportsFullJoin(): bool
    {
        return false;
    }
}
