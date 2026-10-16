<?php

declare(strict_types=1);

namespace Phibble\Column;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use Phibble\Type;
use Stringable;

/**
 * Turns raw PHP values into typed columns, and converts between types the
 * way R's as.*() functions do.
 *
 * @internal
 */
final class Infer
{
    private const string DATE = '/^\d{4}-\d{2}-\d{2}$/';

    private const string DATETIME = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/';

    /** Marks a value that cannot be converted; it becomes NA with a warning. */
    private static ?object $fail = null;

    /**
     * Build a column from PHP values, inferring the type from the values'
     * own PHP types unless one is given.
     *
     * Inference follows R's c(): bool < int < float; anything with a string
     * is a string; DateTimeInterface values are datetimes; arrays and other
     * objects make a list-column. All-NA columns are bool, as in R.
     *
     * @param list<mixed> $values
     */
    public static function column(array $values, Type|string|null $type = null, string $name = ''): Column
    {
        $type = $type === null ? self::detect($values) : Type::parse($type);

        return self::cast($values, $type, null, $name);
    }

    /**
     * Build a column from text, as read from CSV files or databases that
     * return everything as strings. Values in $na become null; the type is
     * the narrowest of bool, int, float, date, datetime and string that fits.
     *
     * @param list<?string> $values
     * @param list<string> $na
     */
    public static function parse(array $values, Type|string|null $type = null, array $na = ['', 'NA'], string $name = ''): Column
    {
        $values = array_map(fn (?string $v) => $v === null || in_array(trim($v), $na, true) ? null : $v, $values);
        if ($type !== null) {
            return self::cast($values, Type::parse($type), Type::String, $name);
        }

        return self::cast($values, self::guess($values), Type::String, $name);
    }

