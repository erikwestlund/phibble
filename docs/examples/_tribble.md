```php
$sites = tribble(
    ['site', 'lat', 'opened'],
    ['north', 44.9, 2019],
    ['south', 41.2, 2021],
);

echo $sites;
```

```{.text .output}
# A tibble: 2 × 3
  site    lat opened
  <chr> <dbl>  <int>
1 north  44.9   2019
2 south  41.2   2021
```
