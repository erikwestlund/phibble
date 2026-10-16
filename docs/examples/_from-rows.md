```php
$rows = Frame::fromRows([
    ['id' => 1, 'dose' => 2.5, 'arm' => 'a'],
    ['id' => 2, 'arm' => 'b'],
]);
$cols = Frame::fromColumns(['id' => [1, 2], 'arm' => ['a', 'b']], types: ['arm' => 'factor']);

echo $rows, "\n\n", $cols;
```

```{.text .output}
# A tibble: 2 × 3
     id  dose arm  
  <int> <dbl> <chr>
1     1   2.5 a    
2     2  NA   b    

# A tibble: 2 × 2
     id arm  
  <int> <fct>
1     1 a    
2     2 b
```
