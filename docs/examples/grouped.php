<?php

$penguins = Frame::fromCsv('penguins.csv')->dropNa(['body_mass']);

$centered = $penguins
    ->groupBy(['species'])
    ->mutate(['vs_species' => col('body_mass')->sub(mean(col('body_mass')))])
    ->select(['species', 'body_mass', 'vs_species'])
    ->head(4);

echo $centered;
