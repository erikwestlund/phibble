```php
// Each order gets the latest price set on or before its date.
$orders = tribble(
    ['order', 'item', 'day'],
    [1, 'tea', 3],
    [2, 'tea', 9],
    [3, 'coffee', 4],
);
$prices = tribble(
    ['item', 'from_day', 'price'],
    ['tea', 1, 2.5],
    ['tea', 5, 2.75],
    ['coffee', 1, 4.0],
    ['coffee', 6, 4.5],
);

echo $orders->join($prices, ['item', ['closest', 'day', '>=', 'from_day']], 'left');
```

```{.text .output}
# A tibble: 3 × 5
  order item     day from_day price
  <int> <chr>  <int>    <int> <dbl>
1     1 tea        3        1  2.5 
2     2 tea        9        5  2.75
3     3 coffee     4        1  4
```
