```php
$visits = Frame::fromCsvString(<<<'CSV'
    day,checked_in
    2024-01-02,2024-01-02T09:15:00Z
    2024-01-03,2024-01-03 14:40:00
    CSV);

echo $visits, "\n";
echo $visits->cast(['day' => 'float', 'checked_in' => 'float']);
```

```{.text .output}
# A tibble: 2 × 2
  day        checked_in         
  <date>     <dttm>             
1 2024-01-02 2024-01-02 09:15:00
2 2024-01-03 2024-01-03 14:40:00
# A tibble: 2 × 2
    day checked_in
  <dbl>      <dbl>
1 19724 1704186900
2 19725 1704292800
```
