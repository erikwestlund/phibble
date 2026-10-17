```php
$penguins = DataFrame::fromCsv('penguins.csv')
    ->asFactor('species')
    ->filter(col('species')->ne('Chinstrap'));

echo $penguins->groupBy(['species'], drop: false)->summarise(['n' => n()]);
```

```{.text .output}
# A tibble: 3 × 2
  species       n
  <fct>     <int>
1 Adelie      152
2 Chinstrap     0
3 Gentoo      124
```
