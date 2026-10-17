```php
// Load penguins.csv into an in-memory SQLite database.
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE penguins (species TEXT, island TEXT, bill_len REAL, bill_dep REAL,
    flipper_len INTEGER, body_mass INTEGER, sex TEXT, year INTEGER)');
$insert = $pdo->prepare('INSERT INTO penguins VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach (DataFrame::fromCsv('penguins.csv')->toRows() as $row) {
    $insert->execute(array_values($row));
}

$penguins = tbl($pdo, 'penguins');

$summary = $penguins
    ->filter(col('year')->eq(2008))
    ->groupBy(['species'])
    ->summarise(['n' => n(), 'mass' => mean(col('body_mass'), na_rm: true)]);

echo get_class($summary), "\n\n";
echo $summary->showQuery(), "\n\n";
echo json_encode($summary->bindings()), "\n\n";
echo $summary->collect();
```

```{.text .output}
Phibble\Sql\SqlTable

SELECT "species", "n", "mass" FROM (SELECT "species", "n", "mass", ROW_NUMBER() OVER (ORDER BY ("species" IS NULL), "species") AS "__phibble_row" FROM (SELECT "species" AS "species", COUNT(*) AS "n", (CASE WHEN COUNT("body_mass") = 0 THEN NULL ELSE AVG(CAST("body_mass" AS REAL)) END) AS "mass" FROM (SELECT * FROM (SELECT *, rowid AS "__phibble_row" FROM "penguins") AS q1 WHERE ("year" = ?)) AS q2 GROUP BY "species") AS q3) AS q4 ORDER BY ("__phibble_row" IS NULL), "__phibble_row"

[2008]

# A tibble: 3 × 3
  species       n  mass
  <chr>     <int> <dbl>
1 Adelie       50 3742 
2 Chinstrap    18 3800 
3 Gentoo       46 5020.
```
