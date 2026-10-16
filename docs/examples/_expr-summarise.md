```php
$penguins = Frame::fromCsv('penguins.csv');

echo $penguins
    ->groupBy(['island'])
    ->summarise([
        'n' => n(),
        'mass' => mean(col('body_mass')),
        'mass_rm' => mean(col('body_mass'), na_rm: true),
        'q90' => quantile(col('body_mass'), 0.9, na_rm: true),
        'species' => n_distinct(col('species')),
        'any_heavy' => any(col('body_mass')->gt(6000), na_rm: true),
    ]);
```

```{.text .output}
# A tibble: 3 × 7
  island        n  mass mass_rm   q90 species any_heavy
  <chr>     <int> <dbl>   <dbl> <dbl>   <int> <lgl>    
1 Biscoe      168   NA    4716.  5670       2 TRUE     
2 Dream       124 3713.   3713.  4300       2 FALSE    
3 Torgersen    52   NA    3706.  4300       1 FALSE
```
