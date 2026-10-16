<?php

declare(strict_types=1);

namespace Phibble\Contracts;

use Closure;
use Phibble\Frame;
use Phibble\Type;

/**
 * The table contract every backend implements: the in-memory Phibble\Frame,
 * a later Polars-backed table and a later lazy SQL table.
 *
 * Rules shared by every method:
 * - Tables are immutable. Every operation returns a new table.
 * - NA is PHP null.
 * - Grouping is metadata. groupBy() records key columns; verbs that care
 *   about groups (filter, mutate, summarise) read it, the rest keep it.
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
    public function select(array $names): static;

    /**
     * Keep rows where the closure returns true. Null counts as false.
     *
     * @param Closure(\Phibble\Row, Table): ?bool $predicate
     */
    public function filter(Closure $predicate): static;

    /**
     * Add or replace columns. Each value is either a closure run per row,
     * or a list with one value per row. Later entries can use earlier ones.
     *
     * @param array<string, Closure(\Phibble\Row, Table): mixed|list<mixed>> $columns
     */
    public function mutate(array $columns): static;

    /** @param array<string, string> $map New name => old name, as in dplyr. */
    public function rename(array $map): static;

    /**
     * Sort rows. Stable; nulls sort last in both directions.
     *
     * @param list<string|array{0: string, 1?: 'asc'|'desc'}> $keys
     *     A bare name means ascending.
     */
    public function arrange(array $keys): static;

    /** @param list<string> $names Replaces any existing grouping. */
    public function groupBy(array $names): static;

    public function ungroup(): static;

    /** @return list<string> Current group key columns; empty when ungrouped. */
    public function groups(): array;

    /**
     * Iterate groups in order of first appearance. Each group's table is
     * ungrouped. Ungrouped tables yield one group with an empty key.
     *
     * @return iterable<int, array{key: array<string, mixed>, table: Table}>
     */
    public function eachGroup(): iterable;

    /**
     * One row per group: the group keys, then one column per entry.
     * The result drops the last grouping level, like dplyr.
     *
     * @param array<string, Closure(Table): mixed> $aggregates
     */
    public function summarise(array $aggregates): static;

    /**
     * Unique rows over the named columns, keeping those columns only.
     * An empty list means all columns.
     *
     * @param list<string> $names
     */
    public function distinct(array $names = []): static;

    /** Rows [$offset, $offset + $length). Ignores groups. */
    public function slice(int $offset, ?int $length = null): static;

    /** First $n rows. Ignores groups. */
    public function head(int $n = 6): static;

    /** @return list<array<string, mixed>> */
    public function toRows(): array;

    /** @return array<string, list<mixed>> */
    public function toColumns(): array;

    /** Materialise into an in-memory Frame. A Frame returns itself. */
    public function collect(): Frame;
}
