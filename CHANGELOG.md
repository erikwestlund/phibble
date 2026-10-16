# Changelog

All notable changes to phibble are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/). Before 1.0, minor versions may
change the public API.

## [0.2.0] - Unreleased

### Added

- The Polars backend, `Phibble\Polars\PolarsTable`, moved here from the
  phpolars-phibble package. It implements the Table contract on Polars through
  the php_polars extension, which is suggested and not required. `polars()`
  creates one from any table.
- A Backends documentation page: `Frame`, `SqlTable`, and `PolarsTable` as
  interchangeable implementations of `Phibble\Contracts\Table`.

## [0.1.0] - 2026-10-16

The first release. phibble is a PHP 8.5 port of R's tibble, with readr-style
input and output, and the shared table type for phplyr, phpolars, php-stats,
and cmdstanphp.

### Frames and types

- `Frame`, an immutable, typed, in-memory data frame. NA is `null`.
- Column types: float, int, bool, string, factor (with ordered factors), date,
  datetime, and list-columns. Dates and datetimes are stored as integers.
- Type inference following R's `c()`, and casts following R's `as.*()`, with
  a warning when values become NA.
- forcats basics: `fctRelevel`, `fctLevels`, `fctRev`, `fctInorder`,
  `fctInfreq`, `fctReorder`, `fctRecode`, `fctCollapse`, and `fctDrop`.

### tibble

- `tibble()`, `tribble()`, `as_tibble()`, `enframe()`, `deframe()`,
  `addRow()`, and `addColumn()`, with tibble's recycling rules and messages.
- Name repair as in vctrs: `check_unique`, `unique`, `universal`, and
  `minimal`.
- Printing and `glimpse()` that match tibble's output, checked against R.
- Strict column access through `$frame->x` and `$frame['x']`.

### Verbs and the Table contract

- `Phibble\Contracts\Table`, the interface that `Frame`, `SqlTable`, and
  phpolars' `PolarsTable` implement.
- `select`, `filter`, `mutate`, `rename`, `arrange`, `groupBy` (with
  `drop:`), `rowwise`, `summarise` (with `.groups`), `distinct`, `slice`,
  `head`, `take`, `eachGroup`, `groupRows`, `mutateGroups`, and
  `filterGroups`, with dplyr 1.1 semantics and dplyr's group order.
- Joins with dplyr's semantics, including `join_by` comparisons, rolling
  (`closest`) joins, `keep`, `naMatches`, `multiple`, `relationship`, and
  `unmatched`.
- `bindCols`, `concat`, `nest`, `unnest`, and tidyr's spec-based pivots
  (`pivotLongerSpec`, `pivotWiderSpec`).

### Expressions

- `Phibble\Expr`: inspectable, serializable expressions with dplyr's
  semantics, accepted by `filter`, `mutate`, `summarise`, and `arrange`.
- A fixed registry of functions: operators, conditionals, math, aggregates,
  window functions, stringr basics (regular expressions by default, with
  `fixed()` and `regex()`), and lubridate basics (parsing, parts, rounding,
  periods, `difftime`, and time zones).

### SQL tables

- `SqlTable` and `tbl()`: lazy tables over PDO for SQLite, PostgreSQL, and
  MySQL. Verbs with expressions compile to SQL; anything without an exact SQL
  translation runs in memory, so results match `Frame`.
- `showQuery()` and `bindings()`, source row order, and native joins
  including `join_by` comparisons and rolling joins.

### Input and output

- CSV reading with readr's options and type guessing, and `writeCsv()` /
  `toCsv()` as `write_csv()` writes.
- The php-stats extension's native CSV reader, when it is loaded.
- JSON in records or columns form, PDO queries, Laravel and Doctrine query
  builders, and Laravel collections.
- `toModelData()` for php-stats' model functions.

### Laravel

- An auto-discovered service provider with `->toFrame()` on collections and
  query builders and `->phibble()` for lazy SQL tables. Global scopes such as
  soft deletes apply.

[0.2.0]: https://github.com/erikwestlund/phibble/releases/tag/v0.2.0
[0.1.0]: https://github.com/erikwestlund/phibble/releases/tag/v0.1.0