    /**
     * Guess the type of text values, as readr's guess_parser() does (except
     * that whole numbers are int rather than double).
     *
     * @param list<?string> $values
     */
    public static function guess(array $values): Type
    {
        $present = [];
        foreach ($values as $v) {
            if ($v !== null) {
                $present[] = trim($v);
            }
        }

        return match (true) {
            $present === [] => Type::Bool,
            array_all($present, fn ($v) => in_array($v, ['T', 'F', 'TRUE', 'FALSE', 'True', 'False', 'true', 'false'], true)) => Type::Bool,
            array_all($present, fn ($v) => preg_match('/^[+-]?\d+$/', $v)
                && (strlen(ltrim($v, '+-')) < 19 || filter_var($v, FILTER_VALIDATE_INT) !== false)) => Type::Int,
            array_all($present, fn ($v) => self::isNumber($v)) => Type::Float,
            array_all($present, fn ($v) => preg_match(self::DATE, $v) && self::parseDate($v) !== null) => Type::Date,
            array_all($present, fn ($v) => (self::parseDateTime($v) ?? self::parseDate($v)) !== null) => Type::DateTime,
            default => Type::String,
        };
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
     * Convert a column to another type, as R's as.*() would. Values that
     * cannot be converted become NA with a warning.
     */
    public static function convert(Column $column, Type|string $type, string $name = ''): Column
    {
        $type = Type::parse($type);
        if ($column->type() === $type) {
            return $column;
        }
        // as.integer(factor) gives the 1-based codes, as in R.
        if ($column instanceof FactorColumn && in_array($type, [Type::Int, Type::Float], true)) {
            $codes = array_map(fn (?int $c) => $c === null ? null : $c + 1, $column->codes());

            return self::cast($codes, $type, Type::Int, $name);
        }

        return self::cast($column->values(), $type, $column->type(), $name);
    }

    /** R's as.character() for doubles: 15 significant digits, fixed or scientific, whichever is shorter. */
    public static function formatFloat(float $x): string
    {
        if (is_nan($x)) {
            return 'NaN';
        }
        if (is_infinite($x)) {
            return $x > 0 ? 'Inf' : '-Inf';
        }
        if ($x == 0.0) {
            return '0';
        }

        $target = (float) sprintf('%.14e', $x);
        for ($digits = 1; $digits < 15; $digits++) {
            if ((float) sprintf('%.'.($digits - 1).'e', $x) === $target) {
                break;
            }
        }
        [$mantissa, $exponent] = explode('e', sprintf('%.'.($digits - 1).'e', $x));
        $exponent = (int) $exponent;
        $scientific = $mantissa.'e'.($exponent < 0 ? '-' : '+').str_pad((string) abs($exponent), 2, '0', STR_PAD_LEFT);
        $fixed = sprintf('%.'.max(0, $digits - 1 - $exponent).'f', $x);

        return strlen($fixed) <= strlen($scientific) ? $fixed : $scientific;
    }

    /** @param list<mixed> $values */
    private static function detect(array $values): Type
    {
        $seen = [];
        foreach ($values as $v) {
            $seen[match (true) {
                $v === null => 'null',
                is_bool($v) => 'bool',
                is_int($v) => 'int',
                is_float($v) => 'float',
                is_string($v), $v instanceof Stringable, $v instanceof BackedEnum => 'string',
                $v instanceof DateTimeInterface => 'datetime',
                default => 'list',
            }] = true;
        }
        unset($seen['null']);

        return match (true) {
            $seen === [] => Type::Bool,
            isset($seen['list']) => Type::List,
            isset($seen['string']) => Type::String,
            isset($seen['datetime']) => count($seen) === 1 ? Type::DateTime : Type::String,
            isset($seen['float']) => Type::Float,
            isset($seen['int']) => Type::Int,
            default => Type::Bool,
        };
    }

    /** @param list<mixed> $values */
    private static function cast(array $values, Type $type, ?Type $from, string $name): Column
    {
        if ($type === Type::Factor) {
            return FactorColumn::fromValues($values);
        }
        if ($type === Type::List) {
            return new ArrayColumn(Type::List, $values);
        }

        self::$fail ??= new \stdClass();
        $out = [];
        $failed = 0;
        foreach ($values as $value) {
            if ($value === null) {
                $out[] = null;

                continue;
            }
            $converted = self::castValue($value, $type, $from);
            if ($converted === self::$fail) {
                $failed++;
                $converted = null;
            }
            $out[] = $converted;
        }

        if ($failed > 0) {
            trigger_error(
                "NAs introduced by coercion to {$type->value} in column '{$name}' ({$failed} value".($failed === 1 ? '' : 's').').',
                E_USER_WARNING,
            );
        }

        return new ArrayColumn($type, $out);
    }

    private static function castValue(mixed $value, Type $type, ?Type $from): mixed
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        if ($value instanceof Stringable && ! $value instanceof DateTimeInterface) {
            $value = (string) $value;
        }

        return match ($type) {
            Type::Float => match (true) {
                is_float($value) => $value,
                is_int($value), is_bool($value) => (float) $value,
                is_string($value) && self::isNumber(trim($value)) => self::toFloat(trim($value)),
                $value instanceof DateTimeInterface => self::epoch($value, $from),
                default => self::$fail,
            },
            Type::Int => match (true) {
                is_int($value) => $value,
                is_bool($value) => (int) $value,
                is_float($value) => is_finite($value) && abs($value) < 2 ** 63 ? (int) $value : self::$fail,
                is_string($value) && filter_var(trim($value), FILTER_VALIDATE_INT) !== false => (int) trim($value),
                is_string($value) && self::isNumber(trim($value)) => self::castValue(self::toFloat(trim($value)), Type::Int, null),
                $value instanceof DateTimeInterface => (int) floor(self::epoch($value, $from)),
                default => self::$fail,
            },
            Type::Bool => match (true) {
                is_bool($value) => $value,
                is_int($value), is_float($value) => is_nan((float) $value) ? self::$fail : $value != 0,
                is_string($value) => match (trim($value)) {
                    'TRUE', 'true', 'T', 'True' => true,
                    'FALSE', 'false', 'F', 'False' => false,
                    default => self::$fail,
                },
                default => self::$fail,
            },
            Type::String => match (true) {
                is_string($value) => $value,
                is_bool($value) => $value ? 'TRUE' : 'FALSE',
                is_int($value) => (string) $value,
                is_float($value) => self::formatFloat($value),
                $value instanceof DateTimeInterface => $from === Type::Date || ($from === null && $value->format('H:i:s.u') === '00:00:00.000000')
                    ? $value->format('Y-m-d')
                    : $value->format('Y-m-d H:i:s'),
                default => self::$fail,
            },
            Type::Date => match (true) {
                $value instanceof DateTimeInterface => self::parseDate($value->format('Y-m-d')),
                is_string($value) => self::parseDate(trim($value))
                    ?? (($dt = self::parseDateTime(trim($value))) ? self::parseDate($dt->format('Y-m-d')) : self::$fail),
                is_int($value), is_float($value) => is_finite((float) $value)
                    ? new DateTimeImmutable('@'.((int) floor($value) * 86400))
                    : self::$fail,
                default => self::$fail,
            },
            Type::DateTime => match (true) {
                $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value),
                is_string($value) => self::parseDateTime(trim($value)) ?? self::parseDate(trim($value)) ?? self::$fail,
                is_int($value), is_float($value) => is_finite((float) $value)
                    ? DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $value), new DateTimeZone('UTC')) ?: self::$fail
                    : self::$fail,
                default => self::$fail,
            },
            Type::Factor, Type::List => throw new \LogicException('Factors and lists are cast as a whole column.'),
        };
    }

    /** Days (dates) or seconds (datetimes) since 1970-01-01, as R's as.numeric(). */
    private static function epoch(DateTimeInterface $value, ?Type $from): float
    {
        $seconds = (float) $value->format('U.u');

        return $from === Type::Date ? floor($seconds / 86400) : $seconds;
    }

    private static function isNumber(string $v): bool
    {
        return is_numeric($v) || in_array($v, ['Inf', '-Inf', 'NaN', 'inf', '-inf', 'nan'], true);
    }

    private static function toFloat(string $v): float
    {
        return match (strtolower($v)) {
            'inf' => INF,
            '-inf' => -INF,
            'nan' => NAN,
            default => (float) $v,
        };
    }

    /** Dates are UTC midnight. */
    private static function parseDate(string $value): ?DateTimeImmutable
    {
        if (! preg_match(self::DATE, $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** Datetimes without an offset are read as UTC, as readr does. */
    private static function parseDateTime(string $value): ?DateTimeImmutable
    {
        if (! preg_match(self::DATETIME, $value)) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }

        // Reject rollovers such as 2024-02-30.
        return $date->format('Y-m-d') === substr($value, 0, 10) ? $date : null;
    }
}
