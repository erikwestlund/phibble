<?php

declare(strict_types=1);

namespace Phibble\Sql;

use Closure;
use Generator;
use InvalidArgumentException;
use PDO;
use Phibble\Column\Infer;
use Phibble\Contracts\Table;
use Phibble\Exception\ColumnNotFound;
use Phibble\Expr\Call;
use Phibble\Expr\Col;
use Phibble\Expr\Desc;
use Phibble\Expr\Expr;
use Phibble\Expr\Lit;
use Phibble\Frame;
use Phibble\Join;
use Phibble\Source\PdoSource;
use Phibble\Source\QuerySource;
use Phibble\Type;

/**
 * A lazy table backed by a SQL query, like dbplyr's tbl(). Verbs given
 * Expr arguments compile to SQL; nothing runs until the rows are needed
 * (collect(), toRows(), nrow(), iteration, printing). A Closure argument,
 * or an expression with no faithful SQL translation, collects the rows
 * and continues in memory, so results always match Frame.
 *
 *     $visits = SqlTable::table($pdo, 'visits');
 *     $visits->filter(col('age')->ge(18))
 *         ->groupBy(['clinic'])
 *         ->summarise(['n' => n(), 'mean_bp' => mean(col('bp'), na_rm: true)])
 *         ->showQuery();
 */
