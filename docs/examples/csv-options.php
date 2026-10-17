<?php

$text = <<<'CSV'
    # exported 2024-06-01
    id;zip;score;visited
    1;02134;3,5;2024-05-01
    2;10001;-;2024-05-03
    3;94110;4,25;2024-05-04
    CSV;

$frame = DataFrame::fromCsvString(
    $text,
    types: ['zip' => 'c'],
    delimiter: ';',
    na: ['-'],
    comment: '#',
    decimalMark: ',',
);

echo $frame;
