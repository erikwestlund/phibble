<?php

declare(strict_types=1);

namespace Phibble\Column;

use BackedEnum;
use Closure;
use DateTimeInterface;
use InvalidArgumentException;
use Phibble\Type;

/**
 * A categorical column: an ordered list of level labels plus one code per
 * row. Codes are 0-based indexes into the levels; NA is a null code.
 * Ordered factors (R's ordered()) compare by level position.
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
        private bool $ordered = false,
    ) {
        if (count(array_unique($levels)) !== count($levels)) {
            throw new InvalidArgumentException('Factor levels must be unique.');
        }
    }

    /**
     * Build a factor from raw values, as R's factor(). Without explicit
     * levels, the levels are the sorted unique values (numbers sort
     * numerically). Values missing from explicit levels become NA.
     *
     * @param list<mixed> $values
     * @param list<string|int|float>|null $levels
     */
    public static function fromValues(array $values, ?array $levels = null, bool $ordered = false): self
    {
        $levels = array_map(fn ($l) => (string) self::label($l), $levels ?? self::sortedLevels($values));
        $lookup = array_flip($levels);
        $codes = [];
        foreach ($values as $value) {
            $label = self::label($value);
            $codes[] = $label === null ? null : ($lookup[$label] ?? null);
        }

        return new self($levels, $codes, $ordered);
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

    public function isOrdered(): bool
    {
        return $this->ordered;
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
            $codes[] = $i === null ? null : $this->codes[$i];
        }

        return new self($this->levels, $codes, $this->ordered);
    }

    public function count(): int
    {
        return count($this->codes);
    }

    // ---------------------------------------------------------------
    // forcats basics. Each returns a new column with the same values
    // (except recode/collapse, which rename them) and new levels.
    // ---------------------------------------------------------------

    /**
     * Use these levels, in this order. Values whose level is not listed
     * become NA, as in factor(x, levels = ...).
     *
     * @param list<string> $levels
     */
    public function withLevels(array $levels): self
    {
        return self::fromValues($this->values(), $levels, $this->ordered);
    }

    /**
     * fct_relevel(): move the given levels to the front, or to $after
     * (0-based position to insert after; -1 means the end).
     *
     * @param list<string> $first
     */
    public function relevel(array $first, int $after = 0): self
    {
        foreach ($first as $level) {
            if (! in_array($level, $this->levels, true)) {
                throw new InvalidArgumentException("Unknown level '{$level}'.");
            }
        }
        $rest = array_values(array_diff($this->levels, $first));
        $position = $after === -1 ? count($rest) : $after;
        array_splice($rest, $position, 0, $first);

        return $this->withLevels($rest);
    }

    /** fct_rev(): reverse the level order. */
    public function reverse(): self
    {
        return $this->withLevels(array_reverse($this->levels));
    }

    /** fct_inorder(): levels in order of first appearance. */
    public function inOrder(): self
    {
        $seen = [];
        foreach ($this->codes as $code) {
            if ($code !== null) {
                $seen[$code] = true;
            }
        }
        $levels = array_map(fn (int $code) => $this->levels[$code], array_keys($seen));
        // Unused levels go last, in their current order.
        $levels = [...$levels, ...array_values(array_diff($this->levels, $levels))];

        return $this->withLevels($levels);
    }

    /** fct_infreq(): levels by frequency, most common first; ties keep level order. */
    public function inFrequency(): self
    {
        $counts = array_fill(0, count($this->levels), 0);
        foreach ($this->codes as $code) {
            if ($code !== null) {
                $counts[$code]++;
            }
        }
        $order = array_keys($this->levels);
        usort($order, fn (int $a, int $b) => $counts[$b] <=> $counts[$a] ?: $a <=> $b);

        return $this->withLevels(array_map(fn (int $i) => $this->levels[$i], $order));
    }

    /**
     * fct_reorder(): order levels by a summary of another vector, by
     * default its median. NA groups go last.
     *
     * @param list<mixed> $by One value per row.
     * @param Closure(list<mixed>): mixed|null $summary
     */
    public function reorder(array $by, ?Closure $summary = null, bool $descending = false): self
    {
        $summary ??= static function (array $xs): ?float {
            $xs = array_values(array_filter($xs, fn ($x) => $x !== null));
            if ($xs === []) {
                return null;
            }
            sort($xs);
            $n = count($xs);

            return $n % 2 === 1 ? (float) $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
        };

        $byLevel = array_fill(0, count($this->levels), []);
        foreach ($this->codes as $i => $code) {
            if ($code !== null) {
                $byLevel[$code][] = $by[$i];
            }
        }
        $keys = array_map($summary, $byLevel);
        $order = array_keys($this->levels);
        usort($order, function (int $a, int $b) use ($keys, $descending): int {
            [$x, $y] = [$keys[$a], $keys[$b]];
            if ($x === null || $y === null) {
                return ($x === null) <=> ($y === null) ?: $a <=> $b;
            }

            return ($descending ? $y <=> $x : $x <=> $y) ?: $a <=> $b;
        });

        return $this->withLevels(array_map(fn (int $i) => $this->levels[$i], $order));
    }

    /**
     * fct_recode(): rename levels, new => old. Renaming two levels to the
     * same name merges them.
     *
     * @param array<string, string> $map
     */
    public function recode(array $map): self
    {
        $rename = [];
        foreach ($map as $new => $old) {
            if (! in_array($old, $this->levels, true)) {
                throw new InvalidArgumentException("Unknown level '{$old}'.");
            }
            $rename[$old] = (string) $new;
        }

        return $this->mapLevels(fn (string $level) => $rename[$level] ?? $level);
    }

    /**
     * fct_collapse(): merge groups of levels, new => list of old.
     *
     * @param array<string, list<string>> $groups
     */
    public function collapse(array $groups): self
    {
        $map = [];
        foreach ($groups as $new => $olds) {
            foreach ($olds as $old) {
                if (! in_array($old, $this->levels, true)) {
                    throw new InvalidArgumentException("Unknown level '{$old}'.");
                }
                $map[$old] = (string) $new;
            }
        }

        return $this->mapLevels(fn (string $level) => $map[$level] ?? $level);
    }

    /**
     * droplevels() / fct_drop(): remove levels with no values. With
     * $only, drop just those levels if unused.
     *
     * @param list<string>|null $only
     */
    public function dropUnused(?array $only = null): self
    {
        $used = array_flip(array_filter($this->codes, fn ($c) => $c !== null));
        $levels = [];
        foreach ($this->levels as $code => $level) {
            $drop = ! isset($used[$code]) && ($only === null || in_array($level, $only, true));
            if (! $drop) {
                $levels[] = $level;
            }
        }

        return $this->withLevels($levels);
    }

    /** Make an ordered (or unordered) factor with the same levels. */
    public function asOrdered(bool $ordered = true): self
    {
        return new self($this->levels, $this->codes, $ordered);
    }

    /** @param Closure(string): string $rename */
    private function mapLevels(Closure $rename): self
    {
        $levels = [];
        foreach ($this->levels as $level) {
            $levels[$rename($level)] = true;
        }
        $levels = array_map(strval(...), array_keys($levels));
        $values = array_map(fn (?string $v) => $v === null ? null : $rename($v), $this->values());

        return self::fromValues($values, $levels, $this->ordered);
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private static function sortedLevels(array $values): array
    {
        $present = array_values(array_filter($values, fn ($v) => $v !== null));
        $numeric = $present !== [] && array_all($present, fn ($v) => is_int($v) || is_float($v));

        $unique = [];
        foreach ($present as $value) {
            $unique[self::label($value)] = $value;
        }
        $unique = array_values($unique);
        $numeric ? sort($unique) : usort($unique, fn ($a, $b) => strcmp((string) self::label($a), (string) self::label($b)));

        return array_map(fn ($v) => (string) self::label($v), $unique);
    }

    public static function label(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_float($value) => Infer::formatFloat($value),
            $value instanceof DateTimeInterface => $value->format('H:i:s') === '00:00:00'
                ? $value->format('Y-m-d')
                : $value->format('Y-m-d H:i:s'),
            $value instanceof BackedEnum => (string) $value->value,
            default => (string) $value,
        };
    }
}
