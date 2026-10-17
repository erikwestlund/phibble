<?php

$penguins = DataFrame::fromCsv('penguins.csv');

$summary = $penguins
    ->filter(col('body_mass')->isNa()->not())
    ->groupBy(['species', 'sex'])
    ->summarise([
        'n' => n(),
        'mass' => mean(col('body_mass')),
        'flipper' => median(col('flipper_len')),
    ]);

echo $summary;
