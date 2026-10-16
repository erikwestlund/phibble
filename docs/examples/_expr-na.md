```php
$frame = tibble(
    a: [true, false, null, null],
    b: [null, null, true, false],
    x: [2.5, 0.5, -1.5, 2.675],
    y: [1, null, 3, 4],
);

echo $frame->mutate([
    'and' => col('a')->and(col('b')),
    'or' => col('a')->or(col('b')),
    'sum' => col('y')->add(1),
    'round' => Phibble\Expr\round(col('x')),
    'round2' => Phibble\Expr\round(col('x'), digits: 2),
    'mod' => lit(-7)->mod(3),
    'cumsum' => cumsum(col('y')),
]);
```

```{.text .output}
# A tibble: 4 × 11
  a     b         x     y and   or      sum round round2   mod cumsum
  <lgl> <lgl> <dbl> <int> <lgl> <lgl> <int> <dbl>  <dbl> <int>  <int>
1 TRUE  NA     2.5      1 NA    TRUE      2     2   2.5      2      1
2 FALSE NA     0.5     NA FALSE NA       NA     0   0.5      2     NA
3 NA    TRUE  -1.5      3 NA    TRUE      4    -2  -1.5      2     NA
4 NA    FALSE  2.68     4 FALSE NA        5     3   2.67     2     NA
```
