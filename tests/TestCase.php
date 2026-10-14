<?php

declare(strict_types=1);

namespace BrewPHP\Tests;

use BrewPHP\Core\Application;
use BrewPHP\Core\Env;
use BrewPHP\Core\Settings;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected const PROJECT = __DIR__ . '/Fixtures/project';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        Application::setInstance(null);
        Env::reset();
        foreach ($this->tempDirs as $dir) {
            self::removeDirectory($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * Boot the fixture project, with some settings replaced.
     *
     * @param array<string, mixed> $overrides
     */
    protected function makeApp(array $overrides = [], ?string $basePath = null): Application
    {
        $basePath ??= self::PROJECT;
        /** @var array<string, mixed> $settings */
        $settings = require $basePath . '/config/settings.php';
        $app = new Application($basePath, new Settings(array_replace($settings, $overrides)));
        $app->boot();

        return $app;
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $server
     * @param array<string, mixed> $settings
     */
    protected function request(string $method, string $uri, array $parameters = [], array $server = [], array $settings = []): Response
    {
        return $this->makeApp($settings)->handle(Request::create($method, $uri, $parameters, server: $server));
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'brewphp-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * A writable copy of the fixture project. The demo app's views.php and
     * models.php are left out: their functions and classes are already
     * declared by the original fixture and PHP cannot declare them twice in
     * one process.
     */
    protected function copyProject(): string
    {
        $target = $this->tempDir();
        self::copyDirectory(self::PROJECT, $target);

        return $target;
    }

    private static function copyDirectory(string $source, string $target): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $path = $target . DIRECTORY_SEPARATOR . substr($item->getPathname(), strlen($source) + 1);
            if ($item->isDir()) {
                @mkdir($path, 0777, true);
            } elseif (!in_array($item->getFilename(), ['views.php', 'models.php'], true)) {
                copy($item->getPathname(), $path);
            }
        }
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
