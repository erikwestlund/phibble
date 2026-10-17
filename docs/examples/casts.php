<?php

set_error_handler(function (int $level, string $message): bool {
    echo "Warning: {$message}\n";

    return true;
});

$frame = DataFrame::fromColumns([
    'x' => [2.7, -2.7, '3.9', 'abc'],
    'flag' => ['TRUE', 'T', 'yes', '0'],
    'n' => [1.0, 0.1 + 0.2, 1e5, 1 / 3],
]);

$cast = $frame->cast(['x' => 'int', 'flag' => 'bool', 'n' => 'string']);

echo $cast;
