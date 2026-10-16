<?php

$penguins = Frame::fromCsv('penguins.csv');

$out = $penguins
    ->filter(is_na(col('body_mass'))->not())
    ->groupBy(['species'])
    ->mutate([
        'z' => col('body_mass')->sub(mean(col('body_mass')))->div(sd(col('body_mass'))),
        'rank' => min_rank(desc(col('body_mass'))),
        'size' => case_when(
            col('body_mass')->ge(5000), 'large',
            col('body_mass')->ge(3500), 'medium',
            default: 'small',
        ),
    ])
    ->filter(col('rank')->le(2))
    ->select(['species', 'body_mass', 'z', 'rank', 'size']);

echo $out;
