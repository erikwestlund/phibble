<?php

$penguins = Frame::fromCsv('penguins.csv');

echo $penguins
    ->groupBy(['island'])
    ->summarise([
        'n' => n(),
        'mass' => mean(col('body_mass')),
        'mass_rm' => mean(col('body_mass'), na_rm: true),
        'q90' => quantile(col('body_mass'), 0.9, na_rm: true),
        'species' => n_distinct(col('species')),
        'any_heavy' => any(col('body_mass')->gt(6000), na_rm: true),
    ]);
