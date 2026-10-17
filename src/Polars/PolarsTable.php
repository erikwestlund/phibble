<?php

declare(strict_types=1);

namespace Phibble\Polars;

use Closure;
use Generator;
use InvalidArgumentException;
use Phibble\Column\FactorColumn;
use Phibble\Column\TemporalColumn;
use Phibble\Contracts\Table;
use Phibble\Exception\ColumnNotFound;
use Phibble\Expr\Col;
use Phibble\Expr\Desc;
use Phibble\Expr\Expr as PhibbleExpr;
use Phibble\DataFrame;
use Phibble\Row;
use Phibble\Type;
use Phpolars\DataFrame as PolarsDataFrame;
use Phpolars\Expr;
use Phpolars\LazyFrame;
use Phpolars\Series;

use function Phpolars\{all, all_horizontal, any_horizontal, col, concat, lit, when};

/**
 * A Phibble\Contracts\Table backed by a Polars DataFrame.
 *
 * Verbs that take column names or Phibble\Expr run in Polars. Verbs given a
 * PHP closure cannot run on Polars threads, so they materialise a
 * Phibble\DataFrame, run there, and convert the result back.
 *
 * Factor columns are held as strings in Polars; their levels travel with
 * the table so that collect() and group ordering keep them.
 */
final class PolarsTable implements Table
{
    /**
     * @param list<string> $groups
     * @param array<string, array{levels: list<string>, ordered: bool}> $factors
     */
    private function __construct(
        private readonly PolarsDataFrame $df,
        private readonly array $groups = [],
        private readonly bool $rowwise = false,
        private readonly array $factors = [],
        private readonly bool $dropGroups = true,
    ) {}

    /** Wrap a Polars frame; a LazyFrame is collected. */
    public static function fromPolars(PolarsDataFrame|LazyFrame $df): self
    {
        return new self($df instanceof LazyFrame ? $df->collect() : $df);
    }

    /** Copy any phibble table into Polars, keeping types, groups and factor levels. */
    public static function fromTable(Table $table): self
    {
        if ($table instanceof self) {
            return $table;
        }
        $frame = $table->collect();
        $schema = [];
        $factors = [];
        $columns = [];
        $temporal = [];
        foreach ($frame->columns() as $name) {
            $storage = $frame->storage($name);
            if ($storage instanceof TemporalColumn) {
                // Dates and datetimes cross as integer ticks, never as objects.
                $columns[$name] = $storage->ticks();
                $temporal[$name] = $storage->type() === Type::Date
                    ? 'Date'
                    : ($storage->timezone() === 'UTC' ? 'Datetime(us)' : "Datetime(us, {$storage->timezone()})");
            } else {
                $columns[$name] = $storage->values();
            }
        }
        foreach ($frame->types() as $name => $type) {
            if (isset($temporal[$name])) {
                $schema[$name] = 'Int64';

                continue;
            }
            $dtype = match ($type) {
                Type::Int => 'Int64',
                Type::Float => 'Float64',
                Type::Bool => 'Boolean',
                Type::String, Type::Factor => 'String',
                Type::Date => 'Date',
                Type::DateTime => 'Datetime(us)',
                Type::List => null,
            };
            if ($dtype !== null) {
                $schema[$name] = $dtype;
            }
            if ($type === Type::Factor) {
                $storage = $frame->storage($name);
                $factors[$name] = ['levels' => $storage->levels(), 'ordered' => $storage instanceof FactorColumn && $storage->isOrdered()];
            }
        }
        $df = PolarsDataFrame::fromDict($columns, schema: $schema);
        if ($temporal !== []) {
            $casts = [];
            foreach ($temporal as $name => $dtype) {
                $casts[$name] = col($name)->cast($dtype);
            }
            $df = $df->withColumns($casts);
        }
        self::checkListColumns($frame, $df);

        return new self($df, $frame->groups(), $frame->isRowwise(), $factors, $frame->dropsEmptyGroups());
    }

