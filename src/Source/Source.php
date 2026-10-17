<?php

declare(strict_types=1);

namespace Phibble\Source;

use Phibble\DataFrame;

/**
 * Something that can produce a frame: a file, a query, an API.
 */
interface Source
{
    public function read(): DataFrame;
}
