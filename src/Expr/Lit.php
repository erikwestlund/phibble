<?php

declare(strict_types=1);

namespace Phibble\Expr;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * A literal: null, bool, int, float, string, a date, or a list of scalars
 * (only as the second argument of `in`).
 */
final readonly class Lit extends Expr
{
    public mixed $value;

    public ?string $dtype;

    public function __construct(mixed $value, ?string $dtype = null)
    {
        if (is_array($value)) {
            foreach ($value as $v) {
                if (! ($v === null || is_scalar($v))) {
                    throw new InvalidArgumentException('List literals may only hold scalars.');
                }
            }
            $value = array_values($value);
        } elseif ($value instanceof DateTimeInterface) {
            $dtype ??= $value->format('H:i:s.u') === '00:00:00.000000' ? 'date' : 'datetime';
        } elseif (! ($value === null || is_scalar($value))) {
            throw new InvalidArgumentException('Literals must be scalars, dates or lists of scalars, got '.get_debug_type($value).'.');
        }
        if (! in_array($dtype, [null, 'date', 'datetime'], true)) {
            throw new InvalidArgumentException("Unknown literal dtype '{$dtype}'.");
        }
        $this->value = $value;
        $this->dtype = $dtype;
    }

    /** @param array<string, mixed> $node */
    public static function fromArray(array $node): self
    {
        $dtype = $node['dtype'] ?? null;
        $value = $node['value'] ?? null;
        if ($dtype !== null && is_string($value)) {
            $value = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        }

        return new self($value, $dtype);
    }

    public function toArray(): array
    {
        $value = $this->value;
        if ($value instanceof DateTimeInterface) {
            $value = $this->dtype === 'date' ? $value->format('Y-m-d') : $value->format(DATE_ATOM);
        }

        return $this->dtype === null
            ? ['type' => 'lit', 'value' => $value]
            : ['type' => 'lit', 'value' => $value, 'dtype' => $this->dtype];
    }

    public function columns(): array
    {
        return [];
    }
}