    /**
     * Polars stores named arrays as structs, and a struct column has every key
     * in every row. Named arrays with different keys would come back with
     * extra NA entries, so such list-columns are refused instead of changed.
     */
    private static function checkListColumns(DataFrame $frame, PolarsDataFrame $df): void
    {
        foreach ($frame->types() as $name => $type) {
            if ($type === Type::List && $df->select([$name])->toDict(asSeries: false)[$name] != $frame->column($name)) {
                throw new InvalidArgumentException(
                    "List-column `{$name}` can't be stored in Polars without changing its values. "
                    .'Polars stores named arrays as structs, so named arrays with different keys gain each other\'s keys. '
                    .'Keep this table as a DataFrame.'
                );
            }
        }
    }

    /** The underlying Polars frame. */
    public function polars(): PolarsDataFrame
    {
        return $this->df;
    }

    private function with(PolarsDataFrame $df, ?array $groups = null, ?bool $rowwise = null, ?array $factors = null, ?bool $drop = null): static
    {
        $factors ??= $this->factors;
        $columns = array_flip($df->columns());
        $groups ??= $this->groups;

        return new self(
            $df,
            $groups,
            $rowwise ?? $this->rowwise,
            array_intersect_key($factors, $columns),
            $groups === [] ? true : ($drop ?? $this->dropGroups),
        );
    }

    /**
     * With groupBy(drop: false), a factor key has a group for every level,
     * even empty ones. Polars only sees observed groups, so those verbs run
     * on a DataFrame.
     */
    private function keepsEmptyGroups(): bool
    {
        return ! $this->dropGroups && array_any($this->groups, fn (string $g) => isset($this->factors[$g]));
    }

    /** How many verbs fell back to an in-memory DataFrame in this process. */
    public static int $fallbacks = 0;

    /** Run a verb on an in-memory DataFrame and bring the result back. */
    private function viaDataFrame(Closure $verb): static
    {
        self::$fallbacks++;

        return self::fromTable($verb($this->collect()));
    }

    private function check(string ...$names): void
    {
        $columns = $this->df->columns();
        foreach ($names as $name) {
            if (! in_array($name, $columns, true)) {
                throw ColumnNotFound::named($name, $columns);
            }
        }
    }

    private function translator(bool $grouped = true): Translator
    {
        return new Translator($this->df->schema(), $grouped ? $this->groups : [], $this->factors);
    }

    /** Sort keys for group order: factor levels first, NA last, strings by byte. */
    private function groupOrder(array $keys): array
    {
        return array_map(function (string $k) {
            if (! isset($this->factors[$k])) {
                return col($k);
            }

            return col($k)->replaceStrict(array_flip($this->factors[$k]['levels']), default: lit(null), returnDtype: 'Int64');
        }, $keys);
    }

    /** Raise R's error when a quantile() argument holds NA and na_rm is FALSE. */
    private function guard(Translator $translator): void
    {
        foreach ($translator->naChecks as $arg) {
            if ($this->df->select($arg->isNull()->any()->alias('na'))->item() === true) {
                throw new InvalidArgumentException("missing values and NaN's not allowed if 'na_rm' is FALSE");
            }
        }
    }

    // Shape and values

    public function columns(): array
    {
        return $this->df->columns();
    }

    public function types(): array
    {
        $types = [];
        foreach ($this->df->schema() as $name => $dtype) {
            $types[$name] = isset($this->factors[$name]) ? Type::Factor : match (true) {
                str_starts_with($dtype, 'Int'), str_starts_with($dtype, 'UInt') => Type::Int,
                str_starts_with($dtype, 'Float') => Type::Float,
                $dtype === 'Boolean', $dtype === 'Null' => Type::Bool,
                $dtype === 'Date' => Type::Date,
                str_starts_with($dtype, 'Datetime') => Type::DateTime,
                str_starts_with($dtype, 'List'), str_starts_with($dtype, 'Array'), str_starts_with($dtype, 'Struct') => Type::List,
                $dtype === 'Categorical' => Type::Factor,
                default => Type::String,
            };
        }

        return $types;
    }

