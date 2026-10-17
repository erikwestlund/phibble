<?php

$penguins = DataFrame::fromCsv('penguins.csv')->select(['species', 'island', 'body_mass']);

$nested = $penguins->nest(['island', 'body_mass'], into: 'data');
echo $nested, "\n";

$sizes = $nested->mutate(['n' => fn ($r) => $r->data->nrow()]);
echo $sizes->select(['species', 'n']), "\n";

echo $nested->unnest('data')->head(3);
