<?php

declare(strict_types=1);

namespace Phibble\Column;

use Phibble\Type;

/** A column backed by a plain PHP list. Values must already match the type. */
final readonly class ArrayColumn implements Column
{
    /** @param list<mixed> $values */
    public function __construct(
        private Type $type,
        private array $values,
    ) {}

    public function type(): Type
    {
        return $this->type;
    }

    public function values(): array
    {
        return $this->values;
    }

    public function get(int $index): mixed
    {
        return $this->values[$index];
    }

    public function take(array $indices): static
    {
        $values = [];
        foreach ($indices as $i) {
            $values[] = $i === null ? null : $this->values[$i];
        }

        return new self($this->type, $values);
    }

    public function count(): int
    {
        return count($this->values);
    }
}
