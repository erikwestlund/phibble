```php
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
```

```{.text .output}
Warning: NAs introduced by coercion to int in column 'x' (1 value).
Warning: NAs introduced by coercion to bool in column 'flag' (2 values).
# A tibble: 4 × 3
      x flag  n                
  <int> <lgl> <chr>            
1     2 TRUE  1                
2    -2 TRUE  0.3              
3     3 NA    1e+05            
4    NA NA    0.333333333333333
```
