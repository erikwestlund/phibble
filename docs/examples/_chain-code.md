```php
$penguins = DataFrame::fromCsv('penguins.csv');

$counts = $penguins
    ->groupBy(['island'])
    ->summarise(['n' => n()])
    ->arrange([desc(col('n'))]);

echo $counts;
```
