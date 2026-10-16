<?php

$heavy = col('body_mass')->gt(5000)->and(col('species')->isIn(['Gentoo', 'Chinstrap']));

echo json_encode($heavy->toArray(), JSON_PRETTY_PRINT), "\n";
echo json_encode($heavy->columns()), "\n";
