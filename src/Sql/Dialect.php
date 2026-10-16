<?php

declare(strict_types=1);

namespace Phibble\Sql;

use InvalidArgumentException;
use PDO;

/**
 * SQL differences between SQLite, PostgreSQL and MySQL.
 */
abstract class Dialect
{
    public static function for(PDO $pdo): self
    {
        return match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => new SqliteDialect(),
            'pgsql' => new PostgresDialect(),
            'mysql' => new MysqlDialect(),
            default => throw new InvalidArgumentException(
                'SqlTable supports SQLite, PostgreSQL and MySQL, not '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).'.'
            ),
        };
    }

    abstract public function name(): string;

    public function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /** CAST target for 'int', 'float' and 'string'. */
    abstract public function castType(string $type): string;

    /** @param list<Fragment> $parts */
    public function concat(array $parts): Fragment
    {
        return Fragment::format('('.implode(' || ', array_fill(0, count($parts), '{}')).')', $parts);
    }

    /** 1-based position of $needle in $haystack, 0 if absent. */
    abstract public function position(Fragment $haystack, Fragment $needle): Fragment;

    public function length(Fragment $x): Fragment
    {
        return Fragment::format('CHAR_LENGTH({})', [$x]);
    }

    /** Equality where NULL equals NULL. */
    abstract public function nullSafeEquals(Fragment $a, Fragment $b): Fragment;

    /** The LIMIT clause that means "no limit", for OFFSET-only queries. */
    abstract public function noLimit(): string;

    public function supportsFullJoin(): bool
    {
        return true;
    }

    /** Whether FULL JOIN accepts NULL-safe key equality. */
    public function supportsNullSafeFullJoin(): bool
    {
        return $this->supportsFullJoin();
    }

    /**
     * Sample standard deviation or variance. $aggregate wraps each aggregate
     * call (adding OVER (...) when used as a window).
     *
     * @param \Closure(Fragment): Fragment $aggregate
     */
    public function sampleStat(string $fn, Fragment $x, \Closure $aggregate): Fragment
    {
        return $aggregate(Fragment::format($fn === 'sd' ? 'STDDEV_SAMP({})' : 'VAR_SAMP({})', [$x]));
    }

    /** A continuous quantile aggregate (type 7), if the dialect has one. */
    public function quantile(Fragment $x, float $p): Fragment
    {
        throw new Untranslatable("quantile() and median() have no {$this->name()} translation.");
    }
}