final readonly class SqlTable implements Table
{
    /**
     * Hidden column carrying each row's position. SQL has no row order, so
     * it is assigned when the table is opened and carried through every
     * subquery: it orders collected rows, breaks ties after arrange() (which
     * is stable in dplyr), and orders window functions when nothing else does.
     */
    public const string ROW = '__phibble_row';

    /**
     * @param list<string> $columns
     * @param list<string> $groups
     * @param list<array{0: Expr, 1: bool}> $order
     * @param array<string, Type> $hints Types to restore on collect (e.g. 0/1 → bool).
     */
    private function __construct(
        private PDO $pdo,
        private Dialect $dialect,
        private Fragment $from,
        private array $columns,
        private array $groups = [],
        private array $order = [],
        private array $hints = [],
        private int $depth = 0,
        private array $rowKeys = [self::ROW],
    ) {}

    /** A table (or view) by name. */
    public static function table(PDO $pdo, string $name): self
    {
        $dialect = Dialect::for($pdo);
        $quoted = implode('.', array_map($dialect->quote(...), explode('.', $name)));

        return self::start($pdo, $dialect, Fragment::raw("SELECT * FROM {$quoted}"), $quoted);
    }

    /**
     * Any SELECT query with positional (?) or named (:name) bindings.
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function query(PDO $pdo, string $sql, array $bindings = []): self
    {
        return self::start($pdo, Dialect::for($pdo), self::positional($sql, $bindings));
    }

    public static function fromSource(PdoSource $source): self
    {
        return self::query($source->pdo, $source->sql, $source->bindings);
    }

    /** A Laravel or Doctrine DBAL query builder, kept lazy. */
    public static function fromQuery(object $builder, ?object $connection = null): self
    {
        $source = QuerySource::fromBuilder($builder, $connection);
        if (! $source instanceof PdoSource) {
            throw new InvalidArgumentException('Pass a PDO or DBAL connection to keep a Doctrine query lazy.');
        }

        return self::fromSource($source);
    }

    private static function start(PDO $pdo, Dialect $dialect, Fragment $from, ?string $table = null): self
    {
        $probe = $pdo->prepare("SELECT * FROM ({$from->sql}) AS phibble_probe LIMIT 0");
        self::bind($probe, $from->bindings);
        $probe->execute();
        $columns = [];
        for ($i = 0; $i < $probe->columnCount(); $i++) {
            $meta = $probe->getColumnMeta($i);
            $columns[] = $meta === false ? "column{$i}" : $meta['name'];
        }

        // The row's position: a stored table's physical row id when it has one
        // (free, in scan order), otherwise a row number over the source query.
        $row = $dialect->quote(self::ROW);
        $physical = $table === null ? null : match ($dialect->name()) {
            'SQLite' => 'rowid',
            'PostgreSQL' => 'ctid',
            default => null,
        };
        if ($physical !== null) {
            try {
                $pdo->query("SELECT {$physical} FROM {$table} LIMIT 0");
            } catch (\PDOException) {
                $physical = null; // a view, or a table without row ids
            }
        }
        $numbered = $physical !== null
            ? Fragment::raw("SELECT *, {$physical} AS {$row} FROM {$table}")
            : Fragment::format("SELECT *, ROW_NUMBER() OVER () AS {$row} FROM ({}) AS phibble_source", [$from]);

        return new self($pdo, $dialect, $numbered, $columns);
    }

    // ---------------------------------------------------------------
    // Inspection
    // ---------------------------------------------------------------

    /** The SQL that collect() would run, with ? placeholders. */
    public function showQuery(): string
    {
        return $this->render()->sql;
    }

    /** @return list<mixed> Bindings for showQuery(), in order. */
    public function bindings(): array
    {
        return $this->render()->bindings;
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    public function columns(): array
    {
        return $this->columns;
    }

    /** Types of the first 1000 rows, since SQL drivers report types unreliably. */
    public function types(): array
    {
        return $this->head(1000)->collect()->types();
    }

    public function nrow(): int
    {
        $sql = Fragment::format('SELECT COUNT(*) FROM ({}) AS phibble_count', [$this->from]);

        return (int) $this->run($sql)->fetchColumn();
    }

    public function ncol(): int
    {
        return count($this->columns);
    }

    public function count(): int
    {
        return $this->nrow();
    }

    public function column(string $name): array
    {
        $this->check([$name]);
        // Fetch one column but keep the row order, which may use other columns.
        $template = 'SELECT '.$this->dialect->quote($name).' FROM ({}) AS '.$this->alias().' ORDER BY {}';
        $parts = [$this->from, (new Compiler($this->dialect))->orderBy($this->rowOrder())];
        $rows = $this->run(Fragment::format($template, $parts))->fetchAll(PDO::FETCH_ASSOC);
        $hints = array_intersect_key($this->hints, [$name => true]);

        return $rows === []
            ? []
            : Frame::fromRows($rows, $hints, parseStrings: true)->column($name);
    }

    public function groups(): array
    {
        return $this->groups;
    }

    public function isRowwise(): bool
    {
        return false;
    }

    public function getIterator(): Generator
    {
        yield from $this->collect();
    }

    public function toRows(): array
    {
        return $this->collect()->toRows();
    }

    public function toColumns(): array
    {
        return $this->collect()->toColumns();
    }

    public function collect(): Frame
    {
        $statement = $this->run($this->render());
        // Build columns straight from positional rows; each column is then
        // typed in one pass (text from the driver is parsed, as readr would).
        $columns = array_fill(0, count($this->columns), []);
        while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            foreach ($row as $i => $value) {
                $columns[$i][] = $value;
            }
        }
        $data = [];
        foreach ($this->columns as $i => $name) {
            $data[$name] = $columns[$i] === []
                ? Infer::column([], $this->hints[$name] ?? null, $name)
                : Infer::fromDatabase($columns[$i], $this->hints[$name] ?? null, $name);
            $columns[$i] = null;
        }
        $frame = Frame::fromColumns($data);

        return $this->groups === [] ? $frame : $frame->groupBy($this->groups);
    }

    public function __toString(): string
    {
        return "# Source: SQL [{$this->dialect->name()}]\n".(string) $this->head(10)->collect();
    }

    // ---------------------------------------------------------------
    // Verbs
    // ---------------------------------------------------------------

    public function select(array $names): Table
    {
        $this->check($names);
        $names = [...array_values(array_diff($this->groups, $names)), ...array_values(array_unique($names))];
        $source = $this->keepOrder($names) === $this->order ? $this : $this->ordered();
        $list = Fragment::join(', ', array_map(fn (string $n) => Fragment::raw($source->dialect->quote($n)), $names));

        return $source->wrap($list, $names);
    }

    public function filter(Closure|Expr $predicate): Table
    {
        if ($predicate instanceof Closure) {
            return $this->collect()->filter($predicate);
        }
        try {
            $condition = $this->compiler()->compile($predicate);
        } catch (Untranslatable) {
            return $this->collect()->filter($predicate);
        }

        if (! Compiler::hasWindow($predicate)) {
            return $this->wrap(Fragment::raw('*'), $this->columns, where: $condition, carryRow: false);
        }
        // Window conditions can't sit in WHERE: compute a flag column first.
        $flag = '__phibble_keep';
        $inner = $this->wrap(Fragment::format('*, {} AS '.$this->dialect->quote($flag), [$condition]), [...$this->columns, $flag], carryRow: false);
        $list = Fragment::join(', ', array_map(fn (string $n) => Fragment::raw($this->dialect->quote($n)), $this->columns));

        return $inner->wrap($list, $this->columns, where: Fragment::raw($this->dialect->quote($flag).' = TRUE'));
    }

    public function filterGroups(Closure $predicate): Table
    {
        return $this->collect()->filterGroups($predicate);
    }

    public function mutate(array $columns): Table
    {
        $table = $this;
        foreach ($columns as $name => $definition) {
            $name = (string) $name;
            $expr = match (true) {
                $definition instanceof Expr => $definition,
                $definition === null || is_scalar($definition) => new Lit($definition),
                default => null,
            };
            if ($expr === null) {
                return $table->collect()->mutate(array_slice($columns, array_search($name, array_keys($columns), true), null, true));
            }
            try {
                $sql = $table->compiler()->compile($expr);
            } catch (Untranslatable) {
                return $table->collect()->mutate(array_slice($columns, array_search($name, array_keys($columns), true), null, true));
            }
            $table = $table->withExpression($name, $sql, Compiler::isLogical($expr) ? Type::Bool : null);
        }

        return $table;
    }

    public function mutateGroups(array $columns): Table
    {
        if (array_all($columns, fn ($c) => ! $c instanceof Closure)) {
            return $this->mutate($columns);
        }

        return $this->collect()->mutateGroups($columns);
    }

    public function rename(array $map): Table
    {
        $newByOld = [];
        foreach ($map as $new => $old) {
            $this->check([$old]);
            $newByOld[$old] = (string) $new;
        }
        $names = array_map(fn (string $c) => $newByOld[$c] ?? $c, $this->columns);
        if (count(array_unique($names)) !== count($names)) {
            throw new InvalidArgumentException('Renaming would create a duplicate column.');
        }
        $list = Fragment::join(', ', array_map(
            fn (string $c) => Fragment::raw(isset($newByOld[$c])
                ? $this->dialect->quote($c).' AS '.$this->dialect->quote($newByOld[$c])
                : $this->dialect->quote($c)),
            $this->columns,
        ));
        $order = array_map(fn (array $o) => [self::renameIn($o[0], $newByOld), $o[1]], $this->order);
        $hints = [];
        foreach ($this->hints as $c => $type) {
            $hints[$newByOld[$c] ?? $c] = $type;
        }

        return $this->wrap($list, $names, order: $order, hints: $hints)
            ->withGroups(array_map(fn (string $g) => $newByOld[$g] ?? $g, $this->groups));
    }

    public function arrange(array $keys): Table
    {
        $order = [];
        foreach ($keys as $key) {
            $order[] = match (true) {
                $key instanceof Desc => [$key->expr, true],
                $key instanceof Expr => [$key, false],
                is_array($key) => [new Col($key[0]), strtolower($key[1] ?? 'asc') === 'desc'],
                default => [new Col($key), false],
            };
        }
        try {
            (new Compiler($this->dialect))->orderBy($order);
        } catch (Untranslatable) {
            return $this->collect()->arrange($keys);
        }
        foreach ($order as [$expr]) {
            $this->check($expr->columns());
        }

        return new self($this->pdo, $this->dialect, $this->from, $this->columns, $this->groups, $order, $this->hints, $this->depth, $this->rowKeys);
    }

    public function groupBy(array $names, bool $drop = true): Table
    {
        $this->check($names);
        if (! $drop) {
            // Empty factor-level groups need the levels, which SQL doesn't have.
            return $this->collect()->ungroup()->groupBy($names, false);
        }

        return $this->withGroups(array_values(array_unique($names)));
    }

    public function dropsEmptyGroups(): bool
    {
        return true;
    }

    public function ungroup(): Table
    {
        return $this->withGroups([]);
    }

    public function rowwise(array $idColumns = []): Table
    {
        return $this->collect()->rowwise($idColumns);
    }

    public function eachGroup(): iterable
    {
        return $this->collect()->eachGroup();
    }

    public function groupRows(): array
    {
        return $this->collect()->groupRows();
    }

    public function summarise(array $aggregates, ?string $groups = null): Table
    {
        if (array_any($aggregates, fn ($a) => ! $a instanceof Expr)) {
            return $this->collect()->summarise($aggregates, $groups);
        }

        // first(), last() and nth() become window columns first; every row of
        // a group then holds the same value, which an aggregate picks up.
        $original = $aggregates;
        $source = $this;
        $hidden = 0;
        foreach ($aggregates as $name => $expr) {
            if ($expr instanceof Call && in_array($expr->fn, ['first', 'last', 'nth'], true)) {
                $column = '__phibble_w'.$hidden++;
                $windowed = $source->mutate([$column => $expr]);
                if (! $windowed instanceof self) {
                    return $this->collect()->summarise($original, $groups);
                }
                $source = $windowed;
                $aggregates[$name] = new Call('max', [new Col($column)], ['na_rm' => new Lit(true)]);
            }
        }
        if ($source !== $this) {
            return $source->summariseCompiled($aggregates, $groups, $this->hints);
        }

        return $this->summariseCompiled($aggregates, $groups, $this->hints);
    }

    /**
     * @param array<string, Expr> $aggregates
     * @param array<string, Type> $baseHints
     */
    private function summariseCompiled(array $aggregates, ?string $groups, array $baseHints): Table
    {
        $compiler = new Compiler($this->dialect, mode: 'summary');
        $parts = array_map(fn (string $g) => Fragment::raw($this->dialect->quote($g)), $this->groups);
        $hints = array_intersect_key($baseHints, array_flip($this->groups));
        try {
            foreach ($aggregates as $name => $expr) {
                $parts[] = Fragment::format('{} AS '.$this->dialect->quote((string) $name), [$compiler->compile($expr)]);
                if (Compiler::isLogical($expr)) {
                    $hints[(string) $name] = Type::Bool;
                }
            }
        } catch (Untranslatable) {
            return $this->collect()->summarise($aggregates, $groups);
        }

        $groupBy = $this->groups === []
            ? null
            : Fragment::join(', ', array_map(fn (string $g) => Fragment::raw($this->dialect->quote($g)), $this->groups));
        $names = [...$this->groups, ...array_map(strval(...), array_keys($aggregates))];
        $order = array_map(fn (string $g) => [new Col($g), false], $this->groups);

        $outGroups = match ($groups ?? 'drop_last') {
            'drop_last' => array_slice($this->groups, 0, -1),
            'drop' => [],
            'keep' => $this->groups,
            default => throw new InvalidArgumentException("Unknown .groups value '{$groups}'."),
        };

        $grouped = $this->wrap(Fragment::join(', ', $parts), $names, groupBy: $groupBy, order: [], hints: $hints, carryRow: false);

        // Rows come out in group order (NA last), as in dplyr.
        return $grouped->renumber($order, hasRow: false)->withGroups($outGroups);
    }

    public function summarize(array $aggregates, ?string $groups = null): Table
    {
        return $this->summarise($aggregates, $groups);
    }

    public function distinct(array $names = []): Table
    {
        $this->check($names);
        $names = $names === [] ? $this->columns : [...array_values(array_diff($this->groups, $names)), ...$names];
        // Keep each combination's first row in the current order, as dplyr does.
        $source = $this->numbered();
        $list = Fragment::join(', ', array_map(fn (string $n) => Fragment::raw($source->dialect->quote($n)), $names));
        $row = $source->dialect->quote(self::ROW);

        return $source->wrap(
            Fragment::format("{}, MIN({$row}) AS {$row}", [$list]),
            $names,
            groupBy: $list,
            order: [],
            carryRow: false,
        );
    }

    public function slice(int $offset, ?int $length = null): Table
    {
        $limit = $length === null ? $this->dialect->noLimit() : 'LIMIT '.max(0, $length);
        $sql = Fragment::format('{} '.$limit.' OFFSET '.max(0, $offset), [$this->render(withRow: true)]);
        $sql = Fragment::format('SELECT * FROM ({}) AS '.$this->alias(), [$sql]);

        return new self($this->pdo, $this->dialect, $sql, $this->columns, $this->groups, $this->order, $this->hints, $this->depth + 1, $this->rowKeys);
    }

    public function head(int $n = 6): Table
    {
        return $this->slice(0, max(0, $n));
    }

    public function pivotLongerSpec(array $spec, bool $dropNa = false): Table
    {
        return $this->collect()->pivotLongerSpec($spec, $dropNa);
    }

    public function pivotWiderSpec(array $spec, ?array $idColumns = null, array $valuesFill = []): Table
    {
        return $this->collect()->pivotWiderSpec($spec, $idColumns, $valuesFill);
    }

    public function take(array $indices): Table
    {
        return $this->collect()->take($indices);
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
    ): Table {
        $closest = (Join::conditions($by))[2] !== null;
        $native = $other instanceof self
            && $other->pdo === $this->pdo
            && $multiple === 'all' && $relationship === null && $unmatched === 'drop'
            && ! ($closest && in_array($type, ['right', 'full'], true))
            && ($type !== 'full' || ($naMatches ? $this->dialect->supportsNullSafeFullJoin() : $this->dialect->supportsFullJoin()));
        if (! $native) {
            return $this->collect()->join($other->collect(), $by, $type, $suffix, $keep, $naMatches, $multiple, $relationship, $unmatched);
        }

        return SqlJoin::build($this, $other, $by, $type, $suffix, $keep, $naMatches);
    }

    // ---------------------------------------------------------------
    // Internals (shared with SqlJoin)
    // ---------------------------------------------------------------

    /** @internal */
    public function fragment(): Fragment
    {
        return $this->from;
    }

    /** @internal */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @internal @return array<string, Type> */
    public function hints(): array
    {
        return $this->hints;
    }

    /**
     * @internal
     *
     * @param list<string> $columns
     * @param list<string> $groups
     * @param array<string, Type> $hints
     */
    public function derive(Fragment $from, array $columns, array $groups, array $hints, array $rowKeys = [self::ROW]): self
    {
        return new self($this->pdo, $this->dialect, $from, $columns, $groups, [], $hints, $this->depth + 1, $rowKeys);
    }

    /** @internal */
    public function alias(): string
    {
        return 'q'.($this->depth + 1);
    }

    private function compiler(): Compiler
    {
        return new Compiler($this->dialect, $this->groups, $this->rowOrder());
    }

    /**
     * The row order: arrange() keys, then the source position.
     *
     * @return list<array{0: Expr, 1: bool}>
     */
    private function rowOrder(): array
    {
        return [...$this->order, ...array_map(fn (string $k) => [new Col($k), false], $this->rowKeys)];
    }

    /** The full query in row order, without (or with) the hidden row column. */
    private function render(bool $withRow = false): Fragment
    {
        $list = $withRow ? '*' : implode(', ', array_map($this->dialect->quote(...), $this->columns));
        if ($list === '') {
            $list = '*';
        }

        return Fragment::format("SELECT {$list} FROM ({}) AS ".$this->alias().' ORDER BY {}', [
            $this->from,
            (new Compiler($this->dialect))->orderBy($this->rowOrder()),
        ]);
    }

    /**
     * The same rows with the current order fixed into the row column, so
     * later steps can drop the columns the order depends on.
     *
     * @internal
     */
    public function ordered(): self
    {
        return $this->order === [] ? $this : $this->renumber($this->order);
    }

    /** @internal @return list<string> The hidden columns that order rows, most significant first. */
    public function rowKeys(): array
    {
        return $this->rowKeys;
    }

    /** The same rows with a single row-number column (for MIN(row) and similar). */
    private function numbered(): self
    {
        return $this->order === [] && count($this->rowKeys) === 1 ? $this : $this->renumber($this->order);
    }

    /**
     * Renumber the row column by $order, then the old row number.
     *
     * @param list<array{0: Expr, 1: bool}> $order
     */
    private function renumber(array $order, bool $hasRow = true): self
    {
        $compiler = new Compiler($this->dialect);
        $terms = $hasRow ? [...$order, ...array_map(fn (string $k) => [new Col($k), false], $this->rowKeys)] : $order;
        $over = $terms === [] ? Fragment::raw('') : Fragment::format('ORDER BY {}', [$compiler->orderBy($terms)]);
        $list = Fragment::join(', ', array_map(fn (string $n) => Fragment::raw($this->dialect->quote($n)), $this->columns));
        $row = $this->dialect->quote(self::ROW);
        $items = $this->columns === []
            ? Fragment::format("ROW_NUMBER() OVER ({}) AS {$row}", [$over])
            : Fragment::format("{}, ROW_NUMBER() OVER ({}) AS {$row}", [$list, $over]);

        return $this->wrap($items, $this->columns, order: [], carryRow: false, rowKeys: [self::ROW]);
    }

    /**
     * Wrap the current query: SELECT $list FROM (current) [WHERE] [GROUP BY].
     *
     * @param list<string> $columns
     * @param list<array{0: Expr, 1: bool}>|null $order
     * @param array<string, Type>|null $hints
     */
    private function wrap(
        Fragment $list,
        array $columns,
        ?Fragment $where = null,
        ?Fragment $groupBy = null,
        ?array $order = null,
        ?array $hints = null,
        bool $carryRow = true,
        ?array $rowKeys = null,
    ): self {
        if ($carryRow) {
            $list = Fragment::format('{}, '.implode(', ', array_map($this->dialect->quote(...), $this->rowKeys)), [$list]);
        }
        $template = 'SELECT {} FROM ({}) AS '.$this->alias();
        $parts = [$list, $this->from];
        if ($where !== null) {
            $template .= ' WHERE {}';
            $parts[] = $where;
        }
        if ($groupBy !== null) {
            $template .= ' GROUP BY {}';
            $parts[] = $groupBy;
        }
        $hints ??= array_intersect_key($this->hints, array_flip($columns));

        return new self(
            $this->pdo,
            $this->dialect,
            Fragment::format($template, $parts),
            $columns,
            array_values(array_intersect($this->groups, $columns)),
            $order ?? $this->order,
            $hints,
            $this->depth + 1,
            $rowKeys ?? $this->rowKeys,
        );
    }

    private function withExpression(string $name, Fragment $sql, ?Type $hint): self
    {
        $exists = in_array($name, $this->columns, true);
        $items = [];
        foreach ($this->columns as $column) {
            $items[] = $column === $name
                ? Fragment::format('{} AS '.$this->dialect->quote($name), [$sql])
                : Fragment::raw($this->dialect->quote($column));
        }
        if (! $exists) {
            $items[] = Fragment::format('{} AS '.$this->dialect->quote($name), [$sql]);
        }
        $hints = $this->hints;
        unset($hints[$name]);
        if ($hint !== null) {
            $hints[$name] = $hint;
        }

        return $this->wrap(Fragment::join(', ', $items), $exists ? $this->columns : [...$this->columns, $name], hints: $hints);
    }

    /** @param list<string> $groups */
    private function withGroups(array $groups): self
    {
        return new self($this->pdo, $this->dialect, $this->from, $this->columns, $groups, $this->order, $this->hints, $this->depth, $this->rowKeys);
    }

    /**
     * Keep the row order only while its columns still exist.
     *
     * @param list<string> $columns
     * @return list<array{0: Expr, 1: bool}>
     */
    private function keepOrder(array $columns): array
    {
        foreach ($this->order as [$expr]) {
            if (array_diff($expr->columns(), $columns) !== []) {
                return [];
            }
        }

        return $this->order;
    }

    /** @param list<string> $names */
    private function check(array $names): void
    {
        foreach ($names as $name) {
            if (! in_array($name, $this->columns, true)) {
                throw ColumnNotFound::named($name, $this->columns);
            }
        }
    }

    private function run(Fragment $sql): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql->sql);
        self::bind($statement, $sql->bindings);
        $statement->execute();

        return $statement;
    }

    /** @param list<mixed> $bindings */
    private static function bind(\PDOStatement $statement, array $bindings): void
    {
        foreach ($bindings as $i => $value) {
            $statement->bindValue($i + 1, $value, match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                default => PDO::PARAM_STR,
            });
        }
    }

    /**
     * Turn named (:name) bindings into positional ones, so compiled
     * fragments can add their own ? placeholders.
     *
     * @param array<int|string, mixed> $bindings
     */
    private static function positional(string $sql, array $bindings): Fragment
    {
        if ($bindings === [] || array_is_list($bindings)) {
            return new Fragment($sql, array_values($bindings));
        }

        $named = [];
        foreach ($bindings as $key => $value) {
            $named[ltrim((string) $key, ':')] = $value;
        }
        $out = '';
        $values = [];
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($char === "'" || $char === '"' || $char === '`') {
                $end = $i + 1;
                while ($end < $length && $sql[$end] !== $char) {
                    $end++;
                }
                $out .= substr($sql, $i, $end - $i + 1);
                $i = $end;

                continue;
            }
            if ($char === ':' && $i + 1 < $length && $sql[$i + 1] !== ':' && ($i === 0 || $sql[$i - 1] !== ':')
                && preg_match('/\G:([A-Za-z_][A-Za-z0-9_]*)/', $sql, $m, 0, $i)) {
                if (! array_key_exists($m[1], $named)) {
                    throw new InvalidArgumentException("No binding given for :{$m[1]}.");
                }
                $out .= '?';
                $values[] = $named[$m[1]];
                $i += strlen($m[0]) - 1;

                continue;
            }
            $out .= $char;
        }

        return new Fragment($out, $values);
    }

    /** @param array<string, string> $map old => new */
    private static function renameIn(Expr $expr, array $map): Expr
    {
        return match (true) {
            $expr instanceof Col => new Col($map[$expr->name] ?? $expr->name),
            $expr instanceof Desc => new Desc(self::renameIn($expr->expr, $map)),
            $expr instanceof Call => new Call(
                $expr->fn,
                array_map(fn (Expr $a) => self::renameIn($a, $map), $expr->args),
                array_map(fn (Expr $a) => self::renameIn($a, $map), $expr->named),
            ),
            default => $expr,
        };
    }
}
