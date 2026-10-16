# phibble

phibble is a port of R's tibble to PHP 8.5, with readr-style input and output.
A `Frame` holds typed columns, treats `null` as NA, and returns a new frame
from every operation. It is the shared table type for
[phplyr](https://github.com/erikwestlund/phplyr),
[php-stats](https://github.com/erikwestlund/php-stats), phpolars, and
cmdstanphp.

Documentation: <https://erikwestlund.github.io/phibble/>

## Install

```bash
composer require erikwestlund/phibble
```

phibble needs PHP 8.5 and its PDO extension.

## Example

```php
use Phibble\Frame;
use function Phibble\Expr\{col, mean, n};

$penguins = Frame::fromCsv('penguins.csv');

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
- **Input and output:** CSV with readr's options and type guessing, JSON,
  PDO queries, Laravel and Doctrine query builders, and Laravel collections.
- **Verbs:** `select`, `filter`, `mutate`, `arrange`, `groupBy`, `summarise`,
  `distinct`, `join`, `nest`, and `unnest`, with dplyr 1.1 semantics.
- **Expressions:** `Phibble\Expr` describes computations as inspectable,
  serializable trees that follow dplyr's semantics.
- **SQL tables:** `SqlTable` compiles verbs to SQL for SQLite, PostgreSQL, and
  MySQL, like dbplyr.
- **The Table contract:** `Phibble\Contracts\Table` lets other backends, such
  as phpolars, run the same pipelines.

## Development

```bash
composer install
php vendor/bin/pest
```

See the [development guide](https://erikwestlund.github.io/phibble/development.html)
for the R comparison scripts, database tests, and documentation build.
