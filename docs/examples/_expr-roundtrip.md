```php
$expr = lag(col('score'), n: 2, default: 0, order_by: col('week'));
$json = json_encode($expr->toArray());

echo $json, "\n";

$back = Phibble\Expr\Expr::fromArray(json_decode($json, true));
var_dump($back == $expr);
```

```{.text .output}
{"type":"call","fn":"lag","args":[{"type":"col","name":"score"}],"named":{"n":{"type":"lit","value":2},"default":{"type":"lit","value":0},"order_by":{"type":"col","name":"week"}}}
bool(true)
```
