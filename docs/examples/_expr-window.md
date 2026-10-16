```php
$sales = tribble(
    ['store', 'week', 'units'],
    ['a', 1, 10], ['a', 2, 14], ['a', 3, 9],
    ['b', 1, 7], ['b', 2, 7], ['b', 3, 12],
);

echo $sales
    ->groupBy(['store'])
    ->mutate([
        'prev' => lag(col('units')),
        'change' => col('units')->sub(lag(col('units'))),
        'running' => cumsum(col('units')),
        'rank' => min_rank(desc(col('units'))),
        'tile' => ntile(col('units'), n: 2),
    ]);
```

```{.text .output}
# A tibble: 6 × 8
# Groups:   store [2]
  store  week units  prev change running  rank  tile
  <chr> <int> <int> <int>  <int>   <int> <int> <int>
1 a         1    10    NA     NA      10     2     1
2 a         2    14    10      4      24     1     2
3 a         3     9    14     -5      33     3     1
4 b         1     7    NA     NA       7     2     1
5 b         2     7     7      0      14     2     1
6 b         3    12     7      5      26     1     2
```
