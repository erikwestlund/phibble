<?php

$penguins = Frame::fromCsv('penguins.csv')
    ->asFactor('species')
    ->filter(col('species')->ne('Chinstrap'));

echo $penguins->groupBy(['species'], drop: false)->summarise(['n' => n()]);
