<?php

declare(strict_types=1);

namespace Phibble\Exception;

use InvalidArgumentException;

final class ColumnNotFound extends InvalidArgumentException
{
    /** @param list<string> $available */
    public static function named(string $name, array $available): self
    {
        $list = $available === [] ? 'none' : implode(', ', $available);

        return new self("Column '{$name}' not found. Available columns: {$list}.");
    }
}