    public function nrow(): int
    {
        return $this->df->height();
    }

    public function ncol(): int
    {
        return $this->df->width();
    }

    public function count(): int
    {
        return $this->df->height();
    }

    public function column(string $name): array
    {
        $this->check($name);

        return (new self($this->df->select($name)))->toColumns()[$name];
    }

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
        for ($i = 0, $n = $this->nrow(); $i < $n; $i++) {
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
        return array_map(
            fn ($column) => $column instanceof TemporalColumn ? $column->values() : $column,
            $this->phibbleColumns(),
        );
    }

    /**
     * Columns as phibble wants them: plain lists, with dates and datetimes
     * as TemporalColumn built from integer ticks, so no PHP date objects are
     * made unless values are read.
     *
     * @return array<string, list<mixed>|TemporalColumn>
     */
    private function phibbleColumns(): array
    {
        $casts = [];
        $temporal = [];
        foreach ($this->df->schema() as $name => $dtype) {
            if ($dtype === 'Date') {
                $casts[$name] = col($name)->cast('Int64');
                $temporal[$name] = [Type::Date, 'UTC'];
            } elseif (str_starts_with($dtype, 'Datetime')) {
                $casts[$name] = col($name)->dt()->castTimeUnit('us')->cast('Int64');
                $zone = preg_match('/^Datetime\(\w+, (.+)\)$/', $dtype, $m) ? $m[1] : 'UTC';
                $temporal[$name] = [Type::DateTime, $zone];
            }
        }
        $df = $casts === [] ? $this->df : $this->df->withColumns($casts);
        $columns = $df->toDict(asSeries: false);
        foreach ($temporal as $name => [$type, $zone]) {
            $columns[$name] = new TemporalColumn($type, $columns[$name], $zone);
        }

        return $columns;
    }

    public function collect(): DataFrame
    {
        $columns = $this->phibbleColumns();
        $types = [];
        foreach ($this->types() as $name => $type) {
            if ($columns[$name] instanceof TemporalColumn) {
                continue;
            }
            if ($type === Type::Factor && isset($this->factors[$name])) {
                $levels = $this->factors[$name]['levels'];
                $extra = array_diff(array_unique(array_filter($columns[$name], fn ($v) => $v !== null)), $levels);
                $columns[$name] = FactorColumn::fromValues($columns[$name], [...$levels, ...array_values($extra)], $this->factors[$name]['ordered']);
            } elseif ($type !== Type::List) {
                $types[$name] = $type;
            }
        }
        $frame = DataFrame::fromColumns($columns, $types);
        if ($this->rowwise) {
            return $frame->rowwise($this->groups);
        }

        return $this->groups === [] ? $frame : $frame->groupBy($this->groups, $this->dropGroups);
    }

    // Verbs

    public function select(array $names): static
    {
        $names = array_values(array_unique($names));
        $this->check(...$names);
        // Grouping columns always stay, as in dplyr.
        $names = [...array_values(array_diff($this->groups, $names)), ...$names];

        return $this->with($this->df->select($names));
    }

    public function filter(Closure|PhibbleExpr $predicate): static
    {
        try {
            return $this->filterNative($predicate);
        } catch (Unsupported) {
            return $this->viaDataFrame(fn (DataFrame $f) => $f->filter($predicate));
        }
    }

    private function filterNative(Closure|PhibbleExpr $predicate): static
    {
        if ($predicate instanceof Closure || $this->rowwise || $this->keepsEmptyGroups()) {
            return $this->viaDataFrame(fn (DataFrame $f) => $f->filter($predicate));
        }

        $translator = $this->translator();
        $predicate = $translator->row($predicate);
        $this->guard($translator);

        return $this->with($this->df->filter($predicate));
    }

    public function filterGroups(Closure $predicate): static
    {
        return $this->viaDataFrame(fn (DataFrame $f) => $f->filterGroups($predicate));
    }

