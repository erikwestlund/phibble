<?php

$expr = lag(col('score'), n: 2, default: 0, order_by: col('week'));
$json = json_encode($expr->toArray());

echo $json, "\n";

$back = Phibble\Expr\Expr::fromArray(json_decode($json, true));
var_dump($back == $expr);
