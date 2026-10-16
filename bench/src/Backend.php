<?php

declare(strict_types=1);

namespace Phibble\Bench;

use Phibble\Contracts\Table;

/** A table implementation under benchmark. Other packages add their own. */
interface Backend
{
    public function name(): string;

    /** Load a CSV file from bench/data into this backend (not timed). */
    public function load(string $path): Table;
}