    public function mutate(array $columns): static
    {
        try {
            return $this->mutateNative($columns);
        } catch (Unsupported) {
            return $this->viaDataFrame(fn (DataFrame $f) => $f->mutate($columns));
        }
    }

    private function mutateNative(array $columns): static
    {
        if ($this->rowwise || $this->keepsEmptyGroups() || array_any($columns, fn ($v) => $v instanceof Closure)) {
            return $this->viaDataFrame(fn (DataFrame $f) => $f->mutate($columns));
        }
        $df = $this->df;
        $factors = $this->factors;
        foreach ($columns as $name => $value) {
            $name = (string) $name;
            $translator = new Translator($df->schema(), $this->groups, $this->factors);
            $expr = match (true) {
                $value instanceof PhibbleExpr => $translator->row($value),
                is_array($value) => $this->listColumn($value, $df->height()),
                $value === null => lit(null)->cast('Boolean'),
                default => lit($value),
            };
            (new self($df))->guard($translator);
            $df = $df->withColumns([$name => $expr]);
            unset($factors[$name]);
        }

        return $this->with($df, factors: $factors);
    }

    private function listColumn(array $values, int $rows): Expr
    {
        if (count($values) !== $rows) {
            throw new InvalidArgumentException('A column of values must have '.$rows.' entries, not '.count($values).'.');
        }

        return lit(new Series('', array_values($values)));
    }

    public function mutateGroups(array $columns): static
    {
        return $this->viaDataFrame(fn (DataFrame $f) => $f->mutateGroups($columns));
    }

    public function rename(array $map): static
    {
        $this->check(...array_values($map));
        $flipped = [];
        foreach ($map as $new => $old) {
            $flipped[$old] = (string) $new;
        }
        $groups = array_map(fn (string $g) => $flipped[$g] ?? $g, $this->groups);
        $factors = [];
        foreach ($this->factors as $name => $meta) {
            $factors[$flipped[$name] ?? $name] = $meta;
        }

        return $this->with($this->df->rename($flipped), $groups, factors: $factors);
    }

    public function arrange(array $keys): static
    {
        try {
            return $this->arrangeNative($keys);
        } catch (Unsupported) {
            return $this->viaDataFrame(fn (DataFrame $f) => $f->arrange($keys));
        }
    }

    private function arrangeNative(array $keys): static
    {
        if ($keys === []) {
            return $this;
        }
        $by = [];
        $descending = [];
        $translator = $this->translator(false);
        foreach ($keys as $key) {
            [$expr, $desc] = match (true) {
                is_string($key) => [$key, false],
                is_array($key) => [$key[0], ($key[1] ?? 'asc') === 'desc'],
                $key instanceof Desc => [$key->expr, true],
                $key instanceof PhibbleExpr => [$key, false],
                default => throw new InvalidArgumentException('arrange() keys are names, [name, direction] pairs or expressions.'),
            };
            if (is_string($expr)) {
                $this->check($expr);
                $expr = new Col($expr);
            }
            $by[] = $expr instanceof Col && isset($this->factors[$expr->name])
                ? $this->groupOrder([$expr->name])[0]
                : $translator->row($expr);
            $descending[] = $desc;
        }

        return $this->with($this->df->sort($by, descending: $descending, nullsLast: true, maintainOrder: true));
    }

    public function groupBy(array $names, bool $drop = true): static
    {
        $this->check(...$names);

        return $this->with($this->df, array_values($names), false, drop: $drop);
    }

    public function dropsEmptyGroups(): bool
    {
        return $this->dropGroups;
    }

    public function ungroup(): static
    {
        return $this->with($this->df, [], false);
    }

    public function groups(): array
    {
        return $this->groups;
    }

    public function rowwise(array $idColumns = []): static
    {
        $this->check(...$idColumns);

        return $this->with($this->df, array_values($idColumns), true);
    }

    public function isRowwise(): bool
    {
        return $this->rowwise;
    }

