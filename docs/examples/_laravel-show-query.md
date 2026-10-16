```php
$heaviest = Penguin::query()
    ->measured()
    ->phibble()
    ->groupBy(['island'])
    ->filter(col('body_mass')->eq(max(col('body_mass'))))
    ->select(['island', 'species', 'body_mass']);

echo $heaviest->showQuery(), "\n\n";
echo $heaviest->arrange(['island'])->collect();
```

```{.text .output}
SELECT "island", "species", "body_mass" FROM (SELECT "island", "species", "body_mass", "__phibble_row" FROM (SELECT "id", "species", "island", "bill_len", "bill_dep", "flipper_len", "body_mass", "sex", "year", "__phibble_row" FROM (SELECT *, ("body_mass" = (CASE WHEN COUNT(*) OVER (PARTITION BY "island") > COUNT("body_mass") OVER (PARTITION BY "island") THEN NULL ELSE (CASE WHEN COUNT("body_mass") OVER (PARTITION BY "island") = 0 THEN -9e999 ELSE MAX("body_mass") OVER (PARTITION BY "island") END) END)) AS "__phibble_keep" FROM (SELECT *, ROW_NUMBER() OVER () AS "__phibble_row" FROM (select * from "penguins" where "body_mass" is not null) AS phibble_source) AS q1) AS q2 WHERE "__phibble_keep" = TRUE) AS q3) AS q4 ORDER BY ("__phibble_row" IS NULL), "__phibble_row"

# A tibble: 3 × 3
# Groups:   island [3]
  island    species   body_mass
  <chr>     <chr>         <int>
1 Biscoe    Gentoo         6300
2 Dream     Chinstrap      4800
3 Torgersen Adelie         4700
```
