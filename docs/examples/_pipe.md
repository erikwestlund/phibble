```php
$penguins = Frame::fromCsv('penguins.csv');

$counts = $penguins
    |> (fn (Frame $f) => $f->groupBy(['island']))
    |> (fn (Frame $f) => $f->summarise(['n' => n()]))
    |> (fn (Frame $f) => $f->arrange([desc(col('n'))]));

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
