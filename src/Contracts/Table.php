<?php

declare(strict_types=1);

namespace Phibble\Contracts;

use Closure;
use Phibble\Expr\Expr;
use Phibble\Frame;
use Phibble\Type;

/**
 * The table contract every backend implements: the in-memory Phibble\Frame,
 * a later Polars-backed table and a later lazy SQL table.
 *
 * Rules shared by every method:
 * - Tables are immutable. Every operation returns a new table. Verbs are
 *   declared to return Table: a backend may hand back a different
 *   implementation, e.g. a SQL table falls back to an in-memory Frame
 *   for closures it cannot translate. Frame returns static.
 * - NA is PHP null.
 * - Grouping is metadata. groupBy() records key columns; verbs that care
 *   about groups (filter, mutate, summarise) read it, the rest keep it.
 * - Groups are visited in sorted key order, as in dplyr: ascending,
 *   factors by level order, NA last, strings in byte order. Row-level
 *   verbs (filter, mutate, arrange) keep the input row order.
 * - A rowwise table (rowwise()) treats every row as its own group, in
 *   row order; groups() returns its id columns.
 * - Row closures receive (Phibble\Row $row, Table $group). $group is the
 *   row's group, or the whole table when ungrouped. Closures that only
 *   need the row can ignore the second argument.
 * - Unknown column names throw Phibble\Exception\ColumnNotFound.
 * - count() is nrow(); iterating yields a Phibble\Row per row.
 *
 * @extends \IteratorAggregate<int, \Phibble\Row>
 */
interface Table extends \Countable, \IteratorAggregate
{
    /** @return list<string> Column names in order. */
    public function columns(): array;

    /** @return array<string, Type> Column name => type. */
    public function types(): array;

    public function nrow(): int;

    public function ncol(): int;

    /** @return list<mixed> The column's values; factors come back as their labels. */
    public function column(string $name): array;

    /** @param list<string> $names Keep these columns, in this order. */
    public function select(array $names): Table;

    /**
     * Keep rows where the predicate is true; NA counts as false. A
     * closure runs per row; an Expr is evaluated per group, vectorised.
     *
     * @param Closure(\Phibble\Row, Table): ?bool|Expr $predicate
     */
    public function filter(Closure|Expr $predicate): Table;

    /**
     * Keep rows by a vectorised predicate run once per group. The closure
     * returns one value per row of the group; truthy keeps the row.
     *
     * @param Closure(Table): list<?bool> $predicate
     */
    public function filterGroups(Closure $predicate): Table;

    /**
     * Add or replace columns. Each value is a closure run per row, an Expr
     * evaluated per group (aggregates recycle within the group), a list
     * with one value per row, or a scalar to repeat. Later entries can use
     * earlier ones.
     *
     * @param array<string, Closure(\Phibble\Row, Table): mixed|list<mixed>> $columns
     */
    public function mutate(array $columns): Table;

    /**
     * Add or replace columns with vectorised closures, run once per group.
     * Each returns a list with one value per row of the group, or a scalar
     * to repeat. Non-closure values behave as in mutate(). This is the
     * primitive for window functions (lag, cumsum, rank) and across().
     *
     * @param array<string, Closure(Table): (list<mixed>|scalar|null)|list<mixed>|scalar|null> $columns
     */
    public function mutateGroups(array $columns): Table;

    /** @param array<string, string> $map New name => old name, as in dplyr. */
    public function rename(array $map): Table;

    /**
     * Sort rows. Stable; nulls sort last in both directions.
     *
     * @param list<string|array{0: string, 1?: 'asc'|'desc'}|Expr> $keys
     *     A bare name means ascending. An Expr key sorts by its value;
     *     wrap it in desc() for descending.
     */
    public function arrange(array $keys): Table;

    /** @param list<string> $names Replaces any existing grouping. */
    public function groupBy(array $names): Table;

    public function ungroup(): Table;

    /** @return list<string> Current group key columns; empty when ungrouped. */
    public function groups(): array;

    /**
     * Treat each row as its own group. groupBy() and ungroup() clear it.
     *
     * @param list<string> $idColumns Kept by summarise(), like group keys.
     */
    public function rowwise(array $idColumns = []): Table;

    public function isRowwise(): bool;

    /**
     * Iterate groups in sorted key order (row order for rowwise tables).
     * Each group's table is ungrouped. Ungrouped tables yield one group
     * with an empty key, even when they have no rows.
     *
     * @return iterable<int, array{key: array<string, mixed>, table: Table}>
     */
    public function eachGroup(): iterable;

    /**
     * One row per group: the group keys, then one column per entry. Each
     * entry is a closure given the group's table, or an Expr that must
     * evaluate to one value (usually an aggregate).
     * The result drops the last grouping level, like dplyr. A rowwise
     * table keeps its id columns as groups.
     *
     * @param array<string, Closure(Table): mixed|Expr> $aggregates
     * @param string|null $groups dplyr's .groups: 'drop_last' (the default),
     *     'drop', 'keep' or 'rowwise'. Rowwise tables default to 'keep'.
     */
    public function summarise(array $aggregates, ?string $groups = null): Table;

    /**
     * Unique rows over the named columns, keeping those columns only.
     * An empty list means all columns.
     *
     * @param list<string> $names
     */
    public function distinct(array $names = []): Table;

    /**
     * Rows by position, in the given order; a null index gives an all-NA
     * row. Keeps types, factor levels and grouping.
     *
     * @param list<int|null> $indices
     */
    public function take(array $indices): Table;

    /**
     * Join another table, with dplyr 1.1 semantics.
     *
     * - $by: list of [leftColumn, rightColumn] pairs; a bare 'k' means
     *   ['k', 'k']. Empty for a cross join.
     * - $type: inner, left, right, full, semi, anti or cross.
     * - Columns: x's columns, then y's non-key columns (all of y's with
     *   $keep). Clashing names get $suffix. Without $keep, key columns come
     *   from x, filled from y for rows only y has (right and full joins).
     * - Rows: x order, with each x row's matches in y order; right and full
     *   joins then add y's unmatched rows in y order. Semi and anti joins
     *   keep x's rows and columns and never duplicate rows.
     * - Matching: NA matches NA unless !$naMatches; 1 matches 1.0; factors
     *   match strings by label.
     * - $multiple: all, any, first or last match per x row.
     * - $relationship: one-to-one, one-to-many, many-to-one or
     *   many-to-many; a violation throws. Unspecified many-to-many warns.
     * - $unmatched: drop, or error when a join would drop rows.
     * - The result keeps x's grouping.
     *
     * @param list<string|array{0: string, 1: string}> $by
     * @param array{0: string, 1: string} $suffix
     */
    public function join(
        Table $other,
        array $by,
        string $type = 'inner',
        array $suffix = ['.x', '.y'],
        bool $keep = false,
        bool $naMatches = true,
        string $multiple = 'all',
        ?string $relationship = null,
        string $unmatched = 'drop',
    ): Table;

    /** Rows [$offset, $offset + $length). Ignores groups. */
    public function slice(int $offset, ?int $length = null): Table;

    /** First $n rows. Ignores groups. */
    public function head(int $n = 6): Table;

    /** @return list<array<string, mixed>> */
    public function toRows(): array;

    /** @return array<string, list<mixed>> */
    public function toColumns(): array;

    /** Materialise into an in-memory Frame. A Frame returns itself. */
    public function collect(): Frame;
}
