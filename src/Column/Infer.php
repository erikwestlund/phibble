<?php

declare(strict_types=1);

namespace Phibble\Column;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use Phibble\Type;
use Stringable;

/**
 * Turns raw PHP values into typed columns.
 *
 * @internal
 */
final class Infer
{
    private const string DATE = '/^\d{4}-\d{2}-\d{2}$/';

    private const string DATETIME = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/';

    /**
     * Build a column from PHP values, inferring the type from the values'
     * own PHP types unless one is given. All-NA columns are bool, as in R.
     *
     * @param list<mixed> $values
     */
    public static function column(array $values, Type|string|null $type = null, string $name = ''): Column
    {
        $type = $type === null ? self::detect($values, $name) : Type::parse($type);

        return self::cast($values, $type, $name);
    }

    /**
     * Build a column from text, as read from CSV files or databases that
     * return everything as strings. Values in $na become null; the type is
     * the narrowest of int, float, bool, date and string that fits.
     *
     * @param list<?string> $values
     * @param list<string> $na
     */
    public static function parse(array $values, Type|string|null $type = null, array $na = ['', 'NA'], string $name = ''): Column
    {
        $values = array_map(fn (?string $v) => $v === null || in_array(trim($v), $na, true) ? null : $v, $values);
        if ($type !== null) {
            return self::cast($values, Type::parse($type), $name);
        }

        $present = array_map(trim(...), array_filter($values, fn ($v) => $v !== null));
        $trimmed = array_map(fn (?string $v) => $v === null ? null : trim($v), $values);

        if ($present === []) {
            return new ArrayColumn(Type::Bool, $trimmed);
        }
        if (array_all($present, fn ($v) => filter_var($v, FILTER_VALIDATE_INT) !== false && preg_match('/^[+-]?\d+$/', $v))) {
            return self::cast($trimmed, Type::Int, $name);
        }
        if (array_all($present, fn ($v) => is_numeric($v))) {
            return self::cast($trimmed, Type::Float, $name);
        }
        if (array_all($present, fn ($v) => in_array(strtolower($v), ['true', 'false'], true))) {
            return self::cast($trimmed, Type::Bool, $name);
        }
        if (array_all($present, fn ($v) => self::parseDate($v) !== null)) {
            return self::cast($trimmed, Type::Date, $name);
        }

        return new ArrayColumn(Type::String, $values);
    }

    /**
     * Like column(), but a column whose values are all strings is parsed as
     * text. Used for database results, where drivers differ in whether they
     * return numbers as numbers or as strings.
     *
     * @param list<mixed> $values
     */
    public static function fromDatabase(array $values, Type|string|null $type = null, string $name = ''): Column
    {
        $present = array_filter($values, fn ($v) => $v !== null);
        if ($present !== [] && array_all($present, fn ($v) => is_string($v))) {
            return self::parse($values, $type, [], $name);
        }

        return self::column($values, $type, $name);
    }

    /**
     * Convert a column to another type.
     */
    public static function convert(Column $column, Type|string $type, string $name = ''): Column
    {
        $type = Type::parse($type);
        if ($column->type() === $type) {
            return $column;
        }

        return self::cast($column->values(), $type, $name);
    }

    /** @param list<mixed> $values */
    private static function detect(array $values, string $name): Type
    {
        $present = array_filter($values, fn ($v) => $v !== null);

        return match (true) {
            $present === [] => Type::Bool,
            array_all($present, fn ($v) => is_bool($v)) => Type::Bool,
            array_all($present, fn ($v) => is_int($v)) => Type::Int,
            array_all($present, fn ($v) => is_int($v) || is_float($v)) => Type::Float,
            array_all($present, fn ($v) => $v instanceof DateTimeInterface) => Type::Date,
            array_all($present, fn ($v) => is_scalar($v) || $v instanceof Stringable || $v instanceof BackedEnum || $v instanceof DateTimeInterface) => Type::String,
            default => throw new InvalidArgumentException(
                "Column '{$name}' holds values that are not scalars, dates or strings."
            ),
        };
    }

    /** @param list<mixed> $values */
    private static function cast(array $values, Type $type, string $name): Column
    {
        if ($type === Type::Factor) {
            return FactorColumn::fromValues($values);
        }

        $out = [];
        foreach ($values as $value) {
            $out[] = $value === null ? null : (self::castValue($value, $type)
                ?? throw new InvalidArgumentException(sprintf(
                    "Cannot cast %s to %s in column '%s'.",
                    var_export(is_object($value) ? $value::class : $value, true),
                    $type->value,
                    $name,
                )));
        }

        return new ArrayColumn($type, $out);
    }

    /** Returns null when the value cannot be cast. */
    private static function castValue(mixed $value, Type $type): mixed
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return match ($type) {
            Type::Float => match (true) {
                is_float($value) => $value,
                is_int($value), is_bool($value) => (float) $value,
                is_string($value) && is_numeric(trim($value)) => (float) trim($value),
                default => null,
            },
            Type::Int => match (true) {
                is_int($value) => $value,
                is_bool($value) => (int) $value,
                is_float($value) && floor($value) === $value && abs($value) <= PHP_INT_MAX => (int) $value,
                is_string($value) && filter_var(trim($value), FILTER_VALIDATE_INT) !== false => (int) trim($value),
                is_string($value) && is_numeric(trim($value)) => self::castValue((float) trim($value), Type::Int),
                default => null,
            },
            Type::Bool => match (true) {
                is_bool($value) => $value,
                $value === 1, $value === 1.0 => true,
                $value === 0, $value === 0.0 => false,
                is_string($value) => match (strtolower(trim($value))) {
                    'true', 't', '1', 'yes' => true,
                    'false', 'f', '0', 'no' => false,
                    default => null,
                },
                default => null,
            },
            Type::String => match (true) {
                is_string($value) => $value,
                is_bool($value) => $value ? 'true' : 'false',
                $value instanceof DateTimeInterface => $value->format('H:i:s') === '00:00:00'
                    ? $value->format('Y-m-d')
                    : $value->format(DATE_ATOM),
                is_scalar($value), $value instanceof Stringable => (string) $value,
                default => null,
            },
            Type::Date => match (true) {
                $value instanceof DateTimeImmutable => $value,
                $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value),
                is_string($value) => self::parseDate(trim($value)),
                default => null,
            },
            Type::Factor => throw new \LogicException('Factors are cast as a whole column.'),
        };
    }

    private static function parseDate(string $value): ?DateTimeImmutable
    {
        if (preg_match(self::DATE, $value)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
        }
        if (preg_match(self::DATETIME, $value)) {
            try {
                $date = new DateTimeImmutable($value);
            } catch (Exception) {
                return null;
            }

            // Reject rollovers such as 2024-02-30.
            return $date->format('Y-m-d') === substr($value, 0, 10) ? $date : null;
        }

        return null;
    }
}
