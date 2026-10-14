<?php
/**
 * Debug error page, shown only when DEBUG is on.
 *
 * @var int $status
 * @var list<Throwable> $exceptions
 * @var list<array{file: string, line: int, function: string, vendor: bool, code: array<int, string>}> $frames
 * @var array{name: string, line: int, file: string|null, code: array<int, string>}|null $template
 * @var BrewPHP\Http\Request $request
 * @var array<string, mixed> $settings
 * @var array<string, mixed> $server
 * @var string $basePath
 * @var string $version
 */
$exception = $exceptions[0];
$title = (new ReflectionClass($exception))->getShortName() . ': ' . $exception->getMessage();
$short = static fn (string $file): string => str_starts_with($file, $basePath) ? ltrim(substr($file, strlen($basePath)), '/\\') : $file;
$dump = static fn (mixed $value): string => is_scalar($value) || $value === null
    ? var_export($value, true)
    : (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
include __DIR__ . '/_head.php';
?>
<body>
<style>
.header { background: var(--brew-primary); color: #FFF8EF; }
.header .wrap { padding-top: 32px; padding-bottom: 32px; }
.header .eyebrow { color: var(--brew-accent); }
.header h1 { word-break: break-word; }
.header .location { font-family: var(--mono); font-size: 14px; opacity: .85; }
@media (prefers-color-scheme: dark) { .header { background: #3A2A1F; } }
.frame { border: 1px solid var(--border); border-radius: 8px; margin-bottom: 8px; background: var(--surface); }
.frame summary { cursor: pointer; padding: 8px 16px; font-family: var(--mono); font-size: 13px; list-style: none; display: flex; gap: 16px; justify-content: space-between; flex-wrap: wrap; }
.frame summary::-webkit-details-marker { display: none; }
.frame summary:focus-visible { outline: 2px solid var(--brew-accent); outline-offset: 2px; }
.frame.vendor summary { color: var(--muted); }
.frame .fn { font-weight: 600; }
.source { margin: 0; padding: 8px 0; background: var(--code-bg); border-top: 1px solid var(--border); overflow-x: auto; }
.source div { padding: 0 16px; white-space: pre; }
.source .hl { background: var(--highlight); }
.source .ln { display: inline-block; width: 48px; color: var(--muted); user-select: none; }
.chain { font-family: var(--mono); font-size: 14px; margin: 0 0 8px; }
.note { font-size: 14px; }
.template-error .source { border: 1px solid var(--border); border-radius: 8px; }
</style>
<header class="header">
    <div class="wrap">
        <span class="eyebrow"><?= e($status) ?> · <?= e($exception::class) ?></span>
        <h1><?= e($exception->getMessage() !== '' ? $exception->getMessage() : $exception::class) ?></h1>
        <div class="location"><?= e($short($exception->getFile())) ?>:<?= e($exception->getLine()) ?> · <?= e($request->method()) ?> <?= e($request->fullPath()) ?></div>
    </div>
</header>
<main class="wrap">
    <?php if ($template !== null) : ?>
        <section class="card template-error">
            <h2>Template error</h2>
            <p class="chain">In template <strong><?= e($template['name']) ?></strong>, line <strong><?= e($template['line']) ?></strong><?= $template['file'] !== null ? ' · ' . e($short($template['file'])) : '' ?></p>
            <?php if ($template['code'] !== []) : ?>
                <pre class="source"><?php foreach ($template['code'] as $number => $code) : ?><div<?= $number === $template['line'] ? ' class="hl"' : '' ?>><span class="ln"><?= e($number) ?></span><?= e($code) ?></div><?php endforeach ?></pre>
            <?php endif ?>
        </section>
    <?php endif ?>

    <?php if (count($exceptions) > 1) : ?>
        <section class="card">
            <h2>Exception chain</h2>
            <?php foreach ($exceptions as $i => $item) : ?>
                <p class="chain"><?= $i === 0 ? '' : '↳ caused by ' ?><strong><?= e($item::class) ?></strong>: <?= e($item->getMessage()) ?></p>
            <?php endforeach ?>
        </section>
    <?php endif ?>

    <section>
        <h2>Stack trace</h2>
        <?php foreach ($frames as $i => $frame) : ?>
            <details class="frame<?= $frame['vendor'] ? ' vendor' : '' ?>"<?= ($i === 0 || !$frame['vendor']) && $i < 6 ? ' open' : '' ?>>
                <summary>
                    <span class="fn"><?= e($frame['function']) ?></span>
                    <span><?= e($short($frame['file'])) ?>:<?= e($frame['line']) ?></span>
                </summary>
                <?php if ($frame['code'] !== []) : ?>
                    <pre class="source"><?php foreach ($frame['code'] as $number => $code) : ?><div<?= $number === $frame['line'] ? ' class="hl"' : '' ?>><span class="ln"><?= e($number) ?></span><?= e($code) ?></div><?php endforeach ?></pre>
                <?php endif ?>
            </details>
        <?php endforeach ?>
    </section>

    <section class="card">
        <h2>Request</h2>
        <table>
            <tr><th>Method</th><td><?= e($request->method()) ?></td></tr>
            <tr><th>URL</th><td><?= e($request->fullUrl()) ?></td></tr>
            <?php foreach (['Query (GET)' => $request->query(), 'Form (POST)' => BrewPHP\Core\ErrorHandler::mask((array) $request->post()), 'Cookies' => BrewPHP\Core\ErrorHandler::mask($request->cookies()), 'Headers' => BrewPHP\Core\ErrorHandler::mask($request->headers())] as $label => $values) : ?>
                <tr><th><?= e($label) ?></th><td><?= $values === [] ? '<span class="muted">none</span>' : e($dump($values)) ?></td></tr>
            <?php endforeach ?>
        </table>
    </section>

    <section class="card">
        <h2>Settings</h2>
        <table>
            <?php foreach ($settings as $key => $value) : ?>
                <tr><th><?= e($key) ?></th><td><?= e($dump($value)) ?></td></tr>
            <?php endforeach ?>
        </table>
    </section>

    <section class="card">
        <h2>Server</h2>
        <table>
            <?php foreach ($server as $key => $value) : ?>
                <tr><th><?= e($key) ?></th><td><?= e($dump($value)) ?></td></tr>
            <?php endforeach ?>
        </table>
    </section>

    <p class="muted note">You are seeing this page because <code>DEBUG = true</code>. Turn it off in production to show a plain error page instead. · BrewPHP <?= e($version) ?> · PHP <?= e(PHP_VERSION) ?></p>
</main>
</body>
</html>
