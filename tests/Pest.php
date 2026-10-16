<?php

declare(strict_types=1);

use Phibble\Frame;

require_once __DIR__.'/Support/ExprCases.php';
require_once __DIR__.'/Support/JoinCases.php';
require_once __DIR__.'/Support/LubridateStringrCases.php';
require_once __DIR__.'/Support/SqlConnections.php';

function penguins(): Frame
{
    return Frame::fromRows([
        ['species' => 'Adelie', 'island' => 'Torgersen', 'mass' => 3750.0, 'year' => 2007],
        ['species' => 'Adelie', 'island' => 'Biscoe', 'mass' => 3800.0, 'year' => 2008],
        ['species' => 'Gentoo', 'island' => 'Biscoe', 'mass' => 5000.0, 'year' => 2007],
        ['species' => 'Chinstrap', 'island' => 'Dream', 'mass' => null, 'year' => 2009],
        ['species' => 'Gentoo', 'island' => 'Biscoe', 'mass' => 5400.0, 'year' => 2008],
    ]);
}

/** Run $fn with warnings collected instead of thrown. */
function quietly(Closure $fn): array
{
    $warnings = [];
    set_error_handler(function (int $level, string $message) use (&$warnings) {
        $warnings[] = $message;

        return true;
    });
    try {
        $result = $fn();
    } finally {
        restore_error_handler();
    }

    return [$result, $warnings];
}
