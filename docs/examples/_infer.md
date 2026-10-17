```php
$frame = DataFrame::fromColumns([
    'logical' => [true, false, null],
    'whole' => [1, 2, null],
    'mixed' => [1, 2.5, null],
    'with_bool' => [true, 2, 3],
    'text' => ['a', 1, null],
    'when' => [new DateTimeImmutable('2024-03-01 09:30'), null, null],
    'nested' => [[1, 2], [3], null],
    'empty' => [null, null, null],
]);

echo $frame->glimpse();
```

```{.text .output}
Rows: 3
Columns: 8
$ logical   <lgl> TRUE, FALSE, NA
$ whole     <int> 1, 2, NA
$ mixed     <dbl> 1.0, 2.5, NA
$ with_bool <int> 1, 2, 3
$ text      <chr> "a", "1", NA
$ when      <dttm> 2024-03-01 09:30:00, NA, NA
$ nested    <list> <1, 2>, 3, <NULL>
$ empty     <lgl> NA, NA, NA
```
