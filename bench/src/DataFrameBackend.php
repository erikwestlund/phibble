<?php

declare(strict_types=1);

namespace Phibble\Bench;

use Phibble\Contracts\Table;
use Phibble\DataFrame;

final class DataFrameBackend implements Backend
{
    public function name(): string
    {
        return 'frame';
    }

    public function load(string $path): Table
    {
        return DataFrame::fromCsv($path);
    }
}
