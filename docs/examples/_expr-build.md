```php
$heavy = col('body_mass')->gt(5000)->and(col('species')->isIn(['Gentoo', 'Chinstrap']));

echo json_encode($heavy->toArray(), JSON_PRETTY_PRINT), "\n";
echo json_encode($heavy->columns()), "\n";
```

```{.text .output}
{
    "type": "call",
    "fn": "&",
    "args": [
        {
            "type": "call",
            "fn": ">",
            "args": [
                {
                    "type": "col",
                    "name": "body_mass"
                },
                {
                    "type": "lit",
                    "value": 5000
                }
            ]
        },
        {
            "type": "call",
            "fn": "in",
            "args": [
                {
                    "type": "col",
                    "name": "species"
                },
                {
                    "type": "lit",
                    "value": [
                        "Gentoo",
                        "Chinstrap"
                    ]
                }
            ]
        }
    ]
}
["body_mass","species"]
```
