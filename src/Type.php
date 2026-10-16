<?php

declare(strict_types=1);

namespace Phibble;

use InvalidArgumentException;

enum Type: string
{
    case Float = 'float';
    case Int = 'int';
    case Bool = 'bool';
    case String = 'string';
    case Factor = 'factor';
    case Date = 'date';

    public static function parse(self|string $type): self
    {
        if ($type instanceof self) {
            return $type;
        }

        return self::tryFrom(strtolower($type))
            ?? throw new InvalidArgumentException("Unknown column type '{$type}'.");
    }

    /** Short label used when printing a frame, as in tibble. */
    public function abbreviation(): string
    {
        return match ($this) {
            self::Float => 'dbl',
            self::Int => 'int',
            self::Bool => 'lgl',
            self::String => 'chr',
            self::Factor => 'fct',
            self::Date => 'date',
        };
    }

    public function isNumeric(): bool
    {
        return in_array($this, [self::Float, self::Int, self::Bool], true);
    }
}
