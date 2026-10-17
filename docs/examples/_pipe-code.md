```php
$penguins = DataFrame::fromCsv('penguins.csv');

$counts = $penguins
    |> (fn (DataFrame $f) => $f->groupBy(['island']))
    |> (fn (DataFrame $f) => $f->summarise(['n' => n()]))
    |> (fn (DataFrame $f) => $f->arrange([desc(col('n'))]));

echo $counts;
```
