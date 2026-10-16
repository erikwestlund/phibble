<?php

declare(strict_types=1);

namespace Phibble\Bench;

use Phibble\Contracts\Table;
use Phibble\Frame;

final class FrameBackend implements Backend
{
    public function name(): string
    {
        return 'frame';
    }

    public function load(string $path): Table
    {
        return Frame::fromCsv($path);
    }
}
