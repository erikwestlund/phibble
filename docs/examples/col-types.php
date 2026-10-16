<?php

$penguins = Frame::fromCsv('penguins.csv', types: 'ffddii_i');
echo $penguins->glimpse(), "\n";

$some = Frame::fromCsv('penguins.csv', types: ['species' => 'factor', '.default' => '_'], select: ['species']);
echo $some->glimpse();
