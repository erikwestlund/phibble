<?php

$frame = tribble(
    ['name', 'note', 'score', 'seen'],
    ['Ana', 'likes "quotes"', 1e5, true],
    ['Ben', 'a, b', null, false],
);

echo $frame->toCsv();
