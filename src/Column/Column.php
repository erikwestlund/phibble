<?php

declare(strict_types=1);

namespace Phibble\Column;

use Countable;
use Phibble\Type;

/**
 * Column storage. DataFrame only talks to columns through this interface, so
 * another backend (for example phpolars) can supply its own storage.
 *
 * @internal The interface may change before 1.0.
 */
interface Column extends Countable
{
    public function type(): Type;

    /** @return list<mixed> Values with NA as null; factors as labels. */
    public function values(): array;

    public function get(int $index): mixed;

    /** @param list<int|null> $indices Rows to keep, in this order; null gives an NA row. */
    public function take(array $indices): static;
}
