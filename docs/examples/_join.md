```php
$penguins = DataFrame::fromCsv('penguins.csv');
$islands = tribble(
    ['island', 'area_km2'],
    ['Biscoe', 6.1],
    ['Dream', 0.9],
);

$joined = $penguins
    ->distinct(['species', 'island'])
    ->join($islands, ['island'], 'left');

echo $joined;
```

```{.text .output}
# A tibble: 5 × 3
  species   island    area_km2
  <chr>     <chr>        <dbl>
1 Adelie    Torgersen     NA  
2 Adelie    Biscoe         6.1
3 Adelie    Dream          0.9
4 Gentoo    Biscoe         6.1
5 Chinstrap Dream          0.9
```
