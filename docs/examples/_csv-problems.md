```php
set_error_handler(function (int $level, string $message): bool {
    echo "Warning: {$message}\n";

    return true;
});

$frame = Frame::fromCsvString("id,score\n1,2.5\n2,oops\n3\n", types: ['score' => 'double']);

echo $frame;
```

```{.text .output}
Warning: One or more parsing issues: row 4: expected 2 columns, got 1; column 'score': 1 value could not be parsed as float.
# A tibble: 3 × 2
     id score
  <int> <dbl>
1     1   2.5
2     2  NA  
3     3  NA
```
