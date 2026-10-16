<?php

declare(strict_types=1);

namespace Phibble\Io;

use InvalidArgumentException;
use JsonSerializable;
use Phibble\Frame;
use Phibble\Row;
use Phibble\Type;
use RuntimeException;
use Traversable;

/**
 * Laravel Collection interop, duck-typed so phibble needs no Laravel.
 *
 * @internal
 */
final class Collections
{
    private const string COLLECTION = 'Illuminate\\Support\\Collection';

    /**
     * @param iterable<mixed>|object $collection
     * @param array<string, Type|string> $types
     */
    public static function read(iterable|object $collection, array $types = []): Frame
    {
        $items = match (true) {
            is_array($collection) => $collection,
            method_exists($collection, 'all') => $collection->all(),
            $collection instanceof Traversable => iterator_to_array($collection, false),
            method_exists($collection, 'toArray') => $collection->toArray(),
            default => throw new InvalidArgumentException(
                'Expected a Collection, an array or another iterable, got '.$collection::class.'.'
            ),
        };

        $rows = [];
        foreach ($items as $key => $item) {
            $rows[] = match (true) {
                is_array($item) => $item,
                $item instanceof Row => $item->toArray(),
                is_object($item) && method_exists($item, 'toArray') => $item->toArray(),
                $item instanceof JsonSerializable && is_array($item->jsonSerialize()) => $item->jsonSerialize(),
                is_object($item) => get_object_vars($item),
                default => throw new InvalidArgumentException(
                    "Collection item {$key} is ".get_debug_type($item).'; each item must be an array or object (a row).'
                ),
            };
        }

        return Frame::fromRows($rows, $types);
    }

    public static function write(Frame $frame): object
    {
        if (! class_exists(self::COLLECTION)) {
            throw new RuntimeException(
                'toCollection() needs Laravel collections. Install illuminate/collections, or use toRows().'
            );
        }
        $class = self::COLLECTION;

        return new $class($frame->toRows());
    }
}
