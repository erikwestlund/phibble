<?php

declare(strict_types=1);

use Phibble\DataFrame;
use Phibble\Sql\SqlTable;
use Phibble\Type;

/*
 * SqlTable runs against SQLite always. To also run against PostgreSQL and
 * MySQL, set PHIBBLE_TEST_PGSQL / PHIBBLE_TEST_MYSQL to a PDO DSN (and
 * PHIBBLE_TEST_*_USER / _PASSWORD). The tests only run SELECTs over inline
 * data; they never create tables.
 */
function sqlConnections(): array
{
    $connections = ['sqlite' => fn () => new PDO('sqlite::memory:')];
    foreach (['pgsql' => 'PHIBBLE_TEST_PGSQL', 'mysql' => 'PHIBBLE_TEST_MYSQL'] as $driver => $env) {
        $dsn = getenv($env);
        if ($dsn) {
            $connections[$driver] = fn () => new PDO($dsn, getenv($env.'_USER') ?: null, getenv($env.'_PASSWORD') ?: null);
        }
    }

    return $connections;
}

/** A lazy table holding the frame's rows as inline SELECT ... UNION ALL data. */
function sqlFrom(PDO $pdo, DataFrame $frame): SqlTable
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $castFor = fn (Type $type) => match ($type) {
        Type::Int => ['sqlite' => 'INTEGER', 'pgsql' => 'BIGINT', 'mysql' => 'SIGNED'][$driver],
        Type::Float => ['sqlite' => 'REAL', 'pgsql' => 'DOUBLE PRECISION', 'mysql' => 'DOUBLE'][$driver],
        Type::Bool => ['sqlite' => 'INTEGER', 'pgsql' => 'BOOLEAN', 'mysql' => 'SIGNED'][$driver],
        Type::Date => ['sqlite' => 'TEXT', 'pgsql' => 'DATE', 'mysql' => 'DATE'][$driver],
        Type::DateTime => ['sqlite' => 'TEXT', 'pgsql' => 'TIMESTAMP', 'mysql' => 'DATETIME'][$driver],
        default => ['sqlite' => 'TEXT', 'pgsql' => 'TEXT', 'mysql' => 'CHAR'][$driver],
    };
    $quote = fn (string $n) => $driver === 'mysql' ? "`{$n}`" : "\"{$n}\"";
    $selects = [];
    $bindings = [];
    foreach ($frame->toRows() as $i => $row) {
        $items = [];
        foreach ($row as $name => $value) {
            $type = $frame->type($name);
            // Text gets a locale collation (as on a typical Linux server), so the
            // tests catch comparisons that need byte order to match R.
            $collate = $type === Type::String && $driver === 'pgsql' ? ' COLLATE "en-x-icu"' : '';
            $items[] = 'CAST(? AS '.$castFor($type).')'.$collate.($i === 0 ? ' AS '.$quote($name) : '');
            $bindings[] = match (true) {
                $value instanceof DateTimeInterface => $value->format($type === Type::Date ? 'Y-m-d' : 'Y-m-d H:i:s'),
                $type === Type::Bool && $value !== null && $driver !== 'pgsql' => (int) $value,
                default => $value,
            };
        }
        $selects[] = 'SELECT '.implode(', ', $items);
    }

    return SqlTable::query($pdo, implode(' UNION ALL ', $selects), $bindings);
}
