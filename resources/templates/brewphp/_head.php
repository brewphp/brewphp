<?php
/**
 * Shared <head> for the framework's own pages (welcome, errors, debug).
 *
 * @var string $title
 */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap">
<style>
:root {
    --brew-primary: #6F4E37;
    --brew-accent: #C8A27A;
    --bg: #FAF7F2;
    --surface: #FFFFFF;
    --text: #2B211B;
    --muted: #6E6259;
    --border: #E8DFD3;
    --code-bg: #F3EDE4;
    --highlight: #FCEFD9;
    --danger: #A33A2B;
    --font: "Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    --mono: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
@media (prefers-color-scheme: dark) {
    :root {
        --brew-primary: #C8A27A;
        --brew-accent: #E2C4A0;
        --bg: #1C1714;
        --surface: #26201C;
        --text: #F1E9DF;
        --muted: #B3A698;
        --border: #3A312B;
        --code-bg: #2E2722;
        --highlight: #4A3826;
        --danger: #F08A78;
    }
}
* { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font: 400 16px/1.6 var(--font);
}
a { color: var(--brew-primary); text-underline-offset: 3px; }
a:hover { color: var(--brew-accent); }
code, pre { font-family: var(--mono); font-size: 14px; }
.wrap { max-width: 1040px; margin: 0 auto; padding: 48px 24px; }
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 24px;
}
.muted { color: var(--muted); }
.eyebrow {
    display: inline-block;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--brew-primary);
    margin-bottom: 8px;
}
h1 { font-size: 32px; line-height: 1.25; font-weight: 700; margin: 0 0 8px; }
h2 { font-size: 20px; font-weight: 600; margin: 0 0 16px; }
table { width: 100%; border-collapse: collapse; font-size: 14px; }
th, td { text-align: left; padding: 8px; border-bottom: 1px solid var(--border); vertical-align: top; }
th { width: 30%; color: var(--muted); font-weight: 500; word-break: break-all; }
td { font-family: var(--mono); word-break: break-all; }
</style>
</head>
