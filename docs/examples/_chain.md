```php
$penguins = DataFrame::fromCsv('penguins.csv');

$counts = $penguins
    ->groupBy(['island'])
    ->summarise(['n' => n()])
    ->arrange([desc(col('n'))]);

echo $counts;
```

```{.text .output}
# A tibble: 3 × 2
  island        n
  <chr>     <int>
1 Biscoe      168
2 Dream       124
3 Torgersen    52
```
