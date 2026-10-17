<?php

$rows = DataFrame::fromRows([
    ['id' => 1, 'dose' => 2.5, 'arm' => 'a'],
    ['id' => 2, 'arm' => 'b'],
]);
$cols = DataFrame::fromColumns(['id' => [1, 2], 'arm' => ['a', 'b']], types: ['arm' => 'factor']);

echo $rows, "\n\n", $cols;
