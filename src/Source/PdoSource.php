<?php

declare(strict_types=1);

namespace Phibble\Source;

use PDO;
use Phibble\DataFrame;
use Phibble\Type;

/**
 * Runs SQL with bindings through PDO and loads the result eagerly.
 *
 * The SQL, bindings and connection are public so that a later lazy SQL
 * table can start from a source and compile verbs into the query instead
 * of loading rows.
 */
final readonly class PdoSource implements Source
{
    /**
     * @param array<int|string, mixed> $bindings Positional (list) or named.
     * @param array<string, Type|string> $types
     */
    public function __construct(
        public PDO $pdo,
        public string $sql,
        public array $bindings = [],
        public array $types = [],
    ) {}

    public function read(): DataFrame
    {
        $statement = $this->pdo->prepare($this->sql);
        foreach ($this->bindings as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : ':'.ltrim($key, ':');
            $statement->bindValue($parameter, self::bindable($value), match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                default => PDO::PARAM_STR,
            });
        }
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            $columns = [];
            for ($i = 0; $i < $statement->columnCount(); $i++) {
                $meta = $statement->getColumnMeta($i);
                $columns[$meta === false ? "column{$i}" : $meta['name']] = [];
            }

            return DataFrame::fromColumns($columns, $this->types);
        }

        return DataFrame::fromRows($rows, $this->types, parseStrings: true);
    }

    private static function bindable(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \Stringable => (string) $value,
            default => $value,
        };
    }
}