    public function eachGroup(): iterable
    {
        if ($this->rowwise || $this->keepsEmptyGroups()) {
            foreach ($this->collect()->eachGroup() as $i => $group) {
                yield $i => ['key' => $group['key'], 'table' => self::fromTable($group['table'])];
            }

            return;
        }
        foreach ($this->groupRows() as $i => $group) {
            yield $i => ['key' => $group['key'], 'table' => $this->ungroup()->take($group['rows'])];
        }
    }

    public function groupRows(): array
    {
        if ($this->rowwise || $this->keepsEmptyGroups()) {
            return $this->collect()->groupRows();
        }
        if ($this->groups === []) {
            return [['key' => [], 'rows' => $this->nrow() === 0 ? [] : range(0, $this->nrow() - 1)]];
        }
        $index = $this->df->withRowIndex('__row')
            ->groupBy($this->groups, maintainOrder: true)
            ->agg(col('__row'))
            ->sort($this->groupOrder($this->groups), nullsLast: true, maintainOrder: true);
        $out = [];
        foreach ($index->toDicts() as $group) {
            $rows = $group['__row'];
            unset($group['__row']);
            $out[] = ['key' => $group, 'rows' => $rows];
        }

        return $out;
    }

    /** @param ?string $groups dplyr's .groups: drop_last (default), drop, keep or rowwise. */
    public function summarise(array $aggregates, ?string $groups = null): static
    {
        try {
            return $this->summariseNative($aggregates, $groups);
        } catch (Unsupported) {
            return $this->viaDataFrame(fn (DataFrame $f) => $f->summarise($aggregates, $groups));
        }
    }

    private function summariseNative(array $aggregates, ?string $groups): static
    {
        if ($this->rowwise || $this->keepsEmptyGroups() || array_any($aggregates, fn ($v) => $v instanceof Closure)) {
            return $this->viaDataFrame(fn (DataFrame $f) => $f->summarise($aggregates, $groups));
        }
        if (! in_array($groups, [null, 'drop_last', 'drop', 'keep', 'rowwise'], true)) {
            throw new InvalidArgumentException("summarise() .groups must be drop_last, drop, keep or rowwise, not '{$groups}'.");
        }
        $translator = $this->translator(false);
        $named = [];
        foreach ($aggregates as $name => $expr) {
            if (! $expr instanceof PhibbleExpr) {
                $expr = new \Phibble\Expr\Lit($expr);
            }
            if (! Translator::isScalar($expr)) {
                $size = $this->groups === [] ? $this->nrow() : $this->firstGroupSize();
                throw new \LengthException(
                    "summarise() '{$name}' must be size 1, not {$size}. Use an aggregate such as mean() or n(), or reframe() for several rows per group."
                );
            }
            $named[(string) $name] = $translator->aggregate($expr);
        }
        $this->guard($translator);
        if ($this->groups === []) {
            $out = $this->with($this->df->select($named), []);

            return $groups === 'rowwise' ? $out->rowwise() : $out;
        }
        $out = $this->df->groupBy($this->groups, maintainOrder: true)->agg($named)
            ->sort($this->groupOrder($this->groups), nullsLast: true, maintainOrder: true);

        return match ($groups ?? 'drop_last') {
            'drop_last' => $this->with($out, array_slice($this->groups, 0, -1)),
            'drop' => $this->with($out, []),
            'keep' => $this->with($out, $this->groups),
            'rowwise' => $this->with($out, $this->groups, true),
        };
    }

    private function firstGroupSize(): int
    {
        $sizes = $this->df->groupBy($this->groups, maintainOrder: true)->len()
            ->sort($this->groupOrder($this->groups), nullsLast: true, maintainOrder: true);

        return $sizes->height() === 0 ? 0 : (int) $sizes->item(0, 'len');
    }

    public function distinct(array $names = []): static
    {
        $names = $names === [] ? $this->df->columns() : $names;
        $this->check(...$names);
        $out = $this->df->unique(subset: $names, keep: 'first', maintainOrder: true)->select($names);

        return $this->with($out, array_values(array_intersect($this->groups, $names)));
    }

