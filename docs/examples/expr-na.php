<?php

$frame = tibble(
    a: [true, false, null, null],
    b: [null, null, true, false],
    x: [2.5, 0.5, -1.5, 2.675],
    y: [1, null, 3, 4],
);

echo $frame->mutate([
    'and' => col('a')->and(col('b')),
    'or' => col('a')->or(col('b')),
    'sum' => col('y')->add(1),
    'round' => Phibble\Expr\round(col('x')),
    'round2' => Phibble\Expr\round(col('x'), digits: 2),
    'mod' => lit(-7)->mod(3),
    'cumsum' => cumsum(col('y')),
]);
