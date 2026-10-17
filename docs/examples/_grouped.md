```php
$penguins = DataFrame::fromCsv('penguins.csv')->dropNa(['body_mass']);

$centered = $penguins
    ->groupBy(['species'])
    ->mutate(['vs_species' => col('body_mass')->sub(mean(col('body_mass')))])
    ->select(['species', 'body_mass', 'vs_species'])
    ->head(4);

echo $centered;
```

```{.text .output}
# A tibble: 4 × 3
# Groups:   species [1]
  species body_mass vs_species
  <chr>       <int>      <dbl>
1 Adelie       3750       49.3
2 Adelie       3800       99.3
3 Adelie       3250     -451. 
4 Adelie       3450     -251.
```
