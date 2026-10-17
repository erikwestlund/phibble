```php
$scores = tibble(
    student: ['ana', 'ben', 'cy'],
    score: [81.5, 74.0, null],
    passed: fn (DataFrame $t) => array_map(fn ($s) => $s === null ? null : $s >= 75, $t['score']),
    term: 'fall',
);

echo $scores;
```

```{.text .output}
# A tibble: 3 × 4
  student score passed term 
  <chr>   <dbl> <lgl>  <chr>
1 ana      81.5 TRUE   fall 
2 ben      74   FALSE  fall 
3 cy       NA   NA     fall
```
