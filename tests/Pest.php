<?php

declare(strict_types=1);

use Phibble\Frame;

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
