# phibble

phibble is a port of R's tibble to PHP, with readr-style input and output.
A `DataFrame` holds typed columns, treats `null` as NA, and returns a new frame
from every operation. It is the shared table type for
[phplyr](https://github.com/erikwestlund/phplyr),
[phit](https://github.com/erikwestlund/phit), phpolars, and
cmdstanphp.

Documentation: <https://erikwestlund.github.io/phibble/>

## Install

```bash
composer require erikwestlund/phibble
```

phibble needs PHP 8.3 or later and the PDO extension. On PHP 8.5, pipelines
can also use the `|>` operator.

## Example

```php
use Phibble\DataFrame;
use function Phibble\Expr\{col, mean, n};

$penguins = DataFrame::fromCsv('penguins.csv');

echo $penguins
    ->filter(col('body_mass')->isNa()->not())
    ->groupBy(['species'])
    ->summarise(['n' => n(), 'mass' => mean(col('body_mass'))]);
```

```
# A tibble: 3 × 3
  species       n  mass
  <chr>     <int> <dbl>
1 Adelie      151 3701.
2 Chinstrap    68 3733.
3 Gentoo      123 5076.
```

## Features

- **tibble:** `tibble()`, `tribble()`, `as_tibble()`, `addRow()`,
  `addColumn()`, `enframe()`, `deframe()`, name repair, strict column access,
  and printing and `glimpse()` that match tibble's output.
- **Types:** float, int, bool, string, factor (with ordered factors and the
  forcats functions), date, datetime, and list-columns. Casts follow R's
  `as.*()` rules.
- **Input and output:** CSV with readr's options and type guessing (and the
  phit extension's native reader when it is loaded), JSON, PDO queries,
  Laravel and Doctrine query builders, and Laravel collections.
- **Verbs:** `select`, `filter`, `mutate`, `arrange`, `groupBy`, `summarise`,
  `distinct`, joins (including `join_by` comparisons and rolling joins),
  `nest`, `unnest`, and tidyr's spec-based pivots, with dplyr 1.1 semantics.
- **Expressions:** `Phibble\Expr` describes computations as inspectable,
  serializable trees that follow dplyr's semantics, with stringr and
  lubridate basics.
- **SQL tables:** `SqlTable` compiles verbs to SQL for SQLite, PostgreSQL, and
  MySQL, like dbplyr. Anything SQL cannot compute exactly runs in memory, so
  results match `DataFrame`.
- **Laravel:** `->toDataFrame()` and `->phibble()` on queries and collections.
- **Backends:** `DataFrame` (in memory), `SqlTable` (databases), and `PolarsTable`
  (Polars, with the php_polars extension) implement one Table contract, so the
  same pipeline runs on each.

## Development

```bash
composer install
vendor/bin/pest
```

See the [development guide](https://erikwestlund.github.io/phibble/development.html)
for the R comparison scripts, database tests, and documentation build.
