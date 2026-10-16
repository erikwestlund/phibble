```php
$penguins = Frame::fromCsv('penguins.csv')->asFactor('species');

echo json_encode($penguins->levels('species')), "\n";
echo json_encode(array_slice($penguins->storage('species')->codes(), 150, 5)), "\n";

$sizes = tibble(size: ['small', 'large', 'medium', 'small'])
    ->asOrdered('size', ['small', 'medium', 'large']);

echo $sizes->arrange(['size']), "\n";
var_dump($sizes->isOrdered('size'));
```

```{.text .output}
["Adelie","Chinstrap","Gentoo"]
[0,0,2,2,2]
# A tibble: 4 × 1
  size  
  <ord> 
1 small 
2 small 
3 medium
4 large 
bool(true)
```
