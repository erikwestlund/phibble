<?php

declare(strict_types=1);

/*
 * tibble-style constructors. Each is a thin wrapper over a Frame method:
 *
 *     $df = tibble(x: [1, 2, 3], y: fn ($t) => array_map(fn ($x) => $x * 2, $t['x']));
 */

namespace Phibble;

/** tibble(): columns as named arguments. See Frame::tibble(). */
function tibble(mixed ...$columns): Frame
{
    return Frame::tibble($columns);
}

/** tribble(): column names, then rows. See Frame::tribble(). */
function tribble(array $names, mixed ...$cells): Frame
{
    return Frame::tribble($names, ...$cells);
}

/** as_tibble(): see Frame::asTibble(). */
function as_tibble(mixed $data, string $nameRepair = 'check_unique'): Frame
{
    return Frame::asTibble($data, $nameRepair);
}

/** enframe(): see Frame::enframe(). */
function enframe(array $values, ?string $name = 'name', string $value = 'value'): Frame
{
    return Frame::enframe($values, $name, $value);
}

/** deframe(): see Frame::deframe(). */
function deframe(Frame $frame): array
{
    return $frame->deframe();
}

/**
 * tbl(): a lazy SQL table, as in dbplyr. Pass a table name, or a SELECT
 * query with bindings.
 *
 *     tbl($pdo, 'visits');
 *     tbl($pdo, 'SELECT * FROM visits WHERE year = ?', [2024]);
 */
function tbl(\PDO $pdo, string $from, array $bindings = []): Sql\SqlTable
{
    return preg_match('/^\s*(SELECT|WITH)\b/i', $from)
        ? Sql\SqlTable::query($pdo, $from, $bindings)
        : Sql\SqlTable::table($pdo, $from);
}

/**
 * polars(): a table backed by Polars (requires the php_polars extension).
 * Takes any Table, or anything as_tibble() accepts.
 *
 *     polars(Frame::fromCsv('sales.csv'))->filter(col('units')->gt(0));
 */
function polars(mixed $data): Polars\PolarsTable
{
    if (! extension_loaded('php_polars')) {
        throw new \RuntimeException('polars() needs the php_polars extension (erikwestlund/phpolars).');
    }

    return Polars\PolarsTable::fromTable($data instanceof Contracts\Table ? $data : Frame::asTibble($data));
}
