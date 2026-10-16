<?php

declare(strict_types=1);

namespace Phibble\Format;

/**
 * The formatted body of one column, as pillar's pillar_shaft.
 *
 * @internal
 */
interface Shaft
{
    /** Natural width. */
    public function width(): int;

    /** Narrowest width the column can be squeezed to. */
    public function minWidth(): int;

    public function align(): string;

    /** @return list<string> One cell per row, formatted for $width. */
    public function format(int $width): array;
}
