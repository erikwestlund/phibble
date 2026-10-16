<?php

$frame = Frame::fromColumns([
    'logical' => [true, false, null],
    'whole' => [1, 2, null],
    'mixed' => [1, 2.5, null],
    'with_bool' => [true, 2, 3],
    'text' => ['a', 1, null],
    'when' => [new DateTimeImmutable('2024-03-01 09:30'), null, null],
    'nested' => [[1, 2], [3], null],
    'empty' => [null, null, null],
]);

echo $frame->glimpse();
