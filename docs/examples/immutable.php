<?php

$frame = tibble(x: [1, 2, 3]);
$doubled = $frame->mutate(['x' => col('x')->mul(2)]);

echo json_encode($frame->x), ' ', json_encode($doubled->x), "\n";
