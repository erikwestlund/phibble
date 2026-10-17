<?php

declare(strict_types=1);

namespace Phibble;

use ArrayAccess;
use Closure;
use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use PDO;
use Phibble\Column\ArrayColumn;
use Phibble\Column\Column;
use Phibble\Column\FactorColumn;
use Phibble\Column\Infer;
use Phibble\Column\TemporalColumn;
use Phibble\Contracts\Table;
use Phibble\Exception\ColumnNotFound;
use Phibble\Expr\Call;
use Phibble\Expr\Col;
use Phibble\Expr\Desc;
use Phibble\Expr\Evaluator;
use Phibble\Expr\Expr;
use Phibble\Expr\Lit;
use Phibble\Format\Glimpse;
use Phibble\Format\Printer;
use Phibble\Io\Collections;
use Phibble\Io\CsvOptions;
use Phibble\Io\CsvWriter;
use Phibble\Io\Json;
use Phibble\Source\CsvSource;
use Phibble\Source\PdoSource;
use Phibble\Source\QuerySource;
use Phibble\Source\Source;
use Stringable;

/**
 * A typed, immutable, in-memory data frame.
 */
final readonly class DataFrame implements Table, ArrayAccess, Stringable
{
    /**
     * @param array<string, Column> $data
     * @param list<string> $groups
     */
    private function __construct(
        private array $data,
        private int $rows,
        private array $groups = [],
        private bool $rowwise = false,
        private bool $drop = true,
    ) {}

    // ---------------------------------------------------------------
    // Construction
    // ---------------------------------------------------------------

    /**
     * Build a frame from rows. Column order follows first appearance;
     * keys missing from a row are NA.
     *
     * @param iterable<array<string, mixed>|object> $rows
     * @param array<string, Type|string> $types Explicit types by column name.
     * @param bool $parseStrings Parse all-string columns as text (as fromCsv does).
     */
    public static function fromRows(iterable $rows, array $types = [], bool $parseStrings = false): self
    {
        $records = [];
        $names = [];
        foreach ($rows as $row) {
            $row = match (true) {
                is_array($row) => $row,
                $row instanceof Row => $row->toArray(),
                default => get_object_vars($row),
            };
            $records[] = $row;
            foreach ($row as $name => $_) {
                $names[$name] = true;
            }
        }

        $columns = [];
        foreach (array_keys($names) as $name) {
            $name = (string) $name;
            $columns[$name] = array_map(fn (array $r) => $r[$name] ?? null, $records);
        }

        return self::build($columns, count($records), $types, $parseStrings);
    }

    /**
     * Build a frame from columns: name => list of values, or name => Column.
     *
     * @param array<string, list<mixed>|Column> $columns
     * @param array<string, Type|string> $types Explicit types by column name.
     */
    public static function fromColumns(array $columns, array $types = []): self
    {
        $lengths = array_map(fn ($c) => is_array($c) ? count($c) : $c->count(), $columns);
        $rows = $lengths === [] ? 0 : max($lengths);

        return self::build($columns, $rows, $types, false);
    }

    /**
     * Read delimited text like readr::read_csv(): a path (".gz" is
     * decompressed) or an open stream. Types are guessed from up to
     * guessMax rows; "" and "NA" are NA. More readr options go as named
     * arguments; see Phibble\Io\CsvOptions (colNames, select, skip,
     * nMax, quote, comment, trimWs, skipEmptyRows, guessMax, nameRepair,
     * decimalMark, groupingMark, escapeDouble, escapeBackslash).
     *
     * Problems (unparseable values, ragged rows) raise one E_USER_WARNING;
     * the data is still returned, with NA where values failed.
     *
     * @param string|resource $file
     * @param string|array<int|string, mixed> $types readr col_types: "icdlDTfn_?" or name => spec.
     * @param list<string> $na
     */
    public static function fromCsv(mixed $file, string|array $types = [], string $delimiter = ',', array $na = ['', 'NA'], mixed ...$options): self
    {
        $options = new CsvOptions(...['types' => $types, 'delimiter' => $delimiter, 'na' => $na, ...$options]);

        return (is_resource($file) ? CsvSource::stream($file, $options) : CsvSource::file((string) $file, $options))->read();
    }

    /**
     * Read delimited text from a string; options as fromCsv().
     *
     * @param string|array<int|string, mixed> $types
     * @param list<string> $na
     */
    public static function fromCsvString(string $csv, string|array $types = [], string $delimiter = ',', array $na = ['', 'NA'], mixed ...$options): self
    {
        $options = new CsvOptions(...['types' => $types, 'delimiter' => $delimiter, 'na' => $na, ...$options]);

        return CsvSource::string($csv, $options)->read();
    }

    /**
     * Run a query and load the result.
     *
     * @param array<int|string, mixed> $bindings Positional or named.
     * @param array<string, Type|string> $types
     */
    public static function fromPdo(PDO $pdo, string $sql, array $bindings = [], array $types = []): self
    {
        return (new PdoSource($pdo, $sql, $bindings, $types))->read();
    }

    /**
     * Load the result of a Laravel query builder (query or Eloquent) or a
     * Doctrine DBAL QueryBuilder. Neither library is required.
     *
     * @param array<string, Type|string> $types
     */
    public static function fromQuery(object $builder, ?object $connection = null, array $types = []): self
    {
        return QuerySource::fromBuilder($builder, $connection, $types)->read();
    }

    public static function fromSource(Source $source): self
    {
        return $source->read();
    }

    // ---------------------------------------------------------------
    // IO
    // ---------------------------------------------------------------

    /**
     * Read JSON: a list of records ([{"a": 1}, ...]) or an object of
     * columns ({"a": [1, 2]}). The shape is detected unless $orient is
     * 'records' or 'columns'. Missing keys and nulls are NA; nested arrays
     * and objects become list-columns.
     *
     * @param array<string, Type|string> $types
     */
    public static function fromJson(string $json, array $types = [], ?string $orient = null): self
    {
        return Json::read($json, $types, $orient);
    }

    /**
     * Build a frame from a Laravel Collection (or anything with all() or
     * toArray(), or any iterable). Items may be arrays, Eloquent models or
     * other objects with toArray(), or plain objects. Laravel is not needed.
     *
     * @param iterable<mixed>|object $collection
     * @param array<string, Type|string> $types
     */
    public static function fromCollection(iterable|object $collection, array $types = []): self
    {
        return Collections::read($collection, $types);
    }

    /**
     * Stack tables by row, matching columns by name. Columns missing from a
     * table are NA there. A column keeps its type when every table agrees
     * (factors also need the same levels); otherwise it is inferred again.
     * The result takes the first table's grouping.
     *
     * @param list<Table> $tables
     */
    public static function concat(array $tables): self
    {
        $frames = array_map(fn (Table $t) => $t->collect(), $tables);
        if ($frames === []) {
            return self::fromColumns([]);
        }

        $names = array_values(array_unique(array_merge(...array_map(fn (self $f) => $f->columns(), $frames))));
        $data = [];
        foreach ($names as $name) {
            $values = [];
            $columns = [];
            foreach ($frames as $frame) {
                if ($frame->has($name)) {
                    $columns[] = $frame->data[$name];
                    array_push($values, ...$frame->data[$name]->values());
                } else {
                    array_push($values, ...array_fill(0, $frame->rows, null));
                }
            }

            $data[$name] = self::combine($columns, $values, $name);
        }

        $rows = array_sum(array_map(fn (self $f) => $f->rows, $frames));
        $groups = array_values(array_filter($frames[0]->groups, fn (string $g) => in_array($g, $names, true)));

        return new self($data, $rows, $groups, false, $frames[0]->drop);
    }

    /**
     * One column from several, as vctrs::vec_c() combines them: factors
     * take the union of levels in order of appearance (and stay ordered
     * when all share the same levels; ordered factors with different levels
     * can't combine), factor and text give text, numbers widen to double.
     *
     * @param list<Column> $columns The columns present (some tables may lack it).
     * @param list<mixed> $values All values, in order, with NA for missing tables.
     */
    private static function combine(array $columns, array $values, string $name): Column
    {
        $types = array_values(array_unique(array_map(fn (Column $c) => $c->type()->value, $columns)));
        if ($types === [Type::Factor->value]) {
            $ordered = array_filter($columns, fn (FactorColumn $c) => $c->isOrdered());
            $sameLevels = count(array_unique(array_map(fn (FactorColumn $c) => serialize($c->levels()), $columns))) === 1;
            if ($ordered !== [] && ! $sameLevels) {
                throw new InvalidArgumentException("Can't combine ordered factors with different levels in column '{$name}'.");
            }
            $levels = array_values(array_unique(array_merge(...array_map(fn (FactorColumn $c) => $c->levels(), $columns))));

            return FactorColumn::fromValues($values, $levels, count($ordered) === count($columns));
        }
        if (in_array(Type::Factor->value, $types, true) && array_diff($types, [Type::Factor->value, Type::String->value]) === []) {
            return Infer::column($values, Type::String, $name);
        }
        if (count($types) === 1) {
            return Infer::column($values, $columns[0]->type(), $name);
        }

        return Infer::column($values, null, $name);
    }

    // ---------------------------------------------------------------
    // tibble
    // ---------------------------------------------------------------

    /**
     * tibble(): build a frame column by column.
     *
     * - A list is a column; a scalar (or null) is repeated to the common
     *   length. Only length-1 values are recycled.
     * - A closure receives the frame built so far and returns a list or a
     *   scalar, so columns can be computed from earlier ones.
     * - A Table under an integer key is spliced in column by column.
     *
     * @param array<string|int, mixed> $columns
     * @param string $nameRepair check_unique, unique, universal or minimal
     */
    public static function tibble(array $columns, string $nameRepair = 'check_unique'): self
    {
        $names = [];
        $values = [];
        foreach ($columns as $name => $value) {
            if (is_int($name)) {
                if (! $value instanceof Table) {
                    throw new InvalidArgumentException('Column '.($name + 1).' must be named.');
                }
                foreach ($value->collect()->data as $inner => $column) {
                    $names[] = (string) $inner;
                    $values[] = $column;
                }

                continue;
            }
            $names[] = $name;
            $values[] = $value;
        }
        $names = NameRepair::repair($names, $nameRepair);

        $frame = new self([], 0);
        $size = null;
        foreach ($names as $i => $name) {
            $value = $values[$i];
            if ($value instanceof Closure) {
                $value = $value($frame);
            }
            $column = match (true) {
                $value instanceof Column => $value,
                $value instanceof Table => throw new InvalidArgumentException(
                    "Column `{$name}` is a data frame; packed data frame columns are not supported. Pass it unnamed to splice its columns."
                ),
                is_array($value) && array_is_list($value) => Infer::column($value, null, $name),
                is_array($value) => Infer::column(array_values($value), null, $name),
                default => null,
            };

            if ($column === null) {
                // A scalar: recycled once the size is known.
                $length = 1;
            } else {
                $length = $column->count();
            }
            if ($size === null || $size === 1 && $frame->data !== [] && $length !== 1) {
                $frame = $size === 1 && $length !== 1 ? $frame->recycle($length) : $frame;
                $size = $length;
            } elseif ($length !== $size && $length !== 1) {
                throw new InvalidArgumentException(
                    "Tibble columns must have compatible sizes.\n• Size {$size}: Existing data.\n• Size {$length}: Column `{$name}`.\nℹ Only values of size one are recycled."
                );
            }

            if ($column === null) {
                // The type comes from the value itself, also when the size is 0.
                $column = Infer::column(array_fill(0, $size, $value), Infer::column([$value], null, $name)->type(), $name);
            } elseif ($length === 1 && $size !== 1) {
                $column = $column->take(array_fill(0, $size, 0));
            }
            $data = $frame->data;
            $data[$name] = $column;
            $frame = new self($data, $size);
        }

        return $frame;
    }

    /**
     * tribble(): a frame written row by row. Pass the column names, then
     * either one list per row or the cells in row order.
     *
     *     DataFrame::tribble(['x', 'y'], [1, 'a'], [2, 'b']);
     *
     * @param list<string> $names
     */
    public static function tribble(array $names, mixed ...$cells): self
    {
        $names = NameRepair::repair(array_map(fn ($n) => ltrim((string) $n, '~'), $names), 'check_unique');
        $width = count($names);
        $rows = $cells !== [] && array_all($cells, fn ($c) => is_array($c) && array_is_list($c) && count($c) === $width)
            ? $cells
            : (function () use ($cells, $width): array {
                if ($width === 0 || count($cells) % $width !== 0) {
                    throw new InvalidArgumentException(
                        "Data must be rectangular.\n• Found {$width} columns.\n• Found ".count($cells)." cells.\nℹ ".count($cells)." is not an integer multiple of {$width}."
                    );
                }

                return array_chunk($cells, $width);
            })();

        $columns = [];
        foreach ($names as $j => $name) {
            $columns[$name] = array_map(fn (array $row) => $row[$j], $rows);
        }

        return self::fromColumns($columns);
    }

    /**
     * as_tibble(): turn tables, rows, columns, collections or other
     * iterables into a frame.
     *
     * - A Table is collected.
     * - A list of associative arrays (or objects) is rows.
     * - An associative array of lists is columns.
     * - An object with all() or toArray() (a Laravel Collection) is unwrapped.
     *
     * @param string $nameRepair check_unique, unique, universal or minimal
     */
    public static function asTibble(mixed $data, string $nameRepair = 'check_unique'): self
    {
        if ($data instanceof Table) {
            return $data->collect();
        }
        if (is_object($data) && ! $data instanceof \Traversable && (method_exists($data, 'all') || method_exists($data, 'toArray'))) {
            $data = method_exists($data, 'all') ? $data->all() : $data->toArray();
        }
        if ($data instanceof \Traversable) {
            $data = iterator_to_array($data, false);
        }
        if (! is_array($data)) {
            throw new InvalidArgumentException('as_tibble() needs a table, an array or an iterable, got '.get_debug_type($data).'.');
        }
        if ($data === []) {
            return self::fromColumns([]);
        }

        if (array_is_list($data)) {
            $rows = array_map(fn ($row) => match (true) {
                is_array($row) => $row,
                $row instanceof Row => $row->toArray(),
                is_object($row) && method_exists($row, 'toArray') => $row->toArray(),
                is_object($row) => get_object_vars($row),
                default => throw new InvalidArgumentException('as_tibble() needs rows as arrays or objects; use enframe() for a plain list.'),
            }, $data);
            $frame = self::fromRows($rows);

            return $frame->renameAll(NameRepair::repair($frame->columns(), $nameRepair));
        }

        return self::tibble($data, $nameRepair);
    }

    /**
     * enframe(): an array as a two-column frame of names and values. A list
     * gets 1-based integer names, as in R.
     *
     * @param array<mixed> $values
     */
    public static function enframe(array $values, ?string $name = 'name', string $value = 'value'): self
    {
        $columns = [];
        if ($name !== null) {
            $columns[$name] = array_is_list($values)
                ? ($values === [] ? [] : range(1, count($values)))
                : array_map(strval(...), array_keys($values));
        }
        $columns[$value] = array_values($values);

        return self::fromColumns($columns, $name !== null && $values === [] ? [$name => Type::String] : []);
    }

    /**
     * deframe(): a two-column frame as name => value, or a one-column frame
     * as a list.
     *
     * @return array<mixed>
     */
    public function deframe(): array
    {
        return match (count($this->data)) {
            1 => array_values($this->toColumns())[0],
            2 => array_combine(
                array_map(fn ($k) => (string) $k, array_values($this->toColumns())[0]),
                array_values($this->toColumns())[1],
            ),
            default => throw new InvalidArgumentException('deframe() needs a frame with one or two columns.'),
        };
    }

    /**
     * add_row(): insert rows. Pass one row (name => value), a list of rows,
     * or nothing for an all-NA row. Missing columns are NA; unknown columns
     * throw. Positions are 0-based: $before inserts before that row,
     * $after after it. The default appends.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $rows
     */
    public function addRow(array $rows = [], ?int $before = null, ?int $after = null): self
    {
        if ($before !== null && $after !== null) {
            throw new InvalidArgumentException("Can't specify both before and after.");
        }
        $rows = $rows === [] ? [[]] : (array_is_list($rows) ? $rows : [$rows]);
        foreach ($rows as $row) {
            foreach (array_keys($row) as $name) {
                if (! isset($this->data[$name])) {
                    throw new InvalidArgumentException("New rows can't add columns.\n✖ Can't find column `{$name}` in `.data`.");
                }
            }
        }

        $columns = [];
        foreach ($this->data as $name => $_) {
            $columns[$name] = array_map(fn (array $row) => $row[$name] ?? null, $rows);
        }
        $new = self::fromColumns($columns)->withTypesOf($this);

        $position = match (true) {
            $before !== null => $before,
            $after !== null => $after + 1,
            default => $this->rows,
        };
        if ($position < 0 || $position > $this->rows) {
            throw new InvalidArgumentException("Row position {$position} is out of range.");
        }

        $stacked = self::concat([$this->ungroup()->slice(0, $position), $new, $this->ungroup()->slice($position)]);

        return $stacked->copy(groups: $this->groups, rowwise: $this->rowwise, drop: $this->drop)->copyColumnsFrom($this);
    }

    /**
     * add_column(): insert columns, with tibble()'s recycling rules. Place
     * them before or after a column, by name or 0-based position; by
     * default at the end.
     *
     * @param array<string, mixed> $columns
     */
    public function addColumn(array $columns, string|int|null $before = null, string|int|null $after = null): self
    {
        if ($before !== null && $after !== null) {
            throw new InvalidArgumentException("Can't specify both before and after.");
        }
        foreach (array_keys($columns) as $name) {
            if (isset($this->data[$name])) {
                throw new InvalidArgumentException("Column name `{$name}` must not be duplicated.\nUse `.name_repair` to specify repair.");
            }
        }

        $added = self::tibble([
            ...array_map(fn ($v) => $v instanceof Closure ? fn () => $v($this) : $v, $columns),
        ]);
        if ($added->rows !== $this->rows) {
            if ($added->rows !== 1) {
                throw new InvalidArgumentException(
                    "New columns must be compatible with `.data`.\n✖ New columns have {$added->rows} rows.\nℹ `.data` has {$this->rows} rows."
                );
            }
            $added = $added->recycle($this->rows);
        }

        $names = $this->columns();
        $position = match (true) {
            $before !== null => is_int($before) ? $before : $this->position($before),
            $after !== null => (is_int($after) ? $after : $this->position($after)) + 1,
            default => count($names),
        };
        $data = [];
        foreach (array_slice($this->data, 0, $position, true) as $name => $column) {
            $data[$name] = $column;
        }
        foreach ($added->data as $name => $column) {
            $data[$name] = $column;
        }
        foreach (array_slice($this->data, $position, null, true) as $name => $column) {
            $data[$name] = $column;
        }

        return $this->copy(data: $data);
    }

    /** Column access as a property: $frame->price. Unknown names throw; there is no partial matching. */
    public function __get(string $name): array
    {
        return $this->column($name);
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && isset($this->data[$offset]);
    }

    /** Column access as an array: $frame['price']. */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->column((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException('DataFrames are immutable. Use mutate() or withColumn().');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('DataFrames are immutable. Use select() to drop columns.');
    }

    private function position(string $name): int
    {
        $this->storage($name);

        return (int) array_search($name, $this->columns(), true);
    }

    /** Repeat a 1-row frame $n times. */
    private function recycle(int $n): self
    {
        return $this->copy(
            data: array_map(fn (Column $c) => $c->take(array_fill(0, $n, 0)), $this->data),
            rows: $n,
        );
    }

    /** @param list<string> $names */
    private function renameAll(array $names): self
    {
        return $this->copy(data: array_combine($names, array_values($this->data)));
    }

    /** Cast this frame's columns to the types of the same columns in $like. */
    private function withTypesOf(self $like): self
    {
        $frame = $this;
        foreach ($like->data as $name => $column) {
            $values = $frame->data[$name]->values();
            $frame = $frame->withColumn($name, match (true) {
                $column instanceof FactorColumn => FactorColumn::fromValues($values, null),
                array_all($values, fn ($v) => $v === null) => Infer::column($values, $column->type(), $name),
                default => $frame->data[$name],
            });
        }

        return $frame;
    }

    /**
     * Keep factor levels (and ordering) from $like where values still fit,
     * as vctrs does when combining a factor with itself.
     */
    private function copyColumnsFrom(self $like): self
    {
        $frame = $this;
        foreach ($like->data as $name => $column) {
            $mine = $frame->data[$name];
            if ($column instanceof FactorColumn && $mine instanceof FactorColumn) {
                $levels = array_values(array_unique([...$column->levels(), ...$mine->levels()]));
                $frame = $frame->withColumn($name, FactorColumn::fromValues($mine->values(), $levels, $column->isOrdered()));
            }
        }

        return $frame;
    }

    /**
     * @param array<string, list<mixed>|Column> $columns
     * @param array<string, Type|string> $types
     */
    private static function build(array $columns, int $rows, array $types, bool $parseStrings): self
    {
        foreach (array_keys($types) as $name) {
            if (! array_key_exists($name, $columns)) {
                throw ColumnNotFound::named((string) $name, array_map(strval(...), array_keys($columns)));
            }
        }

        $data = [];
        foreach ($columns as $name => $values) {
            $name = (string) $name;
            $type = $types[$name] ?? null;
            $column = match (true) {
                $values instanceof Column => $type === null ? $values : Infer::convert($values, $type, $name),
                $parseStrings => Infer::fromDatabase(array_values($values), $type, $name),
                default => Infer::column(array_values($values), $type, $name),
            };
            if ($column->count() !== $rows) {
                throw new InvalidArgumentException(
                    "Column '{$name}' has {$column->count()} values; expected {$rows}."
                );
            }
            $data[$name] = $column;
        }

        return new self($data, $rows);
    }

    // ---------------------------------------------------------------
    // Shape and access
    // ---------------------------------------------------------------

    public function columns(): array
    {
        return array_map(strval(...), array_keys($this->data));
    }

    public function types(): array
    {
        return array_map(fn (Column $c) => $c->type(), $this->data);
    }

    public function type(string $name): Type
    {
        return $this->storage($name)->type();
    }

    public function nrow(): int
    {
        return $this->rows;
    }

    public function ncol(): int
    {
        return count($this->data);
    }

    public function count(): int
    {
        return $this->rows;
    }

    public function has(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function column(string $name): array
    {
        return $this->storage($name)->values();
    }

    /** The storage behind a column. */
    public function storage(string $name): Column
    {
        return $this->data[$name] ?? throw ColumnNotFound::named($name, $this->columns());
    }

    /** @return list<string> The levels of a factor column. */
    public function levels(string $name): array
    {
        $column = $this->storage($name);
        if (! $column instanceof FactorColumn) {
            throw new InvalidArgumentException("Column '{$name}' is not a factor.");
        }

        return $column->levels();
    }

    public function row(int $index): Row
    {
        if ($index < 0 || $index >= $this->rows) {
            throw new InvalidArgumentException("Row {$index} is out of range (0..".($this->rows - 1).').');
        }

        return new Row(array_map(fn (Column $c) => $c->get($index), $this->data));
    }

    /** @return Generator<int, Row> */
    public function getIterator(): Generator
    {
        foreach ($this->toRows() as $i => $row) {
            yield $i => new Row($row);
        }
    }

    public function toRows(): array
    {
        $columns = $this->toColumns();
        $rows = [];
        for ($i = 0; $i < $this->rows; $i++) {
            $row = [];
            foreach ($columns as $name => $values) {
                $row[$name] = $values[$i];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function toColumns(): array
    {
        return array_map(fn (Column $c) => $c->values(), $this->data);
    }

    public function collect(): self
    {
        return $this;
    }

    // ---------------------------------------------------------------
    // Verbs
    // ---------------------------------------------------------------

    public function select(array $names): static
    {
        $names = array_values(array_unique($names));
        foreach ($names as $name) {
            $this->storage($name);
        }
        // Grouping columns always stay, as in dplyr.
        $names = [...array_values(array_diff($this->groups, $names)), ...$names];

        $data = [];
        foreach ($names as $name) {
            $data[$name] = $this->data[$name];
        }

        return $this->copy(data: $data);
    }

    public function filter(Closure|Expr $predicate): static
    {
        $keep = [];
        foreach ($this->groupIndex() as [$_, $indices]) {
            if ($predicate instanceof Expr) {
                foreach ($this->evaluator($indices)->vector($predicate) as $j => $flag) {
                    if ($flag === true) {
                        $keep[] = $indices[$j];
                    }
                }

                continue;
            }
            $group = $this->subset($indices);
            foreach ($group->toRows() as $j => $row) {
                if ($predicate(new Row($row), $group)) {
                    $keep[] = $indices[$j];
                }
            }
        }
        sort($keep);

        return $this->take($keep);
    }

    public function filterGroups(Closure $predicate): static
    {
        $keep = [];
        foreach ($this->groupIndex() as [$_, $indices]) {
            $mask = $predicate($this->subset($indices));
            if (! is_array($mask) || count($mask) !== count($indices)) {
                throw new InvalidArgumentException(
                    'filterGroups() must return one value per row of the group ('.count($indices).').'
                );
            }
            foreach (array_values($mask) as $j => $flag) {
                if ($flag) {
                    $keep[] = $indices[$j];
                }
            }
        }
        sort($keep);

        return $this->take($keep);
    }

    public function mutate(array $columns): static
    {
        $frame = $this;
        foreach ($columns as $name => $definition) {
            $values = $frame->evaluate((string) $name, $definition);
            $frame = $frame->withColumn((string) $name, $frame->factorResult($definition, $values) ?? $values);
        }

        return $frame;
    }

    public function mutateGroups(array $columns): static
    {
        $frame = $this;
        foreach ($columns as $name => $definition) {
            $name = (string) $name;
            if (! $definition instanceof Closure) {
                $values = $frame->evaluate($name, $definition);
                $frame = $frame->withColumn($name, $frame->factorResult($definition, $values) ?? $values);

                continue;
            }
            $values = array_fill(0, $frame->rows, null);
            foreach ($frame->groupIndex() as [$_, $indices]) {
                $result = $definition($frame->subset($indices));
                if (! is_array($result)) {
                    $result = array_fill(0, count($indices), $result);
                }
                if (count($result) !== count($indices)) {
                    throw new InvalidArgumentException(
                        "mutateGroups() '{$name}' returned ".count($result).' values for a group of '.count($indices).'.'
                    );
                }
                foreach (array_values($result) as $j => $value) {
                    $values[$indices[$j]] = $value;
                }
            }
            $frame = $frame->withColumn($name, $values);
        }

        return $frame;
    }

    /**
     * Add or replace one column. Replaced columns keep their position.
     *
     * @param list<mixed>|Column $values
     */
    public function withColumn(string $name, array|Column $values, Type|string|null $type = null): self
    {
        $column = $values instanceof Column
            ? ($type === null ? $values : Infer::convert($values, $type, $name))
            : Infer::column(array_values($values), $type, $name);

        if ($column->count() !== $this->rows && $this->data !== []) {
            throw new InvalidArgumentException(
                "Column '{$name}' has {$column->count()} values; expected {$this->rows}."
            );
        }

        $data = $this->data;
        $data[$name] = $column;

        return $this->copy(data: $data, rows: $column->count());
    }

    public function rename(array $map): static
    {
        $newByOld = [];
        foreach ($map as $new => $old) {
            $this->storage($old);
            $newByOld[$old] = (string) $new;
        }

        $data = [];
        foreach ($this->data as $name => $column) {
            $target = $newByOld[$name] ?? (string) $name;
            if (isset($data[$target])) {
                throw new InvalidArgumentException("Renaming would create a duplicate column '{$target}'.");
            }
            $data[$target] = $column;
        }
        $groups = array_map(fn (string $g) => $newByOld[$g] ?? $g, $this->groups);

        return $this->copy(data: $data, groups: $groups);
    }

    public function arrange(array $keys): static
    {
        $sorts = [];
        foreach ($keys as $key) {
            if ($key instanceof Expr) {
                // Expression keys are computed over the whole table; arrange ignores groups.
                $desc = $key instanceof Desc;
                $values = (new Evaluator($this))->vector($desc ? $key->expr : $key);
                $sorts[] = [$values, array_all($values, fn ($v) => $v === null || is_string($v)), $desc];

                continue;
            }
            [$name, $direction] = is_array($key) ? [$key[0], $key[1] ?? 'asc'] : [$key, 'asc'];
            $direction = strtolower($direction);
            if (! in_array($direction, ['asc', 'desc'], true)) {
                throw new InvalidArgumentException("Sort direction must be 'asc' or 'desc', got '{$direction}'.");
            }
            $sorts[] = [...$this->sortable($name), $direction === 'desc'];
        }

        return $this->take(self::sortOrder($sorts, $this->rows));
    }

    /**
     * Row order for sort keys, stable, NA last in both directions. Uses
     * array_multisort when every key is all-numeric or all-text, and a
     * comparison sort otherwise.
     *
     * @param list<array{0: list<mixed>, 1: bool, 2: bool}> $sorts [values, isString, descending]
     * @return list<int>
     */
    private static function sortOrder(array $sorts, int $rows): array
    {
        $order = $rows === 0 ? [] : range(0, $rows - 1);
        $args = [];
        foreach ($sorts as [$values, $isString, $desc]) {
            // The rank of missing values: 0 a value, 1 NaN, 2 NA; R sorts NaN after
            // numbers and NA last, in both directions.
            $missing = [];
            $keys = [];
            $numeric = ! $isString;
            $allInt = true;
            foreach ($values as $v) {
                if ($v === null || (is_float($v) && is_nan($v))) {
                    $missing[] = $v === null ? 2 : 1;
                    $keys[] = $numeric ? 0 : '';

                    continue;
                }
                $missing[] = 0;
                $allInt = $allInt && is_int($v);
                if ($v instanceof DateTimeInterface) {
                    $v = (float) $v->format('U.u');
                } elseif (is_bool($v)) {
                    $v = (int) $v;
                }
                if ($numeric ? ! (is_int($v) || is_float($v)) : ! is_string($v)) {
                    $args = null;

                    break 2;
                }
                $keys[] = $v;
            }
            // Integers compare exactly (SORT_NUMERIC would compare them as doubles).
            $flag = match (true) {
                ! $numeric => SORT_STRING,
                $allInt => SORT_REGULAR,
                default => SORT_NUMERIC,
            };
            array_push($args, $missing, SORT_ASC, SORT_NUMERIC, $keys, $desc ? SORT_DESC : SORT_ASC, $flag);
        }

        if ($args === null) {
            usort($order, function (int $a, int $b) use ($sorts): int {
                foreach ($sorts as [$values, $isString, $desc]) {
                    $cmp = self::compare($values[$a], $values[$b], $isString, $desc);
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                }

                return $a <=> $b;
            });

            return $order;
        }
        if ($rows === 0) {
            return [];
        }
        // The row number as the last key keeps the sort stable.
        array_push($args, $order, SORT_ASC, SORT_NUMERIC);
        array_multisort(...$args);

        return $args[count($args) - 3];
    }

    public function groupBy(array $names, bool $drop = true): static
    {
        $names = array_values(array_unique($names));
        foreach ($names as $name) {
            $this->storage($name);
        }

        return $this->copy(groups: $names, rowwise: false, drop: $drop);
    }

    public function ungroup(): static
    {
        return $this->copy(groups: [], rowwise: false, drop: true);
    }

    /** False after groupBy(..., drop: false): empty factor-level groups are kept. */
    public function dropsEmptyGroups(): bool
    {
        return $this->drop;
    }

    public function groups(): array
    {
        return $this->groups;
    }

    public function rowwise(array $idColumns = []): static
    {
        foreach ($idColumns as $name) {
            $this->storage($name);
        }

        return $this->copy(groups: array_values($idColumns), rowwise: true, drop: true);
    }

    public function isRowwise(): bool
    {
        return $this->rowwise;
    }

    public function eachGroup(): iterable
    {
        foreach ($this->groupIndex() as [$key, $indices]) {
            yield ['key' => $key, 'table' => $this->subset($indices)];
        }
    }

    public function groupRows(): array
    {
        return array_map(fn (array $g) => ['key' => $g[0], 'rows' => $g[1]], $this->groupIndex());
    }

    /** Number of groups (1 for an ungrouped table, nrow for a rowwise one). */
    public function ngroups(): int
    {
        return count($this->groupIndex());
    }

    /**
     * @param string $groups dplyr's .groups: 'drop_last' (default), 'drop',
     *     'keep' or 'rowwise'. Rowwise tables keep their id columns as groups.
     */
    public function summarise(array $aggregates, ?string $groups = null): static
    {
        $definitions = $aggregates;
        $index = $this->groupIndex();
        $first = array_map(fn (array $g) => $g[1][0] ?? null, $index);

        $data = [];
        foreach ($this->groups as $name) {
            $data[$name] = in_array(null, $first, true)
                ? $this->keyColumn($name, array_map(fn (array $g) => $g[0][$name], $index))
                : $this->data[$name]->take($first);
        }

        $results = array_fill_keys(array_keys($aggregates), []);
        // Simple aggregates of one column run as one pass over the groups.
        foreach ($aggregates as $name => $aggregate) {
            if ($aggregate instanceof Expr && ($values = $this->aggregateKernel($aggregate, $index)) !== null) {
                $results[$name] = $values;
                unset($aggregates[$name]);
            }
        }
        $needsTable = array_any($aggregates, fn ($a) => ! $a instanceof Expr);
        foreach ($aggregates === [] ? [] : $index as [$_, $indices]) {
            $group = $needsTable ? $this->subset($indices) : null;
            $evaluator = null;
            foreach ($aggregates as $name => $aggregate) {
                $value = $aggregate instanceof Expr
                    ? ($evaluator ??= $this->evaluator($indices))->scalar($aggregate, (string) $name)
                    : $aggregate($group);
                if (is_array($value)) {
                    throw new \LengthException("summarise() '{$name}' must return a single value, not an array. Use reframe() for several rows per group.");
                }
                $results[$name][] = $value;
            }
        }
        foreach ($results as $name => $values) {
            $data[(string) $name] = $this->factorResult($definitions[$name] ?? null, $values)
                ?? Infer::column($values, null, (string) $name);
        }

        $groups ??= $this->rowwise ? 'keep' : 'drop_last';
        [$outGroups, $rowwise] = match ($groups) {
            'drop_last' => [array_slice($this->groups, 0, -1), false],
            'drop' => [[], false],
            'keep' => [$this->groups, false],
            'rowwise' => [$this->groups, true],
            default => throw new InvalidArgumentException("Unknown .groups value '{$groups}'."),
        };

        return new self($data, count($index), $outGroups, $rowwise, $outGroups === [] ? true : $this->drop);
    }

    /** Alias of summarise(). */
    public function summarize(array $aggregates, ?string $groups = null): static
    {
        return $this->summarise($aggregates, $groups);
    }

    public function distinct(array $names = []): static
    {
        $selected = $names === [] ? $this : $this->select($names);
        $seen = [];
        $keep = [];
        foreach ($selected->toRows() as $i => $row) {
            $key = self::hashKey($row);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $keep[] = $i;
            }
        }

        return $selected->take($keep);
    }

    public function slice(int $offset, ?int $length = null): static
    {
        // array_slice() bounds, computed without building every row index.
        $start = $offset < 0 ? max(0, $this->rows + $offset) : min($offset, $this->rows);
        $end = match (true) {
            $length === null => $this->rows,
            $length < 0 => max($start, $this->rows + $length),
            default => min($this->rows, $start + $length),
        };

        return $this->take($start < $end ? range($start, $end - 1) : []);
    }

    /** The first n rows; a negative n drops the last |n| rows, as in R. */
    public function head(int $n = 6): static
    {
        return $this->slice(0, $n >= 0 ? $n : max(0, $this->rows + $n));
    }

    /** The last n rows; a negative n drops the first |n| rows, as in R. */
    public function tail(int $n = 6): self
    {
        return $this->slice($n >= 0 ? max(0, $this->rows - $n) : min($this->rows, -$n));
    }

    public function take(array $indices): static
    {
        foreach ($indices as $i) {
            if ($i !== null && ($i < 0 || $i >= $this->rows)) {
                throw new InvalidArgumentException("Row index {$i} is out of range for a table of {$this->rows} rows.");
            }
        }

        return $this->copy(
            data: array_map(fn (Column $c) => $c->take($indices), $this->data),
            rows: count($indices),
        );
    }

    public function join(
        Table $other,
        array $by,
        string $type = 'inner',
        array $suffix = ['.x', '.y'],
        ?bool $keep = null,
        bool $naMatches = true,
        string $multiple = 'all',
        ?string $relationship = null,
        string $unmatched = 'drop',
    ): static {
        return Join::run($this, $other->collect()->ungroup(), $by, $type, $suffix, $keep, $naMatches, $multiple, $relationship, $unmatched);
    }

    /**
     * bind_cols(): put tables side by side. They must have the same number
     * of rows; clashing names are repaired as name...position.
     *
     * @param list<Table> $tables
     */
    public static function bindCols(array $tables): self
    {
        $frames = array_map(fn (Table $t) => $t->collect(), $tables);
        $columns = [];
        $names = [];
        $rows = null;
        foreach ($frames as $frame) {
            $rows ??= $frame->rows;
            if ($frame->rows !== $rows) {
                throw new InvalidArgumentException("Can't bind tables of {$rows} and {$frame->rows} rows.");
            }
            foreach ($frame->data as $name => $column) {
                $names[] = (string) $name;
                $columns[] = $column;
            }
        }
        $names = NameRepair::repair($names, 'unique');
        $out = DataFrame::fromColumns(array_combine($names, $columns));
        // The result keeps the first table's grouping, as bind_cols() does.
        $groups = $frames === [] ? [] : array_values(array_intersect($frames[0]->groups, $names));

        return $groups === [] ? $out : $out->groupBy($groups, $frames[0]->drop);
    }

    /**
     * tidyr::nest(): collapse columns into a list-column of tables, one row
     * per unique combination of the other columns (first-appearance order).
     * On a grouped table with no columns given, nests everything but the
     * grouping columns.
     *
     * @param list<string> $columns
     */
    public function nest(array $columns = [], string $into = 'data'): self
    {
        if ($columns === []) {
            if ($this->groups === []) {
                throw new InvalidArgumentException('nest() needs columns, or a grouped table.');
            }
            $columns = array_values(array_diff($this->columns(), $this->groups));
        }
        foreach ($columns as $name) {
            $this->storage($name);
        }
        $keys = array_values(array_diff($this->columns(), $columns));

        $index = [];
        $keyColumns = array_map(fn (string $k) => $this->data[$k]->values(), $keys);
        for ($i = 0; $i < $this->rows; $i++) {
            $hash = self::hashKey(array_map(fn (array $v) => $v[$i], $keyColumns));
            $index[$hash][] = $i;
        }

        $inner = $this->ungroup()->select($columns);
        $data = [];
        $first = array_map(fn (array $rows) => $rows[0], array_values($index));
        foreach ($keys as $k) {
            $data[$k] = $this->data[$k]->take($first);
        }
        $data[$into] = new ArrayColumn(Type::List, array_map(fn (array $rows) => $inner->subset($rows), array_values($index)));

        return new self($data, count($index), array_values(array_intersect($this->groups, $keys)));
    }

    /**
     * tidyr::unnest(): expand list-columns of tables (or lists of rows)
     * into rows. Rows whose value is NA or empty are dropped unless
     * $keepEmpty. Nested columns take the place of the list-column.
     *
     * @param list<string>|string $columns
     */
    public function unnest(array|string $columns, bool $keepEmpty = false): self
    {
        $frame = $this;
        foreach ((array) $columns as $name) {
            $frame = $frame->unnestOne($name, $keepEmpty);
        }

        return $frame;
    }

    private function unnestOne(string $name, bool $keepEmpty): self
    {
        $parts = [];
        $outer = [];
        foreach ($this->column($name) as $i => $value) {
            $table = match (true) {
                $value === null => null,
                $value instanceof Table => $value->collect(),
                is_array($value) && $value !== [] && array_is_list($value) && is_array($value[0]) => DataFrame::fromRows($value),
                is_array($value) && array_is_list($value) => DataFrame::fromColumns([$name => $value]),
                is_array($value) => DataFrame::fromRows([$value]),
                default => DataFrame::fromColumns([$name => [$value]]),
            };
            if ($table === null || $table->nrow() === 0) {
                if ($keepEmpty) {
                    $outer[] = $i;
                    $parts[] = null;
                }

                continue;
            }
            array_push($outer, ...array_fill(0, $table->nrow(), $i));
            $parts[] = $table;
        }

        $tables = array_values(array_filter($parts, fn ($p) => $p !== null));
        // An empty row is an NA row of the combined columns, so types and
        // factor levels carry over.
        $empty = $tables === [] ? DataFrame::fromColumns([]) : DataFrame::concat($tables)->ungroup()->take([null]);
        $stacked = DataFrame::concat(array_map(fn ($p) => $p ?? $empty, $parts) ?: [DataFrame::fromColumns([])]);

        $data = [];
        foreach ($this->data as $column => $storage) {
            if ($column === $name) {
                foreach ($stacked->data as $innerName => $innerStorage) {
                    if (isset($data[$innerName]) || ($innerName !== $name && isset($this->data[$innerName]))) {
                        throw new InvalidArgumentException("unnest() would create a duplicate column '{$innerName}'.");
                    }
                    $data[$innerName] = $innerStorage;
                }

                continue;
            }
            $data[$column] = $storage->take($outer);
        }

        // tidyr's unnest() returns a plain tibble from a rowwise one.
        return $this->rowwise
            ? new self($data, count($outer))
            : new self($data, count($outer), array_values(array_intersect($this->groups, array_keys($data))), false, $this->drop);
    }

    // ---------------------------------------------------------------
    // Pivots (tidyr's pivot_longer_spec() and pivot_wider_spec())
    // ---------------------------------------------------------------

    public function pivotLongerSpec(array $spec, bool $dropNa = false): static
    {
        [$sources, $values, $parts, $combos] = self::readSpec($spec, 'longer');
        foreach ($sources as $source) {
            $this->storage($source);
        }
        $ids = array_values(array_diff($this->columns(), $sources));

        // Which source column feeds each (combination, value column).
        $feed = [];
        foreach ($spec as $row) {
            $feed[self::hashKey(array_map(fn (string $p) => $row[$p], $parts))][$row['.value']] = $row['.name'];
        }

        $rowIndex = [];
        $comboIndex = [];
        $valueColumns = array_map(fn (string $v) => $this->combinedColumn($v, array_map(
            fn (array $combo) => $feed[self::hashKey($combo)][$v] ?? null,
            $combos,
        )), array_combine($values, $values));
        $cells = array_map(fn (Column $c) => $c->values(), $valueColumns);
        $width = count($combos);
        for ($i = 0; $i < $this->rows; $i++) {
            foreach (array_keys($combos) as $c) {
                if ($dropNa && array_all($values, fn (string $v) => $cells[$v][$i * $width + $c] === null)) {
                    continue;
                }
                $rowIndex[] = $i;
                $comboIndex[] = $c;
            }
        }

        $data = [];
        foreach ($ids as $id) {
            $data[$id] = $this->data[$id]->take($rowIndex);
        }
        foreach ($parts as $p) {
            $data[$p] = Infer::column(array_map(fn (int $c) => $combos[$c][$p], $comboIndex), null, $p);
        }
        foreach ($valueColumns as $v => $column) {
            $data[$v] = $column->take(array_map(fn (int $i, int $c) => $i * $width + $c, $rowIndex, $comboIndex));
        }

        return new self($data, count($rowIndex), array_values(array_intersect($this->groups, $ids)));
    }

    public function pivotWiderSpec(array $spec, ?array $idColumns = null, array $valuesFill = []): static
    {
        [$names, $values, $from] = self::readSpec($spec, 'wider');
        foreach ([...$values, ...$from] as $column) {
            $this->storage($column);
        }
        $idColumns ??= array_values(array_diff($this->columns(), $from, $values));
        foreach ($idColumns as $column) {
            $this->storage($column);
        }

        // The spec row (new column) for each names_from combination and value column.
        $target = [];
        foreach ($spec as $row) {
            $target[self::hashKey(array_map(fn (string $f) => FactorColumn::label($row[$f]), $from))][$row['.value']] = $row['.name'];
        }

        $idValues = array_map(fn (string $c) => $this->column($c), $idColumns);
        $fromValues = array_map(fn (string $c) => $this->column($c), $from);
        $groups = [];
        $firstRow = [];
        $cells = [];
        $duplicates = false;
        for ($i = 0; $i < $this->rows; $i++) {
            $id = self::hashKey(array_map(fn (array $v) => $v[$i], $idValues));
            if (! isset($groups[$id])) {
                $groups[$id] = count($groups);
                $firstRow[] = $i;
            }
            $g = $groups[$id];
            $key = self::hashKey(array_map(fn (array $v) => FactorColumn::label($v[$i]), $fromValues));
            foreach ($values as $v) {
                $name = $target[$key][$v] ?? null;
                if ($name === null) {
                    continue; // combinations missing from the spec are dropped, as in tidyr
                }
                $cells[$name][$g][] = $i;
                $duplicates = $duplicates || count($cells[$name][$g]) > 1;
            }
        }

        $data = [];
        foreach ($idColumns as $k => $column) {
            $data[$column] = $this->data[$column]->take($firstRow);
        }
        $count = count($firstRow);
        foreach ($spec as $row) {
            $name = $row['.name'];
            $source = $this->data[$row['.value']];
            $sourceValues = $source->values();
            if ($duplicates) {
                $list = [];
                for ($g = 0; $g < $count; $g++) {
                    $rows = $cells[$name][$g] ?? null;
                    $list[] = $rows === null ? null : array_map(fn (int $i) => $sourceValues[$i], $rows);
                }
                $data[$name] = new ArrayColumn(Type::List, $list);

                continue;
            }
            $indices = [];
            for ($g = 0; $g < $count; $g++) {
                $indices[] = $cells[$name][$g][0] ?? null;
            }
            $column = $source->take($indices);
            if (array_key_exists($row['.value'], $valuesFill) && in_array(null, $indices, true)) {
                $filled = $column->values();
                foreach ($indices as $g => $i) {
                    if ($i === null) {
                        $filled[$g] = $valuesFill[$row['.value']];
                    }
                }
                $column = $column instanceof FactorColumn
                    ? FactorColumn::fromValues($filled, $column->levels(), $column->isOrdered())
                    : Infer::column($filled, $source->type(), $name);
            }
            $data[$name] = $column;
        }

        if ($duplicates) {
            $value = $values[0];
            $by = implode(', ', [...$idColumns, ...$from]);
            trigger_error(
                "Values from `{$value}` are not uniquely identified; output will contain list-cols.\n"
                ."• Use `values_fn = list` to suppress this warning.\n"
                ."• Use `values_fn = {summary_fun}` to summarise duplicates.\n"
                ."• Use the following dplyr code to identify duplicates.\n"
                ."  {data} |>\n"
                ."  dplyr::summarise(n = dplyr::n(), .by = c({$by})) |>\n"
                .'  dplyr::filter(n > 1L)',
                E_USER_WARNING,
            );
        }

        return new self($data, $count, array_values(array_intersect($this->groups, $idColumns)));
    }

    /**
     * Validate a spec. Longer: [sources, value columns, name parts, unique
     * name-part combinations in spec order]. Wider: [new names, value
     * columns, names_from columns].
     *
     * @param list<array<string, mixed>> $spec
     */
    private static function readSpec(array $spec, string $kind): array
    {
        if ($spec === [] || ! array_is_list($spec)) {
            throw new InvalidArgumentException('A pivot spec is a non-empty list of rows.');
        }
        $keys = array_keys($spec[0]);
        foreach ($spec as $row) {
            if (! isset($row['.name'], $row['.value']) || array_keys($row) !== $keys) {
                throw new InvalidArgumentException('Every spec row needs .name, .value and the same name columns, in the same order.');
            }
        }
        $names = array_map(fn (array $r) => (string) $r['.name'], $spec);
        if (count(array_unique($names)) !== count($names)) {
            throw new InvalidArgumentException('Spec .name values must be unique.');
        }
        $values = array_values(array_unique(array_map(fn (array $r) => (string) $r['.value'], $spec)));
        $parts = array_values(array_diff($keys, ['.name', '.value']));
        if ($kind === 'wider') {
            return [$names, $values, $parts];
        }

        $combos = [];
        foreach ($spec as $row) {
            $combo = [];
            foreach ($parts as $p) {
                $combo[$p] = $row[$p];
            }
            $combos[self::hashKey($combo)] = $combo;
        }

        return [$names, $values, $parts, array_values($combos)];
    }

    /**
     * One value column for pivotLongerSpec(): for every row, the values of
     * each source in turn (null source: NA), combined with vctrs rules.
     *
     * @param list<?string> $sources One per name-part combination.
     */
    private function combinedColumn(string $name, array $sources): Column
    {
        $present = array_values(array_filter($sources, fn ($s) => $s !== null));
        $types = array_values(array_unique(array_map(fn (string $s) => $this->data[$s]->type()->value, $present)));
        $type = match (true) {
            count($types) <= 1 => $types === [] ? Type::Bool : Type::from($types[0]),
            array_diff($types, ['bool', 'int', 'float']) === [] => in_array('float', $types, true) ? Type::Float : Type::Int,
            array_diff($types, ['date', 'datetime']) === [] => Type::DateTime,
            array_diff($types, ['factor', 'string']) === [] => Type::String, // vctrs: factor and text give text
            default => throw new InvalidArgumentException(sprintf(
                "Can't combine `%s` <%s> and `%s` <%s>.",
                $present[0], $this->data[$present[0]]->type()->abbreviation(),
                $present[array_key_last($present)], $this->data[$present[array_key_last($present)]]->type()->abbreviation(),
            )),
        };

        $columns = array_map(fn (?string $s) => $s === null ? null : $this->data[$s]->values(), $sources);
        $values = [];
        for ($i = 0; $i < $this->rows; $i++) {
            foreach ($columns as $column) {
                $values[] = $column === null ? null : $column[$i];
            }
        }
        $first = $present === [] ? null : $this->data[$present[0]];
        if ($type === Type::Factor && $first instanceof FactorColumn) {
            $levels = [];
            foreach ($present as $s) {
                array_push($levels, ...$this->data[$s]->levels());
            }

            return FactorColumn::fromValues($values, array_values(array_unique($levels)), $first->isOrdered());
        }

        return Infer::column($values, $type, $name);
    }

    // ---------------------------------------------------------------
    // Types and NA
    // ---------------------------------------------------------------

    /**
     * Convert columns to other types.
     *
     * @param array<string, Type|string> $types
     */
    public function cast(array $types): self
    {
        $frame = $this;
        foreach ($types as $name => $type) {
            $frame = $frame->withColumn($name, Infer::convert($this->storage($name), $type, $name));
        }

        return $frame;
    }

    /**
     * Turn a column into a factor, as R's factor(). Without levels, they are
     * the sorted unique values. Values not in $levels become NA.
     *
     * @param list<string|int|float>|null $levels
     */
    public function asFactor(string $name, ?array $levels = null, bool $ordered = false): self
    {
        return $this->withColumn($name, FactorColumn::fromValues($this->column($name), $levels, $ordered));
    }

    /** @param list<string|int|float>|null $levels */
    public function asOrdered(string $name, ?array $levels = null): self
    {
        return $this->asFactor($name, $levels ?? ($this->storage($name) instanceof FactorColumn ? $this->levels($name) : null), true);
    }

    public function isOrdered(string $name): bool
    {
        $column = $this->storage($name);

        return $column instanceof FactorColumn && $column->isOrdered();
    }

    /** fct_relevel(): move levels to the front, or after a 0-based position (-1 for the end). */
    public function fctRelevel(string $name, array $levels, int $after = 0): self
    {
        return $this->withColumn($name, $this->factor($name)->relevel($levels, $after));
    }

    /** factor(x, levels = ...): set the full level order. Unlisted values become NA. */
    public function fctLevels(string $name, array $levels): self
    {
        return $this->withColumn($name, $this->factor($name)->withLevels($levels));
    }

    /** fct_rev() */
    public function fctRev(string $name): self
    {
        return $this->withColumn($name, $this->factor($name)->reverse());
    }

    /** fct_inorder() */
    public function fctInorder(string $name): self
    {
        return $this->withColumn($name, $this->factor($name)->inOrder());
    }

    /** fct_infreq() */
    public function fctInfreq(string $name): self
    {
        return $this->withColumn($name, $this->factor($name)->inFrequency());
    }

    /**
     * fct_reorder(): order levels by a summary (default median) of another column.
     *
     * @param Closure(list<mixed>): mixed|null $summary
     */
    public function fctReorder(string $name, string $by, ?Closure $summary = null, bool $descending = false): self
    {
        return $this->withColumn($name, $this->factor($name)->reorder($this->column($by), $summary, $descending));
    }

    /** fct_recode(): rename levels, new => old. */
    public function fctRecode(string $name, array $map): self
    {
        return $this->withColumn($name, $this->factor($name)->recode($map));
    }

    /** fct_collapse(): merge levels, new => list of old. */
    public function fctCollapse(string $name, array $groups): self
    {
        return $this->withColumn($name, $this->factor($name)->collapse($groups));
    }

    /**
     * fct_drop() / droplevels(): remove unused levels. With no name, every
     * factor column.
     *
     * @param list<string>|null $only
     */
    public function fctDrop(?string $name = null, ?array $only = null): self
    {
        $names = $name === null
            ? array_keys(array_filter($this->data, fn (Column $c) => $c instanceof FactorColumn))
            : [$name];
        $frame = $this;
        foreach ($names as $n) {
            $frame = $frame->withColumn((string) $n, $frame->factor((string) $n)->dropUnused($only));
        }

        return $frame;
    }

    private function factor(string $name): FactorColumn
    {
        $column = $this->storage($name);

        return $column instanceof FactorColumn
            ? $column
            : FactorColumn::fromValues($column->values());
    }

    /**
     * Drop rows with NA in any of the named columns (all columns when empty).
     *
     * @param list<string> $names
     */
    public function dropNa(array $names = []): self
    {
        $names = $names === [] ? $this->columns() : $names;
        $columns = array_map(fn (string $n) => $this->column($n), $names);
        $keep = [];
        for ($i = 0; $i < $this->rows; $i++) {
            if (array_all($columns, fn (array $values) => $values[$i] !== null)) {
                $keep[] = $i;
            }
        }

        return $this->take($keep);
    }

    // ---------------------------------------------------------------
    // Stats handoff
    // ---------------------------------------------------------------

    /**
     * Rows of numeric columns as floats, the shape stats_* functions take
     * for x. Bool columns become 0.0/1.0; NA stays null.
     *
     * @param list<string> $names
     * @return list<list<float|null>>
     */
    public function toMatrix(array $names): array
    {
        $columns = [];
        foreach ($names as $name) {
            $column = $this->storage($name);
            if (! $column->type()->isNumeric()) {
                throw new InvalidArgumentException(
                    "Column '{$name}' is {$column->type()->value}; toMatrix() needs float, int or bool columns."
                );
            }
            $columns[] = array_map(fn ($v) => $v === null ? null : (float) $v, $column->values());
        }

        $matrix = [];
        for ($i = 0; $i < $this->rows; $i++) {
            $matrix[] = array_map(fn (array $values) => $values[$i], $columns);
        }

        return $matrix;
    }

    /**
     * A numeric column as floats, the shape stats_* functions take for y.
     *
     * @return list<float|null>
     */
    public function toVector(string $name): array
    {
        return array_map(fn (array $row) => $row[0], $this->toMatrix([$name]));
    }

    /**
     * Columns for model fitting (stats_lm, stats_glm, stats_model_matrix):
     *
     * - float, int: list<float|int|null>
     * - bool: list<bool|null> (a two-level contrast, as in R)
     * - string: list<string|null> (the model sorts levels, as R does)
     * - date: days since 1970-01-01; datetime: seconds since epoch
     * - factor: ['levels' => list<string>, 'codes' => list<?int>, 'ordered' => bool],
     *   with 0-based codes, null for NA, and every level in frame order
     *
     * List-columns cannot be modelled and throw.
     *
     * @param list<string>|null $names Columns to include; all by default.
     * @return array<string, list<mixed>|array{levels: list<string>, codes: list<?int>, ordered: bool}>
     */
    public function toModelData(?array $names = null): array
    {
        $out = [];
        foreach ($names ?? $this->columns() as $name) {
            $column = $this->storage($name);
            $out[$name] = match ($column->type()) {
                Type::Factor => [
                    'levels' => $column->levels(),
                    'codes' => $column->codes(),
                    'ordered' => $column->isOrdered(),
                ],
                Type::Date, Type::DateTime => Infer::convert($column, Type::Float, $name)->values(),
                Type::List => throw new InvalidArgumentException(
                    "Column '{$name}' is a list-column and cannot be used in a model."
                ),
                default => $column->values(),
            };
        }

        return $out;
    }

    // ---------------------------------------------------------------
    // Writing
    // ---------------------------------------------------------------

    /**
     * Write CSV like readr::write_csv(): NA as "NA", quoting only where
     * needed, ISO dates, UTC datetimes with "Z", TRUE/FALSE, and numbers
     * in readr's shortest form. $quote is 'needed', 'all' or 'none';
     * $escape is 'double', 'backslash' or 'none'. Appending skips the
     * header unless $colNames is true.
     */
    public function writeCsv(
        string $path,
        string $delimiter = ',',
        string $na = 'NA',
        bool $append = false,
        ?bool $colNames = null,
        string $quote = 'needed',
        string $escape = 'double',
        string $eol = "\n",
    ): void {
        $csv = CsvWriter::format($this, $delimiter, $na, $colNames ?? ! $append, $quote, $escape, $eol);
        if (file_put_contents($path, $csv, $append ? FILE_APPEND : 0) === false) {
            throw new \RuntimeException("Cannot write CSV file '{$path}'.");
        }
    }

    /** The CSV text writeCsv() would write. */
    public function toCsv(
        string $delimiter = ',',
        string $na = 'NA',
        bool $colNames = true,
        string $quote = 'needed',
        string $escape = 'double',
        string $eol = "\n",
    ): string {
        return CsvWriter::format($this, $delimiter, $na, $colNames, $quote, $escape, $eol);
    }

    /**
     * JSON as a list of records ('records') or an object of columns
     * ('columns'). NA is null (records keep every key, unlike jsonlite);
     * dates are "Y-m-d", datetimes ISO 8601 in UTC with "Z", factors their
     * labels, NaN and infinities null, nested frames nested JSON.
     */
    public function toJson(string $orient = 'records', int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): string
    {
        return Json::write($this, $orient, $flags);
    }

    /**
     * An Illuminate\Support\Collection of row arrays. Needs Laravel's
     * illuminate/support (or illuminate/collections) to be installed.
     */
    public function toCollection(): object
    {
        return Collections::write($this);
    }

    // ---------------------------------------------------------------
    // Printing
    // ---------------------------------------------------------------

    public function __toString(): string
    {
        return $this->format();
    }

    /**
     * Print like a tibble. By default all rows are shown when there are at
     * most 20, otherwise the first 10.
     */
    public function format(?int $n = null, int $width = 80): string
    {
        return Printer::render($this, $n, $width);
    }

    /** One line per column with its type and leading values, as tibble's glimpse(). */
    public function glimpse(int $width = 80): string
    {
        return Glimpse::render($this, $width);
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /**
     * Rows per group, with groups sorted by key as dplyr does (ascending,
     * factors by level, NA last, strings in byte order). Ungrouped frames
     * have one group holding every row; rowwise frames one group per row.
     *
     * @return list<array{0: array<string, mixed>, 1: list<int>}>
     */
    private function groupIndex(): array
    {
        // DataFrames are immutable, so each frame's index is computed once.
        static $cache = null;
        $cache ??= new \WeakMap();

        return $cache[$this] ??= $this->buildGroupIndex();
    }

    /** @return list<array{0: array<string, mixed>, 1: list<int>}> */
    private function buildGroupIndex(): array
    {
        $all = $this->rows === 0 ? [] : range(0, $this->rows - 1);
        if ($this->rowwise) {
            return array_map(fn (int $i) => [array_map(fn (string $g) => $this->data[$g]->get($i), array_combine($this->groups, $this->groups)), [$i]], $all);
        }
        if ($this->groups === []) {
            return [[[], $all]];
        }
        if (! $this->drop) {
            return $this->expandGroups($this->groups, $all, []);
        }

        // Bucket rows by an encoded key: one hashable value per row.
        $encoded = array_map(fn (string $g) => $this->encodeKeys($g), $this->groups);
        $rowsByKey = [];
        if (count($encoded) === 1) {
            foreach ($encoded[0] as $i => $key) {
                $rowsByKey[$key][] = $i;
            }
        } else {
            for ($i = 0; $i < $this->rows; $i++) {
                // Length-prefixed parts: values can't run together into another key.
                $key = '';
                foreach ($encoded as $column) {
                    $part = (string) $column[$i];
                    $key .= strlen($part).':'.$part;
                }
                $rowsByKey[$key][] = $i;
            }
        }

        $values = array_map(fn (string $g) => $this->data[$g]->values(), $this->groups);
        $index = [];
        foreach ($rowsByKey as $rows) {
            $first = $rows[0];
            $key = [];
            foreach ($this->groups as $k => $g) {
                $key[$g] = $values[$k][$first];
            }
            $index[] = [$key, $rows];
        }

        $sorts = array_map(fn (string $g) => $this->sortable($g), $this->groups);
        usort($index, function (array $a, array $b) use ($sorts): int {
            foreach ($sorts as [$values, $isString]) {
                $cmp = self::compare($values[$a[1][0]], $values[$b[1][0]], $isString, false);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return 0;
        });

        return $index;
    }

    /**
     * One hashable value per row for grouping: factor codes, ints and
     * strings as they are, floats and dates as text, NA as a sentinel.
     *
     * @return list<int|string>
     */
    private function encodeKeys(string $name): array
    {
        $column = $this->data[$name];
        // NA is a lone NUL; text is prefixed, so no string can equal NA's key.
        $na = "\0";
        if ($column instanceof FactorColumn) {
            return array_map(fn (?int $c) => $c ?? $na, $column->codes());
        }
        if ($column instanceof TemporalColumn) {
            return array_map(fn (?int $t) => $t ?? $na, $column->ticks());
        }

        return match ($column->type()) {
            Type::Int => array_map(fn (?int $v) => $v ?? $na, $column->values()),
            Type::String => array_map(fn (?string $v) => $v === null ? $na : 's'.$v, $column->values()),
            Type::Bool => array_map(fn (?bool $v) => $v === null ? $na : (int) $v, $column->values()),
            Type::Float => array_map(fn (?float $v) => match (true) {
                $v === null => $na,
                is_nan($v) => 'fNaN',
                $v == 0.0 => 'f0',
                default => 'f'.var_export($v, true),
            }, $column->values()),
            Type::Date, Type::DateTime => array_map(fn ($v) => $v === null ? $na : 'd'.$v->format('U.u'), $column->values()),
            default => array_map(fn ($v) => self::hashKey([$v]), $column->values()),
        };
    }
    /**
     * Groups for groupBy(..., drop: false), as dplyr builds them: a factor
     * key takes every level (plus NA if present), a non-factor key its
     * observed values; under an empty group a non-factor key is NA.
     *
     * @param list<string> $names
     * @param list<int> $rows
     * @param array<string, mixed> $prefix
     * @return list<array{0: array<string, mixed>, 1: list<int>}>
     */
    private function expandGroups(array $names, array $rows, array $prefix): array
    {
        if ($names === []) {
            return [[$prefix, $rows]];
        }
        $name = $names[0];
        $rest = array_slice($names, 1);
        $column = $this->data[$name];
        $values = $column->values();

        // Rows by value, in sorted order with NA last.
        $buckets = [];
        foreach ($rows as $i) {
            $buckets[self::hashKey([$values[$i]])][] = $i;
        }
        if ($column instanceof FactorColumn) {
            $keys = $column->levels();
            if (array_any($rows, fn (int $i) => $values[$i] === null)) {
                $keys[] = null;
            }
        } elseif ($rows === []) {
            $keys = [null];
        } else {
            $present = array_map(fn (array $b) => $b[0], array_values($buckets));
            [$sortable, $isString] = $this->sortable($name);
            usort($present, fn (int $a, int $b) => self::compare($sortable[$a], $sortable[$b], $isString, false));
            $keys = array_map(fn (int $i) => $values[$i], $present);
        }

        $out = [];
        foreach ($keys as $key) {
            $subset = $buckets[self::hashKey([$key])] ?? [];
            array_push($out, ...$this->expandGroups($rest, $subset, [...$prefix, $name => $key]));
        }

        return $out;
    }

    /**
     * A key column rebuilt from group key values, keeping the source type
     * (and factor levels).
     *
     * @param list<mixed> $values
     */
    private function keyColumn(string $name, array $values): Column
    {
        $source = $this->data[$name];

        return $source instanceof FactorColumn
            ? FactorColumn::fromValues($values, $source->levels(), $source->isOrdered())
            : Infer::column($values, $source->type(), $name);
    }

    /**
     * Values to sort a column by: factor codes, else the values.
     *
     * @return array{0: list<mixed>, 1: bool}
     */
    private function sortable(string $name): array
    {
        $column = $this->storage($name);
        if ($column->type() === Type::List) {
            throw new InvalidArgumentException("Can't sort by list-column '{$name}'.");
        }

        return [
            match (true) {
                $column instanceof FactorColumn => $column->codes(),
                $column instanceof TemporalColumn => $column->ticks(),
                default => $column->values(),
            },
            $column->type() === Type::String,
        ];
    }

    /** NA sorts last in both directions. */
    private static function compare(mixed $x, mixed $y, bool $isString, bool $desc): int
    {
        if ($x === null || $y === null) {
            return ($x === null) <=> ($y === null);
        }
        // NaN sorts after numbers and before NA, in both directions.
        $xNan = is_float($x) && is_nan($x);
        $yNan = is_float($y) && is_nan($y);
        if ($xNan || $yNan) {
            return $xNan <=> $yNan;
        }
        $cmp = $isString ? strcmp($x, $y) <=> 0 : $x <=> $y;

        return $desc ? -$cmp : $cmp;
    }

    /** @param array<string, mixed> $values */
    private static function hashKey(array $values): string
    {
        return serialize(array_map(
            fn ($v) => match (true) {
                $v === 0.0 => 0.0, // -0.0 equals 0.0, as in R
                is_float($v) && is_nan($v) => 'NaN',
                $v instanceof DateTimeInterface => 'date:'.$v->format('Y-m-d\\TH:i:s.uP'),
                $v instanceof Table => 'table:'.serialize($v->toColumns()),
                is_object($v) => 'object:'.spl_object_id($v),
                default => $v,
            },
            array_values($values),
        ));
    }

    /**
     * A copy with some parts replaced; grouping and rowwise carry over.
     *
     * @param array<string, Column>|null $data
     * @param list<string>|null $groups
     */
    private function copy(?array $data = null, ?int $rows = null, ?array $groups = null, ?bool $rowwise = null, ?bool $drop = null): self
    {
        return new self(
            $data ?? $this->data,
            $rows ?? $this->rows,
            $groups ?? $this->groups,
            $rowwise ?? $this->rowwise,
            $drop ?? $this->drop,
        );
    }

    /**
     * Rows as a new ungrouped frame, as handed to group closures.
     *
     * @param list<int> $indices
     */
    /**
     * A factor column for an expression that passes a factor's values
     * through (col(), first(), lag(), if_else() over factors, ...), so the
     * result stays a factor with the same levels, as in R. Null otherwise.
     *
     * @param list<mixed>|Column $values
     */
    private function factorResult(mixed $definition, array|Column $values): ?FactorColumn
    {
        if (! $definition instanceof Expr || $values instanceof Column) {
            return null;
        }
        $sources = self::passThrough($definition);
        if ($sources === null || $sources === []) {
            return null;
        }
        $factors = [];
        foreach (array_unique($sources) as $name) {
            $column = $this->data[$name] ?? null;
            if (! $column instanceof FactorColumn) {
                return null;
            }
            $factors[] = $column;
        }
        $first = $factors[0];
        foreach ($factors as $factor) {
            if ($factor->levels() !== $first->levels() || $factor->isOrdered() !== $first->isOrdered()) {
                return null;
            }
        }

        return FactorColumn::fromValues($values, $first->levels(), $first->isOrdered());
    }

    /**
     * The columns whose values an expression returns unchanged, or null when
     * it computes new values.
     *
     * @return list<string>|null
     */
    private static function passThrough(Expr $expr): ?array
    {
        $all = function (array $exprs): ?array {
            $names = [];
            foreach ($exprs as $e) {
                $part = self::passThrough($e);
                if ($part === null) {
                    return null;
                }
                array_push($names, ...$part);
            }

            return $names;
        };

        return match (true) {
            $expr instanceof Col => [$expr->name],
            $expr instanceof Lit => $expr->value === null ? [] : null,
            $expr instanceof Call => match ($expr->fn) {
                'first', 'last', 'nth', 'lag', 'lead', 'na_if' => $all([$expr->args[0]]),
                'if_else' => $all([$expr->args[1], $expr->args[2], ...($expr->option('missing') ? [$expr->option('missing')] : [])]),
                'coalesce' => $all($expr->args),
                'case_when' => $all([
                    ...array_values(array_filter($expr->args, fn ($a, $i) => $i % 2 === 1, ARRAY_FILTER_USE_BOTH)),
                    ...($expr->option('default') ? [$expr->option('default')] : []),
                ]),
                default => null,
            },
            default => null,
        };
    }

    /**
     * An evaluator over the given rows that slices only the columns an
     * expression reads. All rows in order means the whole columns.
     *
     * @param list<int> $indices
     */
    private function evaluator(array $indices): Evaluator
    {
        $whole = count($indices) === $this->rows;

        return Evaluator::over(function (string $name) use ($indices, $whole): array {
            $storage = $this->storage($name);
            if ($storage instanceof TemporalColumn) {
                // Build objects for these rows only.
                return ($whole ? $storage : $storage->take($indices))->values();
            }
            $values = $this->wholeColumn($name);
            if ($whole) {
                return $values;
            }
            $slice = [];
            foreach ($indices as $i) {
                $slice[] = $values[$i];
            }

            return $slice;
        }, count($indices), function (string $name) use ($indices, $whole): ?array {
            // Factors rank and order by level, so give their codes for sorting.
            $storage = $this->storage($name);
            if (! $storage instanceof FactorColumn) {
                return null;
            }

            return $whole ? $storage->codes() : $storage->take($indices)->codes();
        });
    }

    /** A column's values, computed once per frame (factor labels are built on demand). */
    private function wholeColumn(string $name): array
    {
        static $cache = null;
        $cache ??= new \WeakMap();
        $columns = $cache[$this] ?? [];
        if (! array_key_exists($name, $columns)) {
            $columns[$name] = $this->storage($name)->values();
            $cache[$this] = $columns;
        }

        return $columns[$name];
    }

    /**
     * n(), sum(), mean(), min() and max() of a numeric column, per group,
     * in one pass with the evaluator's semantics. Null when the expression
     * is anything else.
     *
     * @param list<array{0: array<string, mixed>, 1: list<int>}> $index
     * @return list<mixed>|null
     */
    private function aggregateKernel(Expr $expr, array $index): ?array
    {
        if (! $expr instanceof Call || ! in_array($expr->fn, ['n', 'sum', 'mean', 'min', 'max'], true)) {
            return null;
        }
        if ($expr->fn === 'n') {
            return array_map(fn (array $g) => count($g[1]), $index);
        }
        $arg = $expr->args[0];
        $naRm = $expr->option('na_rm');
        if (! $arg instanceof Col || ($naRm !== null && ! ($naRm instanceof Lit && is_bool($naRm->value)))
            || ! isset($this->data[$arg->name]) || ! in_array($this->data[$arg->name]->type(), [Type::Int, Type::Float], true)) {
            return null;
        }
        $naRm = $naRm?->value === true;
        $values = $this->data[$arg->name]->values();
        $isInt = $this->data[$arg->name]->type() === Type::Int;

        $out = [];
        foreach ($index as [$_, $rows]) {
            $acc = null;
            $count = 0;
            $na = false;
            foreach ($rows as $i) {
                $v = $values[$i];
                if ($v === null) {
                    $na = true;
                    if (! $naRm) {
                        break;
                    }

                    continue;
                }
                $count++;
                $acc = match ($expr->fn) {
                    'sum', 'mean' => ($acc ?? 0) + $v,
                    // NaN wins once seen, as in R: min(c(1, NaN)) is NaN.
                    'min' => $acc === null || (is_float($v) && is_nan($v)) || (! (is_float($acc) && is_nan($acc)) && $v < $acc) ? $v : $acc,
                    'max' => $acc === null || (is_float($v) && is_nan($v)) || (! (is_float($acc) && is_nan($acc)) && $v > $acc) ? $v : $acc,
                };
            }
            $out[] = match (true) {
                $na && ! $naRm => null,
                $expr->fn === 'sum' => $acc ?? ($isInt ? 0 : 0.0),
                $expr->fn === 'mean' => $count === 0 ? NAN : (float) $acc / $count,
                $expr->fn === 'min' => $acc ?? INF,
                $expr->fn === 'max' => $acc ?? -INF,
            };
        }

        return $out;
    }

    private function subset(array $indices): self
    {
        return new self(array_map(fn (Column $c) => $c->take($indices), $this->data), count($indices));
    }

    /** @return list<mixed>|Column */
    private function evaluate(string $name, mixed $definition): array|Column
    {
        if ($definition instanceof Column) {
            return $definition;
        }
        if ($definition instanceof Expr) {
            $index = $this->groupIndex();
            if (count($index) === 1 && count($index[0][1]) === $this->rows) {
                return $this->evaluator($index[0][1])->vector($definition);
            }
            $values = array_fill(0, $this->rows, null);
            foreach ($index as [$_, $indices]) {
                foreach ($this->evaluator($indices)->vector($definition) as $j => $value) {
                    $values[$indices[$j]] = $value;
                }
            }

            return $values;
        }
        if (is_array($definition)) {
            if (! array_is_list($definition) || count($definition) !== $this->rows) {
                throw new InvalidArgumentException(
                    "mutate() '{$name}' needs a list of {$this->rows} values, got ".count($definition).'.'
                );
            }

            return $definition;
        }
        if (! $definition instanceof Closure) {
            // A scalar is repeated for every row.
            return array_fill(0, $this->rows, $definition);
        }

        $values = array_fill(0, $this->rows, null);
        foreach ($this->groupIndex() as [$_, $indices]) {
            $group = $this->subset($indices);
            foreach ($group->toRows() as $j => $row) {
                $values[$indices[$j]] = $definition(new Row($row), $group);
            }
        }

        return $values;
    }
}
