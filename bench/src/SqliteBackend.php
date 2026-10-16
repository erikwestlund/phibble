<?php

declare(strict_types=1);

namespace Phibble\Bench;

use PDO;
use Phibble\Contracts\Table;
use Phibble\Frame;
use Phibble\Sql\SqlTable;
use Phibble\Type;

/** Loads each CSV into one in-memory SQLite database and returns SqlTables. */
final class SqliteBackend implements Backend
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
    }

    public function name(): string
    {
        return 'sqlite';
    }

    public function load(string $path): Table
    {
        $frame = Frame::fromCsv($path);
        $table = basename($path, '.csv');
        $columns = $frame->columns();
        // Typed columns: untyped ones store bound values as TEXT, and SQLite
        // orders all TEXT above all numbers.
        $sqlType = fn (string $c) => match ($frame->type($c)) {
            Type::Int, Type::Bool => 'INTEGER',
            Type::Float => 'REAL',
            default => 'TEXT',
        };
        $this->pdo->exec("CREATE TABLE \"{$table}\" (".implode(', ', array_map(fn ($c) => "\"{$c}\" {$sqlType($c)}", $columns)).')');
        $insert = $this->pdo->prepare("INSERT INTO \"{$table}\" VALUES (".implode(', ', array_fill(0, count($columns), '?')).')');
        $this->pdo->beginTransaction();
        foreach ($frame->toRows() as $row) {
            $insert->execute(array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, array_values($row)));
        }
        $this->pdo->commit();
        $this->pdo->exec("CREATE INDEX \"{$table}_key\" ON \"{$table}\" (\"key\")");

        return SqlTable::table($this->pdo, $table);
    }
}
