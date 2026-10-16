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
        return '`'.str_replace('`', '``', $identifier).'`';
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

    public function supportsFullJoin(): bool
    {
        return false;
    }
}
