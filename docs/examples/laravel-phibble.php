<?php

require '../examples/support/laravel.php';
// ---
$summary = Penguin::query()
    ->measured()
    ->where('year', '>=', 2008)
    ->phibble()
    ->groupBy(['species', 'sex'])
    ->summarise([
        'n' => n(),
        'mass' => mean(col('body_mass')),
    ]);

echo $summary->collect();
