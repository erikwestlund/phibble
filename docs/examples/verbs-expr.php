<?php

$penguins = Frame::fromCsv('penguins.csv');

$heavy = $penguins
    ->select(['species', 'island', 'body_mass', 'year'])
    ->filter(col('body_mass')->gt(5500))
    ->mutate(['kg' => col('body_mass')->div(1000)])
    ->arrange([desc(col('body_mass'))])
    ->head(5);

echo $heavy;
