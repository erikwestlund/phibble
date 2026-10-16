<?php

require '../examples/support/laravel.php';
// ---
use Phiz\Plot;

// The grouping and the means run in the database.
$byYear = Penguin::query()
    ->measured()
    ->phibble()
    ->groupBy(['species', 'year'])
    ->summarise(['mass' => mean(col('body_mass'))])
    ->collect();

echo $byYear->head(3), "\n";

$plot = Plot::of($byYear)
    ->aes(x: 'year:O', y: 'mass', color: 'species')
    ->line()
    ->point()
    ->labs(title: 'Mean body mass by year', x: 'Year', y: 'Mean body mass (g)');

// ===
echo "<!--phiz-->\n", Phiz\Html::embed($plot);
