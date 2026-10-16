<?php

$frame = tibble(x: [1, 2, 3], y: ['a', 'b', 'c']);

var_dump($frame->x === $frame['x']);
echo json_encode($frame->column('y')), "\n";

$row = $frame->row(1);
echo $row->x, ' ', $row['y'], "\n";

foreach ($frame as $i => $row) {
    echo "{$i}: {$row->y}\n";
}

try {
    $frame->z;
} catch (Phibble\Exception\ColumnNotFound $e) {
    echo $e->getMessage(), "\n";
}
