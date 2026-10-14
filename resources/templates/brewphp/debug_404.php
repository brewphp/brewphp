<?php
/**
 * Debug "page not found" page: lists the URL patterns that were tried.
 *
 * @var string $path
 * @var list<BrewPHP\Routing\Route> $routes
 * @var string $urlconf
 * @var BrewPHP\Http\Request $request
 */
$title = 'Page not found: ' . $path;
include __DIR__ . '/_head.php';
?>
<body>
<style>
ol { font-family: var(--mono); font-size: 14px; padding-left: 24px; margin: 0; }
ol li { padding: 4px 0; }
.name { color: var(--muted); }
.methods { color: var(--brew-primary); font-weight: 600; }
</style>
<main class="wrap">
    <span class="eyebrow">404 · Page not found</span>
    <h1>No route matches <code><?= e($path) ?></code></h1>
    <p class="muted"><?= e($request->method()) ?> <?= e($request->fullUrl()) ?></p>

    <section class="card">
        <?php if ($routes === []) : ?>
            <h2>Your URLconf has no routes</h2>
            <p>Add routes with <code>path()</code> in <code><?= e($urlconf) ?></code>.</p>
        <?php else : ?>
            <h2>BrewPHP tried these URL patterns, in this order (from <code><?= e($urlconf) ?></code>):</h2>
            <ol>
                <?php foreach ($routes as $route) : ?>
                    <li>
                        <?php if ($route->methods !== []) : ?><span class="methods"><?= e(implode('|', $route->methods)) ?></span> <?php endif ?>
                        /<?= e($route->pattern()) ?>
                        <?php if ($route->name !== null) : ?><span class="name">[name='<?= e($route->name) ?>']</span><?php endif ?>
                    </li>
                <?php endforeach ?>
            </ol>
            <p class="muted">The current path, <code><?= e($path) ?></code>, did not match any of these.</p>
        <?php endif ?>
    </section>

    <p class="muted">You are seeing this page because <code>DEBUG = true</code>. With DEBUG off, a plain 404 page is shown.</p>
</main>
</body>
</html>
