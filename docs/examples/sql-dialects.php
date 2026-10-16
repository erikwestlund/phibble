<?php

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
        echo \str_pad($label, 16), $compiler->compile($expr)->sql, "\n";
    }
    echo "\n";
}

$window = new Compiler(new PostgresDialect(), groups: ['clinic'], order: [[col('visit'), false]]);
echo $window->compile(cumsum(col('bp')))->sql, "\n";
