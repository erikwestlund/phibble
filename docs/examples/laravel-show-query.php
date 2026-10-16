<?php

require '../examples/support/laravel.php';
// ---
$heaviest = Penguin::query()
    ->measured()
    ->phibble()
    ->groupBy(['island'])
    ->filter(col('body_mass')->eq(max(col('body_mass'))))
    ->select(['island', 'species', 'body_mass']);

echo $heaviest->showQuery(), "\n\n";
echo $heaviest->arrange(['island'])->collect();