    public function take(array $indices): static
    {
        $rows = $this->nrow();
        foreach ($indices as $i) {
            if ($i !== null && ($i < 0 || $i >= $rows)) {
                throw new InvalidArgumentException("Row index {$i} is out of range for a table of {$rows} rows.");
            }
        }
        if ($indices === []) {
            return $this->with($this->df->head(0));
        }
        $rows = PolarsDataFrame::fromDict(['__row' => array_values($indices)], schema: ['__row' => 'UInt32']);
        $out = $rows->join($this->df->withRowIndex('__row'), on: '__row', how: 'left', maintainOrder: 'left')->drop('__row');

        return $this->with($out);
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
        $y = self::fromTable($other);
        [$df, $factors] = Joiner::run($this, $y, $by, $type, $suffix, $keep, $naMatches, $multiple, $relationship, $unmatched);

        return $this->with($df, array_values(array_intersect($this->groups, $df->columns())), false, $factors);
    }

    public function slice(int $offset, ?int $length = null): static
    {
        return $this->with($this->df->slice($offset, $length));
    }

    public function head(int $n = 6): static
    {
        return $this->with($this->df->head($n));
    }

    /**
     * tidyr's pivot_longer_spec(), in Polars: one frame per name-part
     * combination, stacked and stably sorted by (input row, combination).
     */
    public function pivotLongerSpec(array $spec, bool $dropNa = false): static
    {
        [$sources, $values, $parts, $combos] = self::readSpec($spec, 'longer');
        $this->check(...$sources);
        $ids = array_values(array_diff($this->columns(), $sources));
        $feed = [];
        foreach ($spec as $row) {
            $feed[serialize(array_map(fn (string $p) => $row[$p], $parts))][$row['.value']] = $row['.name'];
        }
        $types = $this->types();
        $schema = $this->df->schema();
        $targets = [];
        $factors = array_intersect_key($this->factors, array_flip($ids));
        foreach ($values as $v) {
            $present = array_values(array_unique(array_filter(array_map(fn (array $c) => $feed[serialize(array_values($c))][$v] ?? null, $combos))));
            $targets[$v] = $this->combinedType($present, $types, $schema);
            if ($present !== [] && array_all($present, fn (string $s) => isset($this->factors[$s]))) {
                $levels = array_merge(...array_map(fn (string $s) => $this->factors[$s]['levels'], $present));
                $factors[$v] = ['levels' => array_values(array_unique($levels)), 'ordered' => $this->factors[$present[0]]['ordered']];
            }
        }
        $indexed = $this->df->withRowIndex('__row');
        $frames = [];
        foreach ($combos as $c => $combo) {
            $select = ['__row' => col('__row'), '__combo' => lit($c)];
            foreach ($ids as $id) {
                $select[$id] = col($id);
            }
            foreach ($parts as $p) {
                $select[$p] = lit($combo[$p]);
            }
            foreach ($values as $v) {
                $source = $feed[serialize(array_values($combo))][$v] ?? null;
                $select[$v] = ($source === null ? lit(null) : col($source))->cast($targets[$v]);
            }
            $frames[] = $indexed->select($select);
        }
        $out = concat($frames, how: 'vertical_relaxed')->sort(['__row', '__combo'], maintainOrder: true);
        if ($dropNa) {
            $out = $out->filter(all_horizontal(...array_map(fn (string $v) => col($v)->isNull(), $values))->not());
        }

        return $this->with($out->drop('__row', '__combo'), array_values(array_intersect($this->groups, $ids)), factors: $factors);
    }

    /**
     * The Polars type for a pivoted value column fed by $sources, with vctrs'
     * rules: numbers combine to the widest, dates and datetimes to datetime.
     *
     * @param list<string> $sources
     */
    private function combinedType(array $sources, array $types, array $schema): string
    {
        if ($sources === []) {
            return 'Boolean';
        }
        $kinds = array_values(array_unique(array_map(fn (string $s) => $types[$s]->value, $sources)));
        if (count($kinds) === 1) {
            return $schema[$sources[0]];
        }
        if (array_diff($kinds, ['bool', 'int', 'float']) === []) {
            return in_array('float', $kinds, true) ? 'Float64' : 'Int64';
        }
        if (array_diff($kinds, ['date', 'datetime']) === []) {
            return 'Datetime(us)';
        }
        $first = $sources[0];
        $last = $sources[array_key_last($sources)];

        throw new InvalidArgumentException(sprintf(
            "Can't combine `%s` <%s> and `%s` <%s>.",
            $first, $types[$first]->abbreviation(), $last, $types[$last]->abbreviation(),
        ));
    }

