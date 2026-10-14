<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Core;

use BrewPHP\Core\Settings;
use BrewPHP\Tests\TestCase;

final class SettingsTest extends TestCase
{
    public function testDefaultsAreMergedWithValues(): void
    {
        $settings = new Settings(['DEBUG' => true, 'CUSTOM' => 'x']);

        self::assertTrue($settings->get('DEBUG'));
        self::assertSame('x', $settings->get('CUSTOM'));
        self::assertSame('UTC', $settings->get('TIME_ZONE'));
        self::assertTrue($settings->get('APPEND_SLASH'));
    }

    public function testDotAccessAndSet(): void
    {
        $settings = new Settings(['DATABASES' => ['default' => ['ENGINE' => 'sqlite']]]);

        self::assertSame('sqlite', $settings->get('DATABASES.default.ENGINE'));
        self::assertSame('none', $settings->get('DATABASES.other.ENGINE', 'none'));
        self::assertTrue($settings->has('DATABASES.default'));
        self::assertFalse($settings->has('NOPE'));

        $settings->set('DATABASES.default.NAME', 'db.sqlite3');
        self::assertSame('db.sqlite3', $settings->get('DATABASES.default.NAME'));
    }

    public function testTypedAccessors(): void
    {
        $settings = new Settings(['NUM' => 5, 'LIST' => 'single', 'OBJ' => new \stdClass()]);

        self::assertSame('5', $settings->string('NUM'));
        self::assertSame('fallback', $settings->string('OBJ', 'fallback'));
        self::assertSame(['single'], $settings->array('LIST'));
        self::assertSame([], $settings->array('MISSING'));
        self::assertFalse($settings->bool('MISSING'));
    }

    public function testFromFile(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/ok.php', "<?php return ['DEBUG' => true];");
        file_put_contents($dir . '/bad.php', "<?php return 'nope';");

        self::assertTrue(Settings::fromFile($dir . '/ok.php')->get('DEBUG'));
        self::assertFalse(Settings::fromFile($dir . '/missing.php')->get('DEBUG'));

        $this->expectException(\UnexpectedValueException::class);
        Settings::fromFile($dir . '/bad.php');
    }
}
