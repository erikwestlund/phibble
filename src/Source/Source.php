<?php

declare(strict_types=1);

namespace Phibble\Source;

use Phibble\Frame;

/**
 * Something that can produce a frame: a file, a query, an API.
 */
interface Source
{
    public function read(): Frame;
}
