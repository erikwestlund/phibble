```php
use Phiz\Plot;

// The grouping and the means run in the database.
$byYear = Penguin::query()
    ->measured()
    ->phibble()
    ->groupBy(['species', 'year'])
    ->summarise(['mass' => mean(col('body_mass'))])
    ->collect();

echo $byYear->head(3), "\n";

$plot = Plot::of($byYear)
    ->aes(x: 'year:O', y: 'mass', color: 'species')
    ->line()
    ->point()
    ->labs(title: 'Mean body mass by year', x: 'Year', y: 'Mean body mass (g)');
```

```{.text .output}
# A tibble: 3 × 3
# Groups:   species [1]
  species  year  mass
  <chr>   <int> <dbl>
1 Adelie   2007 3696.
2 Adelie   2008 3742 
3 Adelie   2009 3665.
```

```{=html}
<div data-phiz="{&quot;$schema&quot;:&quot;https://vega.github.io/schema/vega-lite/v6.json&quot;,&quot;title&quot;:&quot;Mean body mass by year&quot;,&quot;data&quot;:{&quot;values&quot;:[{&quot;species&quot;:&quot;Adelie&quot;,&quot;year&quot;:2007,&quot;mass&quot;:3696.4285714285716},{&quot;species&quot;:&quot;Adelie&quot;,&quot;year&quot;:2008,&quot;mass&quot;:3742.0},{&quot;species&quot;:&quot;Adelie&quot;,&quot;year&quot;:2009,&quot;mass&quot;:3664.903846153846},{&quot;species&quot;:&quot;Chinstrap&quot;,&quot;year&quot;:2007,&quot;mass&quot;:3694.230769230769},{&quot;species&quot;:&quot;Chinstrap&quot;,&quot;year&quot;:2008,&quot;mass&quot;:3800.0},{&quot;species&quot;:&quot;Chinstrap&quot;,&quot;year&quot;:2009,&quot;mass&quot;:3725.0},{&quot;species&quot;:&quot;Gentoo&quot;,&quot;year&quot;:2007,&quot;mass&quot;:5070.588235294118},{&quot;species&quot;:&quot;Gentoo&quot;,&quot;year&quot;:2008,&quot;mass&quot;:5019.565217391304},{&quot;species&quot;:&quot;Gentoo&quot;,&quot;year&quot;:2009,&quot;mass&quot;:5140.697674418605}]},&quot;width&quot;:300,&quot;layer&quot;:[{&quot;mark&quot;:{&quot;type&quot;:&quot;line&quot;},&quot;encoding&quot;:{&quot;x&quot;:{&quot;field&quot;:&quot;year&quot;,&quot;type&quot;:&quot;ordinal&quot;,&quot;title&quot;:&quot;Year&quot;},&quot;y&quot;:{&quot;field&quot;:&quot;mass&quot;,&quot;type&quot;:&quot;quantitative&quot;,&quot;title&quot;:&quot;Mean body mass (g)&quot;,&quot;scale&quot;:{&quot;zero&quot;:false}},&quot;color&quot;:{&quot;field&quot;:&quot;species&quot;,&quot;type&quot;:&quot;nominal&quot;}}},{&quot;mark&quot;:{&quot;type&quot;:&quot;point&quot;,&quot;filled&quot;:true},&quot;encoding&quot;:{&quot;x&quot;:{&quot;field&quot;:&quot;year&quot;,&quot;type&quot;:&quot;ordinal&quot;,&quot;title&quot;:&quot;Year&quot;},&quot;y&quot;:{&quot;field&quot;:&quot;mass&quot;,&quot;type&quot;:&quot;quantitative&quot;,&quot;title&quot;:&quot;Mean body mass (g)&quot;,&quot;scale&quot;:{&quot;zero&quot;:false}},&quot;color&quot;:{&quot;field&quot;:&quot;species&quot;,&quot;type&quot;:&quot;nominal&quot;}}}],&quot;config&quot;:{&quot;legend&quot;:{&quot;labelLimit&quot;:320}}}"><div data-phiz-view wire:ignore></div></div>
<script src="https://cdn.jsdelivr.net/npm/vega@6.4.0/build/vega.min.js" integrity="sha384-VKdcJr3ZaBIJMbVcopTAI/JEuUkSY6qnwVu9iLuw0DnQ9gQ1JjsfZJhXFQAgNi43" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/vega-lite@6.4.3/build/vega-lite.min.js" integrity="sha384-9/70gNCfOu6G7xXvkdreMfuqAEsoaGJVXV2BN/JLRXkSmcGvnMqtsRx8HZtUWAvI" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/vega-embed@7.3.0/build/vega-embed.min.js" integrity="sha384-Muy1QRxYFeNrA1zShc1KtN4OfipkR/61gt+HWqN57c1Zxu3Oe16TsilsruVVjfKL" crossorigin="anonymous"></script>
<script>/* phiz: renders [data-phiz] elements with vega-embed, and re-renders when their spec changes (Livewire morphs). */
(function () {
  if (window.Phiz) return;
  var views = new WeakMap();
  var dark = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  function parse(json, fallback) {
    if (!json) return fallback;
    try { return JSON.parse(json); } catch (e) { console.error('phiz: invalid JSON', e); return fallback; }
  }

  function target(el) {
    var view = el.querySelector(':scope > [data-phiz-view]');
    if (!view) {
      view = document.createElement('div');
      view.setAttribute('data-phiz-view', '');
      view.setAttribute('wire:ignore', '');
      el.appendChild(view);
    }
    return view;
  }

  function render(el) {
    var embed = Phiz.embed || window.vegaEmbed;
    if (!embed) { pending = true; return; }
    var json = el.getAttribute('data-phiz');
    var optionsJson = el.getAttribute('data-phiz-options') || '';
    var key = json + '\u0000' + optionsJson + '\u0000' + (dark && dark.matches ? 'dark' : 'light');
    var state = views.get(el);
    if (state && state.key === key) return;
    var spec = parse(json, null);
    if (!spec) return;
    var options = parse(optionsJson, {});
    if (options.theme === 'auto') {
      if (dark && dark.matches) options.theme = 'dark'; else delete options.theme;
    }
    if (state && state.view) state.view.finalize();
    // Each render draws into its own element, so a slow, superseded render cannot replace a newer chart.
    var token = {};
    var holder = document.createElement('div');
    target(el).appendChild(holder);
    views.set(el, { key: key, token: token, view: null });
    Promise.resolve(embed(holder, spec, options)).then(function (result) {
      var current = views.get(el);
      if (!current || current.token !== token) { result.view.finalize(); holder.remove(); return; }
      Array.prototype.slice.call(holder.parentNode.children).forEach(function (child) { if (child !== holder) child.remove(); });
      current.view = result.view;
      el.dispatchEvent(new CustomEvent('phiz:rendered', { detail: { view: result.view }, bubbles: true }));
    }).catch(function (error) {
      var current = views.get(el);
      if (!current || current.token !== token) { holder.remove(); return; }
      console.error('phiz:', error);
      holder.textContent = 'Chart error: ' + error.message;
    });
  }

  function scan(root) {
    if (root.nodeType !== 1) return;
    if (root.hasAttribute('data-phiz')) render(root);
    root.querySelectorAll('[data-phiz]').forEach(render);
  }

  function release(root) {
    if (root.nodeType !== 1) return;
    var nodes = root.hasAttribute('data-phiz') ? [root] : [];
    root.querySelectorAll('[data-phiz]').forEach(function (n) { nodes.push(n); });
    nodes.forEach(function (n) {
      if (n.isConnected) return;
      var state = views.get(n);
      if (state && state.view) state.view.finalize();
      views.delete(n);
    });
  }

  var pending = false;
  var Phiz = window.Phiz = {
    embed: null,
    render: render,
    scan: function () { scan(document.documentElement); },
    view: function (el) { var s = views.get(el); return s ? s.view : null; }
  };

  new MutationObserver(function (mutations) {
    mutations.forEach(function (m) {
      if (m.type === 'attributes') { render(m.target); return; }
      m.addedNodes.forEach(scan);
      m.removedNodes.forEach(release);
    });
  }).observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['data-phiz', 'data-phiz-options'] });

  if (dark && dark.addEventListener) dark.addEventListener('change', function () { Phiz.scan(); });

  function start() { pending = false; Phiz.scan(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  window.addEventListener('load', function () { if (pending) start(); });
})();</script>
```
