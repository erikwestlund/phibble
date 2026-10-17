<?php

declare(strict_types=1);

namespace Phibble\Source;

use InvalidArgumentException;
use PDO;
use Phibble\DataFrame;
use Phibble\Type;

/**
 * Loads query builders from Laravel and Doctrine DBAL without depending on
 * either. The builder is duck-typed:
 *
 * - Laravel query and Eloquent builders: toSql(), getBindings() and
 *   getConnection()->getPdo(). Eloquent builders and relations are
 *   unwrapped with toBase(), so global scopes apply.
 * - Doctrine DBAL QueryBuilder: getSQL() and getParameters(). Pass a PDO
 *   or a DBAL connection to run the SQL through PDO; without one, the
 *   builder runs the query itself.
 */
final class QuerySource
{
    /** @param array<string, Type|string> $types */
    public static function fromBuilder(object $builder, ?object $connection = null, array $types = []): Source
    {
        // Eloquent builders and relations: toBase() applies global scopes
        // (soft deletes, tenancy); getQuery() would skip them.
        for ($i = 0; $i < 3 && ! method_exists($builder, 'toSql'); $i++) {
            if (method_exists($builder, 'toBase')) {
                $builder = $builder->toBase();
            } elseif (method_exists($builder, 'getQuery')) {
                $builder = $builder->getQuery();
            } else {
                break;
            }
        }

        if (method_exists($builder, 'toSql') && method_exists($builder, 'getBindings')) {
            return self::laravel($builder, $connection, $types);
        }
        if (method_exists($builder, 'getSQL') && method_exists($builder, 'getParameters')) {
            return self::doctrine($builder, $connection, $types);
        }

        throw new InvalidArgumentException(
            'Unsupported query builder '.$builder::class.'. Expected a Laravel or Doctrine DBAL query builder.'
        );
    }

    /** @param array<string, Type|string> $types */
    private static function laravel(object $builder, ?object $connection, array $types): Source
    {
        $connection ??= method_exists($builder, 'getConnection') ? $builder->getConnection() : null;
        if ($connection === null) {
            throw new InvalidArgumentException('Cannot find a connection for this query builder; pass one.');
        }

        $bindings = $builder->getBindings();
        if (method_exists($connection, 'prepareBindings')) {
            $bindings = $connection->prepareBindings($bindings);
        }

        return new PdoSource(self::pdo($connection), $builder->toSql(), array_values($bindings), $types);
    }

    /** @param array<string, Type|string> $types */
    private static function doctrine(object $builder, ?object $connection, array $types): Source
    {
        $parameters = $builder->getParameters();

        if ($connection !== null) {
            foreach ($parameters as $name => $value) {
                if (is_array($value)) {
                    throw new InvalidArgumentException(
                        "Parameter '{$name}' is an array; PDO cannot expand it. Omit the connection to let Doctrine run the query."
                    );
                }
            }

            return new PdoSource(self::pdo($connection), $builder->getSQL(), $parameters, $types);
        }

        return new class($builder, $types) implements Source
        {
            /** @param array<string, Type|string> $types */
            public function __construct(private object $builder, private array $types) {}

            public function read(): DataFrame
            {
                $result = method_exists($this->builder, 'executeQuery')
                    ? $this->builder->executeQuery()
                    : $this->builder->execute();

                return DataFrame::fromRows($result->fetchAllAssociative(), $this->types, parseStrings: true);
            }
        };
    }

    private static function pdo(object $connection): PDO
    {
        $pdo = match (true) {
            $connection instanceof PDO => $connection,
            method_exists($connection, 'getPdo') => $connection->getPdo(),
            method_exists($connection, 'getNativeConnection') => $connection->getNativeConnection(),
            default => null,
        };

        return $pdo instanceof PDO
            ? $pdo
            : throw new InvalidArgumentException('The connection does not expose a PDO instance.');
    }
}
