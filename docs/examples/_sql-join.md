```php
$pdo = new PDO('sqlite::memory:');
$visits = tbl($pdo, "SELECT 1 AS clinic_id, 120 AS bp UNION ALL SELECT 2, 135 UNION ALL SELECT NULL, 128");
$clinics = tbl($pdo, "SELECT 1 AS id, 'north' AS name UNION ALL SELECT NULL, 'unassigned'");

$joined = $visits->join($clinics, [['clinic_id', 'id']], 'left');

echo $joined->showQuery(), "\n\n";
echo $joined->arrange(['bp'])->collect();
```

```{.text .output}
SELECT "clinic_id", "bp", "name" FROM (SELECT jq1x."clinic_id" AS "clinic_id", jq1x."bp" AS "bp", jq1y."name" AS "name", ROW_NUMBER() OVER (ORDER BY (jq1x."__phibble_row" IS NULL), jq1x."__phibble_row", (jq1y."__phibble_row" IS NULL), jq1y."__phibble_row") AS "__phibble_row" FROM (SELECT *, ROW_NUMBER() OVER () AS "__phibble_row" FROM (SELECT 1 AS clinic_id, 120 AS bp UNION ALL SELECT 2, 135 UNION ALL SELECT NULL, 128) AS phibble_source) AS jq1x LEFT JOIN (SELECT *, ROW_NUMBER() OVER () AS "__phibble_row" FROM (SELECT 1 AS id, 'north' AS name UNION ALL SELECT NULL, 'unassigned') AS phibble_source) AS jq1y ON (jq1x."clinic_id" IS jq1y."id")) AS q2 ORDER BY ("__phibble_row" IS NULL), "__phibble_row"

# A tibble: 3 × 3
  clinic_id    bp name      
      <int> <int> <chr>     
1         1   120 north     
2        NA   128 unassigned
3         2   135 <NA>
```
