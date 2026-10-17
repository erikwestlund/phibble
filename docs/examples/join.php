<?php

$penguins = DataFrame::fromCsv('penguins.csv');
$islands = tribble(
    ['island', 'area_km2'],
    ['Biscoe', 6.1],
    ['Dream', 0.9],
);

$joined = $penguins
    ->distinct(['species', 'island'])
    ->join($islands, ['island'], 'left');

echo $joined;
