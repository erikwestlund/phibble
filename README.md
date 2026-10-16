# phibble

A typed, immutable, in-memory data frame for PHP 8.5. It is the shared table type for phplyr, phpolars, php-stats and cmdstanphp.

```php
use Phibble\Frame;
use Phibble\Row;

$penguins = Frame::fromCsv('penguins.csv');

$penguins
    |> (fn ($f) => $f->filter(fn (Row $r) => $r->body_mass_g !== null))
    |> (fn ($f) => $f->groupBy(['species']))
    |> (fn ($f) => $f->summarise(['mass' => fn ($g) => stats_mean($g->column('body_mass_g'))]));
```

## Model

- **Types:** `float`, `int`, `bool`, `string`, `factor` and `date` (`DateTimeImmutable`). Types are inferred on load. Use `cast()`, `asFactor()` or a `$types` argument to set them yourself.
- **NA:** PHP `null`.
- **Immutable:** every operation returns a new frame.
- **Rows:** `Phibble\Row` is read-only and supports both `$r->price` and `$r['price']`.
- **Grouping:** grouping is metadata. `groupBy()` records the key columns, and `filter`, `mutate` and `summarise` act per group. Row closures receive `(Row $row, Table $group)`, so a grouped mutate can use group statistics:
  `mutate(['dev' => fn ($r, $g) => $r->x - stats_mean($g->column('x'))])`.

## The Table contract

`Phibble\Contracts\Table` is the interface every backend implements: this in-memory `Frame`, phpolars, and a later lazy SQL table. phplyr codes against it.

It provides:
- `columns`, `types`, `nrow`, `ncol` and `column`
- the verbs `select`, `filter`, `mutate`, `rename` (`new => old`), `arrange`, `groupBy`, `ungroup`, `groups`, `eachGroup`, `summarise`, `distinct`, `slice` and `head`
- `toRows`, `toColumns` and `collect`
- `Countable`, and iteration over `Row`s

## Loading data

```php
Frame::fromRows([['a' => 1, 'b' => 'x'], ...]);
Frame::fromColumns(['a' => [1, 2], 'b' => ['x', 'y']], types: ['b' => 'factor']);
Frame::fromCsv('data.csv', types: ['zip' => 'string'], na: ['', 'NA']);
Frame::fromPdo($pdo, 'select * from visits where clinic = ?', [3]);
Frame::fromQuery(Visit::where('clinic', 3));          // Laravel query or Eloquent builder
Frame::fromQuery($dbal->createQueryBuilder()->...);   // Doctrine DBAL QueryBuilder
Frame::concat([$a, $b]);                               // stack by column name
```

Query builders are duck-typed, so phibble has no dependency on Laravel or Doctrine. Each data source implements `Phibble\Source\Source`. `PdoSource` exposes its PDO connection, SQL and bindings, so a later lazy SQL backend can compile verbs into the query.

## Handing data to php-stats

```php
$fit = stats_ols($frame->toMatrix(['age', 'dose']), $frame->toVector('response'));
```

`toMatrix()` returns rows of floats with NA as `null`; bool columns become 0 and 1.

## Development

```sh
composer install
php85 vendor/bin/pest
```

Column storage sits behind `Phibble\Column\Column`, so another backend can supply its own storage. For now only plain PHP arrays are implemented.
