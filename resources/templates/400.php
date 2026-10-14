<?php
/*
 * Production 400 page. Override it by creating templates/400.php in your project.
 */
$status = 400;
$title = 'Bad request';
include __DIR__ . '/brewphp/error.php';
