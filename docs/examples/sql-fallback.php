<?php

$pdo = new PDO('sqlite::memory:');
$t = tbl($pdo, 'SELECT 1 AS id, 2.5 AS x UNION ALL SELECT 2, 3.5');

// Compiles to SQL.
echo get_class($t->mutate(['y' => col('x')->mul(2)])), "\n";

// round() rounds half to even in R, which SQL ROUND does not, so the rows
// are collected and the expression runs in memory.
echo get_class($t->mutate(['y' => Phibble\Expr\round(col('x'))])), "\n";

// So does any closure.
echo get_class($t->filter(fn ($r) => $r->x > 3)), "\n";

echo $t->mutate(['y' => Phibble\Expr\round(col('x'))]);
