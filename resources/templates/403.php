<?php
/*
 * Production 403 page. Override it by creating templates/403.php in your project.
 */
$status = 403;
$title = 'Permission denied';
include __DIR__ . '/brewphp/error.php';
