<?php

declare(strict_types=1);

namespace Phibble;

use Closure;
use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use PDO;
use Phibble\Column\Column;
use Phibble\Column\FactorColumn;
use Phibble\Column\Infer;
use Phibble\Contracts\Table;
use Phibble\Exception\ColumnNotFound;
use Phibble\Source\CsvSource;
use Phibble\Source\PdoSource;
use Phibble\Source\QuerySource;
use Phibble\Source\Source;
use Stringable;

/**
 * A typed, immutable, in-memory data frame.
 */
final readonly class Frame implements Table, Stringable
{
    /**
     * @param array<string, Column> $data
     * @param list<string> $groups
     */
    private function __construct(
        private array $data,
        private int $rows,
        private array $groups = [],
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
     * Read a CSV file with a header row. Types are inferred from the text;
     * "" and "NA" are NA unless $na says otherwise.
     *
     * @param array<string, Type|string> $types
     * @param list<string> $na
     */
    public static function fromCsv(string $path, array $types = [], string $delimiter = ',', array $na = ['', 'NA']): self
    {
        return CsvSource::file($path, $types, $delimiter, $na)->read();
    }

    /**
     * @param array<string, Type|string> $types
     * @param list<string> $na
     */
    public static function fromCsvString(string $csv, array $types = [], string $delimiter = ',', array $na = ['', 'NA']): self
    {
        return CsvSource::string($csv, $types, $delimiter, $na)->read();
    }

    /**
     * Run a query and load the result.
     *
     * @param array<int|string, mixed> $bindings Positional or named.
     * @param array<string, Type|string> $types
     */
    public static function fromPdo(PDO $pdo, string $sql, array $bindings = [], array $types = []): self
    {
        return new PdoSource($pdo, $sql, $bindings, $types)->read();
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

            $types = array_unique(array_map(fn (Column $c) => $c->type()->value, $columns));
            $data[$name] = match (true) {
                $types === [Type::Factor->value] => FactorColumn::fromValues(
                    $values,
                    count(array_unique(array_map(fn (FactorColumn $c) => serialize($c->levels()), $columns))) === 1
                        ? $columns[0]->levels()
                        : null,
                ),
                count($types) === 1 => Infer::column($values, $columns[0]->type(), $name),
                default => Infer::column($values, null, $name),
            };
        }

        $rows = array_sum(array_map(fn (self $f) => $f->rows, $frames));
        $groups = array_values(array_filter($frames[0]->groups, fn (string $g) => in_array($g, $names, true)));

        return new self($data, $rows, $groups);
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

        return new self($data, $this->rows, $this->groups);
    }

    public function filter(Closure $predicate): static
    {
        $keep = [];
        foreach ($this->groupIndex() as [$_, $indices]) {
            $group = $this->take($indices, []);
            foreach ($group->toRows() as $j => $row) {
                if ($predicate(new Row($row), $group)) {
                    $keep[] = $indices[$j];
                }
            }
        }
        sort($keep);

        return $this->take($keep, $this->groups);
    }

    public function mutate(array $columns): static
    {
        $frame = $this;
        foreach ($columns as $name => $definition) {
            $frame = $frame->withColumn((string) $name, $frame->evaluate((string) $name, $definition));
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

        return new self($data, $column->count(), $this->groups);
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

        return new self($data, $this->rows, $groups);
    }

    public function arrange(array $keys): static
    {
        $sorts = [];
        foreach ($keys as $key) {
            [$name, $direction] = is_array($key) ? [$key[0], $key[1] ?? 'asc'] : [$key, 'asc'];
            $direction = strtolower($direction);
            if (! in_array($direction, ['asc', 'desc'], true)) {
                throw new InvalidArgumentException("Sort direction must be 'asc' or 'desc', got '{$direction}'.");
            }
            $column = $this->storage($name);
            $values = $column instanceof FactorColumn ? $column->codes() : $column->values();
            $sorts[] = [$values, $direction === 'desc', $column->type() === Type::String];
        }

        $order = range(0, $this->rows - 1);
        if ($this->rows === 0) {
            $order = [];
        }
        usort($order, function (int $a, int $b) use ($sorts): int {
            foreach ($sorts as [$values, $desc, $isString]) {
                $x = $values[$a];
                $y = $values[$b];
                if ($x === null || $y === null) {
                    if ($x === $y) {
                        continue;
                    }

                    return $x === null ? 1 : -1;
                }
                $cmp = $isString ? strcmp($x, $y) : $x <=> $y;
                if ($cmp !== 0) {
                    return $desc ? -$cmp : $cmp;
                }
            }

            return $a <=> $b;
        });

        return $this->take($order, $this->groups);
    }

    public function groupBy(array $names): static
    {
        $names = array_values(array_unique($names));
        foreach ($names as $name) {
            $this->storage($name);
        }

        return new self($this->data, $this->rows, $names);
    }

    public function ungroup(): static
    {
        return new self($this->data, $this->rows);
    }

    public function groups(): array
    {
        return $this->groups;
    }

    public function eachGroup(): iterable
    {
        foreach ($this->groupIndex() as [$key, $indices]) {
            yield ['key' => $key, 'table' => $this->take($indices, [])];
        }
    }

    public function summarise(array $aggregates): static
    {
        $index = $this->groupIndex();
        $first = array_map(fn (array $g) => $g[1][0] ?? 0, $index);

        $data = [];
        foreach ($this->groups as $name) {
            $data[$name] = $this->data[$name]->take($first);
        }

        $results = array_fill_keys(array_keys($aggregates), []);
        foreach ($index as [$_, $indices]) {
            $group = $this->take($indices, []);
            foreach ($aggregates as $name => $aggregate) {
                $value = $aggregate($group);
                if (is_array($value)) {
                    throw new InvalidArgumentException("summarise() '{$name}' must return a single value, not an array.");
                }
                $results[$name][] = $value;
            }
        }
        foreach ($results as $name => $values) {
            $data[(string) $name] = Infer::column($values, null, (string) $name);
        }

        return new self($data, count($index), array_slice($this->groups, 0, -1));
    }

    /** Alias of summarise(). */
    public function summarize(array $aggregates): static
    {
        return $this->summarise($aggregates);
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

        return $selected->take($keep, $this->groups);
    }

    public function slice(int $offset, ?int $length = null): static
    {
        $indices = array_slice(range(0, max(0, $this->rows - 1)), $offset, $length);
        if ($this->rows === 0) {
            $indices = [];
        }

        return $this->take($indices, $this->groups);
    }

    public function head(int $n = 6): static
    {
        return $this->slice(0, max(0, $n));
    }

    public function tail(int $n = 6): self
    {
        return $this->slice(max(0, $this->rows - $n));
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

        return $this->take($keep, $this->groups);
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

    // ---------------------------------------------------------------
    // Printing
    // ---------------------------------------------------------------

    public function __toString(): string
    {
        return $this->format();
    }

    public function format(int $maxRows = 10): string
    {
        $lines = ["# A phibble: {$this->rows} × {$this->ncol()}"];
        if ($this->groups !== []) {
            $lines[] = '# Groups: '.implode(', ', $this->groups).' ['.count($this->groupIndex()).']';
        }
        if ($this->data === []) {
            return implode("\n", $lines);
        }

        $shown = $this->head($maxRows);
        $table = [['', ...$this->columns()], ['', ...array_values(array_map(fn (Type $t) => "<{$t->abbreviation()}>", $this->types()))]];
        foreach ($shown->toRows() as $i => $row) {
            $table[] = [(string) ($i + 1), ...array_map(self::formatValue(...), array_values($row))];
        }

        $widths = [];
        foreach ($table as $cells) {
            foreach ($cells as $c => $cell) {
                $widths[$c] = max($widths[$c] ?? 0, mb_strlen($cell));
            }
        }
        foreach ($table as $cells) {
            $padded = array_map(fn (string $cell, int $c) => mb_str_pad($cell, $widths[$c]), $cells, array_keys($cells));
            $lines[] = rtrim(implode(' ', $padded));
        }
        if ($this->rows > $maxRows) {
            $lines[] = '# ℹ '.($this->rows - $maxRows).' more rows';
        }

        return implode("\n", $lines);
    }

    private static function formatValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'NA',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_float($value) => is_finite($value) ? sprintf('%.6g', $value) : (string) $value,
            $value instanceof DateTimeInterface => $value->format('H:i:s') === '00:00:00'
                ? $value->format('Y-m-d')
                : $value->format('Y-m-d H:i:s'),
            default => (string) $value,
        };
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /**
     * Rows per group in order of first appearance. Ungrouped frames have
     * one group holding every row.
     *
     * @return list<array{0: array<string, mixed>, 1: list<int>}>
     */
    private function groupIndex(): array
    {
        if ($this->groups === []) {
            return [[[], $this->rows === 0 ? [] : range(0, $this->rows - 1)]];
        }

        $keys = array_map(fn (string $g) => $this->data[$g]->values(), array_combine($this->groups, $this->groups));
        $index = [];
        for ($i = 0; $i < $this->rows; $i++) {
            $key = array_map(fn (array $values) => $values[$i], $keys);
            $hash = self::hashKey($key);
            $index[$hash] ??= [$key, []];
            $index[$hash][1][] = $i;
        }

        return array_values($index);
    }

    /** @param array<string, mixed> $values */
    private static function hashKey(array $values): string
    {
        return serialize(array_map(
            fn ($v) => match (true) {
                $v instanceof DateTimeInterface => 'date:'.$v->format('Y-m-d\TH:i:s.uP'),
                $v instanceof Table => 'table:'.serialize($v->toColumns()),
                is_object($v) => 'object:'.spl_object_id($v),
                default => $v,
            },
            array_values($values),
        ));
    }

    /**
     * @param list<int> $indices
     * @param list<string> $groups
     */
    private function take(array $indices, array $groups): self
    {
        return new self(
            array_map(fn (Column $c) => $c->take($indices), $this->data),
            count($indices),
            $groups,
        );
    }

    /** @return list<mixed>|Column */
    private function evaluate(string $name, mixed $definition): array|Column
    {
        if ($definition instanceof Column) {
            return $definition;
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
            $group = $this->take($indices, []);
            foreach ($group->toRows() as $j => $row) {
                $values[$indices[$j]] = $definition(new Row($row), $group);
            }
        }

        return $values;
    }
}
