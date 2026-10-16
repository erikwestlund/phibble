<?php

declare(strict_types=1);

namespace Illuminate\Support;

// A stand-in with the part of Laravel's Collection that phibble touches,
// declared only when Laravel itself isn't installed.
if (! class_exists(Collection::class)) {
    final class Collection
    {
        /** @param array<int, mixed> $items */
        public function __construct(private array $items = []) {}

        /** @return array<int, mixed> */
        public function all(): array
        {
            return $this->items;
        }
    }
}
