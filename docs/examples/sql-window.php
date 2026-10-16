<?php

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
