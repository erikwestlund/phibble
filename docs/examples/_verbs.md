```php
$penguins = DataFrame::fromCsv('penguins.csv');

$heavy = $penguins
    ->select(['species', 'island', 'body_mass', 'year'])
    ->filter(fn ($r) => $r->body_mass !== null && $r->body_mass > 5500)
    ->mutate(['kg' => fn ($r) => $r->body_mass / 1000])
    ->arrange([['body_mass', 'desc']])
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