    /**
     * tidyr's pivot_wider_spec(), in Polars: one aggregate per spec row over
     * the id groups, in first-appearance order. Duplicate cells make every new
     * column a list-column, with tidyr's warning.
     */
    public function pivotWiderSpec(array $spec, ?array $idColumns = null, array $valuesFill = []): static
    {
        [, $values, $from] = self::readSpec($spec, 'wider');
        $this->check(...$values, ...$from);
        $idColumns ??= array_values(array_diff($this->columns(), $from, $values));
        $this->check(...$idColumns);

        // Names-from values compare as labels, as phibble's FactorColumn::label() does.
        $label = fn (mixed $v): ?string => match (true) {
            $v === null => null,
            is_bool($v) => $v ? 'TRUE' : 'FALSE',
            default => (string) $v,
        };
        $matches = function (array $row) use ($from, $label) {
            $conds = array_map(function (string $f) use ($row, $label) {
                $want = $label($row[$f]);

                return $want === null ? col($f)->isNull() : col($f)->cast('String')->eq($want);
            }, $from);

            return count($conds) === 1 ? $conds[0] : all_horizontal(...$conds);
        };
        $keyed = $this->df->withColumns(['__in_spec' => any_horizontal(...array_map(fn (array $r) => $matches($r)->fillNull(false), $spec))]);
        $counts = $keyed->filter(col('__in_spec'))->groupBy([...$idColumns, ...$from])->len();
        $duplicates = $counts->height() > 0 && $counts->select(col('len')->max())->item() > 1;

        $aggs = [];
        $factors = array_intersect_key($this->factors, array_flip($idColumns));
        foreach ($spec as $row) {
            $name = (string) $row['.name'];
            $cell = col($row['.value'])->filter($matches($row)->fillNull(false));
            if ($duplicates) {
                $aggs[$name] = when($cell->len()->eq(0))->then(lit(null))->otherwise($cell->implode());

                continue;
            }
            $first = $cell->first();
            $aggs[$name] = array_key_exists($row['.value'], $valuesFill)
                ? when($cell->len()->eq(0))->then(lit($valuesFill[$row['.value']]))->otherwise($first)
                : $first;
            if (isset($this->factors[$row['.value']])) {
                $factors[$name] = $this->factors[$row['.value']];
            }
        }
        $out = $this->df->groupBy($idColumns, maintainOrder: true)->agg($aggs);
        if ($duplicates) {
            $by = implode(', ', [...$idColumns, ...$from]);
            trigger_error(
                "Values from `{$values[0]}` are not uniquely identified; output will contain list-cols.\n"
                ."• Use `values_fn = list` to suppress this warning.\n"
                ."• Use `values_fn = {summary_fun}` to summarise duplicates.\n"
                ."• Use the following dplyr code to identify duplicates.\n"
                ."  {data} |>\n"
                ."  dplyr::summarise(n = dplyr::n(), .by = c({$by})) |>\n"
                .'  dplyr::filter(n > 1L)',
                E_USER_WARNING,
            );
        }

        return $this->with($out, array_values(array_intersect($this->groups, $idColumns)), factors: $factors);
    }

    /**
     * Validate a pivot spec as phibble does.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>, 3?: list<array<string, mixed>>}
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
            $combos[serialize($combo)] = $combo;
        }

        return [$names, $values, $parts, array_values($combos)];
    }

    /** @internal Factor metadata for Joiner. */
    public function factorLevels(): array
    {
        return $this->factors;
    }
}
