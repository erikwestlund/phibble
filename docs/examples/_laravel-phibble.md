```php
$summary = Penguin::query()
    ->measured()
    ->where('year', '>=', 2008)
    ->phibble()
    ->groupBy(['species', 'sex'])
    ->summarise([
        'n' => n(),
        'mass' => mean(col('body_mass')),
    ]);

echo $summary->collect();
```

```{.text .output}
# A tibble: 7 × 4
# Groups:   species [3]
  species   sex        n  mass
  <chr>     <chr>  <int> <dbl>
1 Adelie    female    51 3360.
2 Adelie    male      51 4046.
3 Chinstrap female    21 3501.
4 Chinstrap male      21 4013.
5 Gentoo    female    42 4703.
6 Gentoo    male      44 5459.
7 Gentoo    <NA>       3 4750
```
