```php
use Phibble\Sql\Compiler;
use Phibble\Sql\MysqlDialect;
use Phibble\Sql\PostgresDialect;
use Phibble\Sql\SqliteDialect;

$expressions = [
    'mean, NA aware' => mean(col('bp')),
    'ratio' => col('bp')->div(col('baseline')),
    'contains' => str_detect(col('name'), 'north'),
    'in, with NA' => col('arm')->isIn(['a', null]),
];

foreach ([new SqliteDialect(), new PostgresDialect(), new MysqlDialect()] as $dialect) {
    echo "-- {$dialect->name()}\n";
    $compiler = new Compiler($dialect, mode: 'summary');
    foreach ($expressions as $label => $expr) {
        echo str_pad($label, 16), $compiler->compile($expr)->sql, "\n";
    }
    echo "\n";
}

$window = new Compiler(new PostgresDialect(), groups: ['clinic'], order: [[col('visit'), false]]);
echo $window->compile(cumsum(col('bp')))->sql, "\n";
```

```{.text .output}
-- SQLite
mean, NA aware  (CASE WHEN COUNT(*) > COUNT("bp") THEN NULL ELSE AVG(CAST("bp" AS REAL)) END)
ratio           (CAST("bp" AS REAL) / NULLIF("baseline", 0))
contains        (INSTR("name", ?) > 0)
in, with NA     (COALESCE("arm" IN (?), FALSE) OR "arm" IS NULL)

-- PostgreSQL
mean, NA aware  (CASE WHEN COUNT(*) > COUNT("bp") THEN NULL ELSE AVG(CAST("bp" AS DOUBLE PRECISION)) END)
ratio           (CAST("bp" AS DOUBLE PRECISION) / NULLIF("baseline", 0))
contains        (POSITION(? IN "name") > 0)
in, with NA     (COALESCE("arm" IN (?), FALSE) OR "arm" IS NULL)

-- MySQL
mean, NA aware  (CASE WHEN COUNT(*) > COUNT(`bp`) THEN NULL ELSE AVG(CAST(`bp` AS DOUBLE)) END)
ratio           (CAST(`bp` AS DOUBLE) / NULLIF(`baseline`, 0))
contains        (LOCATE(?, `name`) > 0)
in, with NA     (COALESCE(`arm` IN (?), FALSE) OR `arm` IS NULL)

(CASE WHEN COUNT(*) OVER (PARTITION BY "clinic" ORDER BY ("visit" IS NULL), "visit" ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) > COUNT("bp") OVER (PARTITION BY "clinic" ORDER BY ("visit" IS NULL), "visit" ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) THEN NULL ELSE SUM("bp") OVER (PARTITION BY "clinic" ORDER BY ("visit" IS NULL), "visit" ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) END)
```
