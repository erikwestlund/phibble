```php
$pdo = new PDO('sqlite::memory:');
$sales = tbl($pdo, <<<'SQL'
    SELECT 'a' AS store, 1 AS week, 10 AS units
    UNION ALL SELECT 'a', 2, 14 UNION ALL SELECT 'a', 3, 9
    UNION ALL SELECT 'b', 1, 7 UNION ALL SELECT 'b', 2, NULL
    SQL);

$out = $sales
    ->groupBy(['store'])
    ->arrange(['store', 'week'])
    ->mutate([
        'running' => cumsum(col('units')),
        'of_best' => col('units')->div(max(col('units'), na_rm: true)),
    ]);

echo $out->showQuery(), "\n\n";
echo $out->collect();
```

```{.text .output}
SELECT "store", "week", "units", "running", "of_best" FROM (SELECT "store", "week", "units", "running", (CAST("units" AS REAL) / NULLIF(MAX("units") OVER (PARTITION BY "store"), 0)) AS "of_best", "__phibble_row" FROM (SELECT "store", "week", "units", (CASE WHEN COUNT(*) OVER (PARTITION BY "store" ORDER BY ("store" IS NULL), "store", ("week" IS NULL), "week", ("__phibble_row" IS NULL), "__phibble_row" ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) > COUNT("units") OVER (PARTITION BY "store" ORDER BY ("store" IS NULL), "store", ("week" IS NULL), "week", ("__phibble_row" IS NULL), "__phibble_row" ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) THEN NULL ELSE SUM("units") OVER (PARTITION BY "store" ORDER BY ("store" IS NULL), "store", ("week" IS NULL), "week", ("__phibble_row" IS NULL), "__phibble_row" ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) END) AS "running", "__phibble_row" FROM (SELECT *, ROW_NUMBER() OVER () AS "__phibble_row" FROM (SELECT 'a' AS store, 1 AS week, 10 AS units
UNION ALL SELECT 'a', 2, 14 UNION ALL SELECT 'a', 3, 9
UNION ALL SELECT 'b', 1, 7 UNION ALL SELECT 'b', 2, NULL) AS phibble_source) AS q1) AS q2) AS q3 ORDER BY ("store" IS NULL), "store", ("week" IS NULL), "week", ("__phibble_row" IS NULL), "__phibble_row"

# A tibble: 5 × 5
# Groups:   store [2]
  store  week units running of_best
  <chr> <int> <int>   <int>   <dbl>
1 a         1    10      10   0.714
2 a         2    14      24   1    
3 a         3     9      33   0.643
4 b         1     7       7   1    
5 b         2    NA      NA  NA
```
