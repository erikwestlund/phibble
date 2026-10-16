<?php

$scores = tibble(
    student: ['ana', 'ben', 'cy'],
    score: [81.5, 74.0, null],
    passed: fn (Frame $t) => array_map(fn ($s) => $s === null ? null : $s >= 75, $t['score']),
    term: 'fall',
);

echo $scores;
