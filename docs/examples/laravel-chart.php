<?php

require '../examples/support/laravel.php';
// ---
use Phit\Plot;

$penguins = Penguin::query()
    ->measured()
    ->phibble()
    ->select(['species', 'flipper_len', 'body_mass'])
    ->collect();

$plot = Plot::of($penguins)
    ->aes(x: 'flipper_len', y: 'body_mass', color: 'species')
    ->point(alpha: 0.5)
    ->smooth(method: 'lm')
    ->labs(title: 'Body mass by flipper length', x: 'Flipper length (mm)', y: 'Body mass (g)');

// ===
echo "<!--phit-->\n", Phit\Html::embed($plot);
