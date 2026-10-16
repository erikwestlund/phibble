<?php

$penguins = Frame::fromCsv('penguins.csv');

$heavy = $penguins
    ->select(['species', 'island', 'body_mass', 'year'])
    ->filter(fn ($r) => $r->body_mass !== null && $r->body_mass > 5500)
    ->mutate(['kg' => fn ($r) => $r->body_mass / 1000])
    ->arrange([['body_mass', 'desc']])
    ->head(5);

echo $heavy;
