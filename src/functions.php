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
