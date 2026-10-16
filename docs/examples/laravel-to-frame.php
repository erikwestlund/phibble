<?php

require '../examples/support/laravel.php';
// ---
$frame = Penguin::query()
    ->where('year', 2009)
    ->get()
    ->toFrame();

echo $frame->select(['species', 'island', 'body_mass'])->head(3);
