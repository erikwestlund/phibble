```php
$penguins = Frame::fromCsv('penguins.csv');

$out = $penguins
    ->filter(is_na(col('body_mass'))->not())
    ->groupBy(['species'])
    ->mutate([
        'z' => col('body_mass')->sub(mean(col('body_mass')))->div(sd(col('body_mass'))),
        'rank' => min_rank(desc(col('body_mass'))),
        'size' => case_when(
            col('body_mass')->ge(5000), 'large',
            col('body_mass')->ge(3500), 'medium',
            default: 'small',
        ),
    ])
    ->filter(col('rank')->le(2))
    ->select(['species', 'body_mass', 'z', 'rank', 'size']);

echo $out;
```

```{.text .output}
# A tibble: 6 × 5
# Groups:   species [3]
  species   body_mass     z  rank size  
  <chr>         <int> <dbl> <int> <chr> 
1 Adelie         4725  2.23     2 medium
2 Adelie         4775  2.34     1 medium
3 Gentoo         6300  2.43     1 large 
4 Gentoo         6050  1.93     2 large 
5 Chinstrap      4550  2.13     2 medium
6 Chinstrap      4800  2.78     1 medium
```
