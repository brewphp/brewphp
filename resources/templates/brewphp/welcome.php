<?php
/**
 * Shown at "/" while DEBUG is on and the project has no routes yet.
 *
 * @var string $version
 * @var string $phpVersion
 */
$title = 'BrewPHP is brewing';
include __DIR__ . '/_head.php';
?>
<body>
<style>
.hero { padding: 96px 0 48px; }
.hero h1 { font-size: 48px; letter-spacing: -.02em; }
.cup { width: 64px; height: 64px; margin-bottom: 24px; color: var(--brew-primary); }
.lead { font-size: 20px; color: var(--muted); max-width: 640px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
.grid .card { margin: 0; text-decoration: none; color: inherit; transition: border-color .15s; }
.grid .card:hover { border-color: var(--brew-accent); }
.grid h2 { margin-bottom: 8px; color: var(--brew-primary); }
.grid p { margin: 0; color: var(--muted); font-size: 15px; }
pre { background: var(--code-bg); border-radius: 8px; padding: 16px; overflow-x: auto; margin: 0; }
footer { margin-top: 48px; font-size: 14px; }
</style>
<main class="wrap">
    <section class="hero">
        <svg class="cup" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true">
            <path d="M10 26h36v14a16 16 0 0 1-16 16h-4a16 16 0 0 1-16-16z"/>
            <path d="M46 30h4a7 7 0 0 1 0 14h-5"/>
            <path d="M22 8c-3 4 3 6 0 10M32 8c-3 4 3 6 0 10"/>
        </svg>
        <span class="eyebrow">BrewPHP <?= e($version) ?></span>
        <h1>Your project is brewing.</h1>
        <p class="lead">You are seeing this page because <code>DEBUG</code> is on and your URLconf has no routes yet. Add your first route to make it yours.</p>
    </section>

    <div class="card">
        <h2>Add a route in <code>config/urls.php</code></h2>
        <pre><code>return [
    path('', fn (Request $request) => 'Hello, world!', name: 'home'),
];</code></pre>
    </div>

    <div class="grid">
        <a class="card" href="https://docs.brewphp.dev/getting-started/installation/">
            <h2>Get started</h2>
            <p>Install BrewPHP and learn how a project is laid out.</p>
        </a>
        <a class="card" href="https://docs.brewphp.dev/topics/routing/">
            <h2>Routing &amp; views</h2>
            <p>Map URLs to views, with converters, names and includes.</p>
        </a>
        <a class="card" href="https://docs.brewphp.dev/cli/overview/">
            <h2>manage.php</h2>
            <p>Everything you can do from the command line.</p>
        </a>
    </div>

    <footer class="muted">PHP <?= e($phpVersion) ?> · <a href="https://github.com/brewphp/brewphp">GitHub</a> · <a href="https://docs.brewphp.dev">Documentation</a></footer>
</main>
</body>
</html>
