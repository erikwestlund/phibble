```php
$frame = tribble(
    ['name', 'note', 'score', 'seen'],
    ['Ana', 'likes "quotes"', 1e5, true],
    ['Ben', 'a, b', null, false],
);

echo $frame->toCsv();
```

```{.text .output}
name,note,score,seen
Ana,"likes ""quotes""",100000,TRUE
Ben,"a, b",NA,FALSE
```
