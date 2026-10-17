```php
$frame = Penguin::query()
    ->where('year', 2009)
    ->get()
    ->toDataFrame();

echo $frame->select(['species', 'island', 'body_mass'])->head(3);
```

```{.text .output}
# A tibble: 3 × 3
  species island body_mass
  <chr>   <chr>      <int>
1 Adelie  Biscoe      3725
2 Adelie  Biscoe      4725
3 Adelie  Biscoe      3075
```
