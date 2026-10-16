<?php

declare(strict_types=1);

namespace Phibble\Io;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use Phibble\Contracts\Table;
use Phibble\Frame;
use Phibble\Type;
use stdClass;
use Stringable;

/**
 * JSON in and out, as records or columns.
 *
 * @internal
 */
final class Json
{
    /** @param array<string, Type|string> $types */
    public static function read(string $json, array $types = [], ?string $orient = null): Frame
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new InvalidArgumentException('JSON must be a list of records or an object of columns.');
        }

        $orient ??= match (true) {
            $data === [] || array_is_list($data) => 'records',
            array_all($data, fn ($v) => is_array($v) && array_is_list($v)) => 'columns',
            default => throw new InvalidArgumentException(
                'JSON must be a list of records or an object whose values are all arrays (columns).'
            ),
        };

        return match ($orient) {
            'records' => self::records($data, $types),
            'columns' => Frame::fromColumns($data, $types),
            default => throw new InvalidArgumentException("orient must be 'records' or 'columns', got '{$orient}'."),
        };
    }

    public static function write(Table $table, string $orient = 'records', int $flags = 0): string
    {
        return json_encode(self::data($table, $orient), $flags | JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>>|stdClass|array<string, list<mixed>> */
    private static function data(Table $table, string $orient): array|stdClass
    {
        $types = $table->types();
        $columns = [];
        foreach ($table->toColumns() as $name => $values) {
            $columns[$name] = array_map(fn ($v) => self::value($v, $types[$name], $orient), $values);
        }

        return match ($orient) {
            'columns' => $columns === [] ? new stdClass() : $columns,
            'records' => array_map(
                fn (int $i) => (object) array_map(fn (array $values) => $values[$i], $columns),
                $table->nrow() === 0 ? [] : range(0, $table->nrow() - 1),
            ),
            default => throw new InvalidArgumentException("orient must be 'records' or 'columns', got '{$orient}'."),
        };
    }

    private static function value(mixed $v, ?Type $type, string $orient): mixed
    {
        return match (true) {
            $v === null => null,
            is_float($v) => is_finite($v) ? $v : null,
            $v instanceof DateTimeInterface => $type === Type::Date
                ? $v->format('Y-m-d')
                : self::datetime($v),
            $v instanceof Table => self::data($v, $orient),
            is_array($v) => array_map(fn ($x) => self::value($x, null, $orient), $v),
            $v instanceof JsonSerializable => $v,
            $v instanceof Stringable => (string) $v,
            is_object($v) => get_object_vars($v),
            default => $v,
        };
    }

    private static function datetime(DateTimeInterface $v): string
    {
        $utc = DateTimeImmutable::createFromInterface($v)->setTimezone(new DateTimeZone('UTC'));
        $fraction = rtrim($utc->format('u'), '0');

        return $utc->format('Y-m-d\TH:i:s').($fraction === '' ? '' : '.'.$fraction).'Z';
    }

    /**
     * @param list<mixed> $records
     * @param array<string, Type|string> $types
     */
    private static function records(array $records, array $types): Frame
    {
        foreach ($records as $i => $record) {
            if (! is_array($record) || ($record !== [] && array_is_list($record))) {
                throw new InvalidArgumentException("JSON record {$i} is not an object.");
            }
        }

        return Frame::fromRows($records, $types);
    }
}
