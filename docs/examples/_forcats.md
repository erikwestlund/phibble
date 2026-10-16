```php
$penguins = Frame::fromCsv('penguins.csv')->asFactor('species');

$show = fn (string $label, Frame $f) => print(\str_pad($label, 26).json_encode($f->levels('species'))."\n");

$show('sorted (default)', $penguins);
$show('fctRelevel Gentoo', $penguins->fctRelevel('species', ['Gentoo']));
$show('fctInfreq', $penguins->fctInfreq('species'));
$show('fctReorder by body_mass', $penguins->fctReorder('species', 'body_mass', fn ($xs) => array_sum(array_filter($xs)) / count(array_filter($xs))));
$show('fctRev', $penguins->fctRev('species'));
$show('fctRecode', $penguins->fctRecode('species', ['Chinstrap penguin' => 'Chinstrap']));
$show('fctCollapse', $penguins->fctCollapse('species', ['brush-tailed' => ['Adelie', 'Gentoo']]));
```

```{.text .output}
sorted (default)          ["Adelie","Chinstrap","Gentoo"]
fctRelevel Gentoo         ["Gentoo","Adelie","Chinstrap"]
fctInfreq                 ["Adelie","Gentoo","Chinstrap"]
fctReorder by body_mass   ["Adelie","Chinstrap","Gentoo"]
fctRev                    ["Gentoo","Chinstrap","Adelie"]
fctRecode                 ["Adelie","Chinstrap penguin","Gentoo"]
fctCollapse               ["brush-tailed","Chinstrap"]
```
