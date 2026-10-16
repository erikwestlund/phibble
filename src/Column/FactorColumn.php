<?php

declare(strict_types=1);

namespace Phibble\Column;

use InvalidArgumentException;
use Phibble\Type;

/**
 * A categorical column: an ordered list of level labels plus one code per
 * row. Codes are 0-based indexes into the levels; NA is a null code.
 */
final readonly class FactorColumn implements Column
{
    /**
     * @param list<string> $levels
     * @param list<?int> $codes
     */
    public function __construct(
        private array $levels,
        private array $codes,
    ) {}

    /**
     * Build a factor from raw values. Without explicit levels, the levels
     * are the sorted unique values (numbers sort numerically, as in R).
     * Values missing from explicit levels become NA.
     *
     * @param list<mixed> $values
     * @param list<string>|null $levels
     */
    public static function fromValues(array $values, ?array $levels = null): self
    {
        $levels ??= self::defaultLevels($values);
        $levels = array_map(strval(...), $levels);
        if (count(array_unique($levels)) !== count($levels)) {
            throw new InvalidArgumentException('Factor levels must be unique.');
        }

        $lookup = array_flip($levels);
        $codes = [];
        foreach ($values as $value) {
            $label = self::label($value);
            $codes[] = $label === null ? null : ($lookup[$label] ?? null);
        }

        return new self($levels, $codes);
    }

    /** @return list<string> */
    public function levels(): array
    {
        return $this->levels;
    }

    /** @return list<?int> */
    public function codes(): array
    {
        return $this->codes;
    }

    public function type(): Type
    {
        return Type::Factor;
    }

    public function values(): array
    {
        return array_map(fn (?int $code) => $code === null ? null : $this->levels[$code], $this->codes);
    }

    public function get(int $index): mixed
    {
        $code = $this->codes[$index];

        return $code === null ? null : $this->levels[$code];
    }

    public function take(array $indices): static
    {
        $codes = [];
        foreach ($indices as $i) {
            $codes[] = $this->codes[$i];
        }

        return new self($this->levels, $codes);
    }

    public function count(): int
    {
        return count($this->codes);
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private static function defaultLevels(array $values): array
    {
        $present = array_values(array_filter($values, fn ($v) => $v !== null));
        $numeric = $present !== [] && array_all($present, fn ($v) => is_int($v) || is_float($v));

        $unique = [];
        foreach ($present as $value) {
            $unique[self::label($value)] = $value;
        }
        $unique = array_values($unique);
        $numeric ? sort($unique) : sort($unique, SORT_STRING);

        return array_map(fn ($v) => self::label($v), $unique);
    }

    private static function label(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof \BackedEnum => (string) $value->value,
            default => (string) $value,
        };
    }
}
