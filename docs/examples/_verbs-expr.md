```php
$penguins = DataFrame::fromCsv('penguins.csv');

$heavy = $penguins
    ->select(['species', 'island', 'body_mass', 'year'])
    ->filter(col('body_mass')->gt(5500))
    ->mutate(['kg' => col('body_mass')->div(1000)])
    ->arrange([desc(col('body_mass'))])
    ->head(5);

echo $heavy;
```

```{.text .output}
# A tibble: 5 × 5
  species island body_mass  year    kg
  <chr>   <chr>      <int> <int> <dbl>
1 Gentoo  Biscoe      6300  2007  6.3 
2 Gentoo  Biscoe      6050  2007  6.05
3 Gentoo  Biscoe      6000  2008  6   
4 Gentoo  Biscoe      6000  2009  6   
5 Gentoo  Biscoe      5950  2008  5.95
```
