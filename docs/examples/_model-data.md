```php
$frame = tibble(
    mass: [3750.0, 3800.0, null],
    male: [true, false, true],
    species: ['Adelie', 'Gentoo', 'Adelie'],
)->asFactor('species', ['Adelie', 'Chinstrap', 'Gentoo']);

echo json_encode($frame->toModelData(), JSON_PRETTY_PRINT), "\n";
```

```{.text .output}
{
    "mass": [
        3750,
        3800,
        null
    ],
    "male": [
        true,
        false,
        true
    ],
    "species": {
        "levels": [
            "Adelie",
            "Chinstrap",
            "Gentoo"
        ],
        "codes": [
            0,
            2,
            0
        ],
        "ordered": false
    }
}
```
