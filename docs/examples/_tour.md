```php
$penguins = DataFrame::fromCsv('penguins.csv');

$summary = $penguins
    ->filter(col('body_mass')->isNa()->not())
    ->groupBy(['species', 'sex'])
    ->summarise([
        'n' => n(),
        'mass' => mean(col('body_mass')),
        'flipper' => median(col('flipper_len')),
    ]);

echo $summary;
```

```{.text .output}
# A tibble: 8 × 5
# Groups:   species [3]
  species   sex        n  mass flipper
  <chr>     <chr>  <int> <dbl>   <dbl>
1 Adelie    female    73 3369.    188 
2 Adelie    male      73 4043.    193 
3 Adelie    <NA>       5 3540     186 
4 Chinstrap female    34 3527.    192 
5 Chinstrap male      34 3939.    200.
6 Gentoo    female    58 4680.    212 
7 Gentoo    male      61 5485.    221 
8 Gentoo    <NA>       4 4588.    216
```
