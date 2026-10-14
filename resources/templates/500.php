<?php
/*
 * Production 500 page. Override it by creating templates/500.php in your project.
 */
$status = 500;
$title = 'Server error';
include __DIR__ . '/brewphp/error.php';
