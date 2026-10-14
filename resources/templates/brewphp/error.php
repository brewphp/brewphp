<?php
/**
 * Generic production error page. Projects override a specific status by
 * creating a template named after it, such as `404.php`.
 *
 * @var int $status
 * @var string $title
 * @var string $message
 */
$heading = $title;
$title = $status . ' · ' . $heading;
include __DIR__ . '/_head.php';
?>
<body>
<style>
.error { min-height: 100vh; display: grid; place-items: center; text-align: center; padding: 24px; }
.code { font-size: 96px; font-weight: 700; line-height: 1; color: var(--brew-accent); margin-bottom: 16px; }
</style>
<main class="error">
    <div>
        <div class="code"><?= e($status) ?></div>
        <h1><?= e($heading) ?></h1>
        <?php if ($message !== '') : ?>
            <p class="muted"><?= e($message) ?></p>
        <?php endif ?>
        <p><a href="/">Back to the home page</a></p>
    </div>
</main>
</body>
</html>
