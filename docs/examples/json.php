<?php

$frame = tibble(id: [1, 2], score: [2.5, null]);

echo $frame->toJson(), "\n";
echo $frame->toJson('columns'), "\n";

echo Frame::fromJson('{"id": [1, 2], "ok": [true, null]}');
