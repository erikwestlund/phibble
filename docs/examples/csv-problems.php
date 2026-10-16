<?php

set_error_handler(function (int $level, string $message): bool {
    echo "Warning: {$message}\n";

    return true;
});

$frame = Frame::fromCsvString("id,score\n1,2.5\n2,oops\n3\n", types: ['score' => 'double']);

echo $frame;
