<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Auth;

use BrewPHP\Auth\Passwords;
use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Tests\TestCase;

final class PasswordsTest extends TestCase
{
    public function testBcryptSha256(): void
    {
        $this->makeApp(['PASSWORD_HASHER' => 'bcrypt', 'PASSWORD_HASHER_OPTIONS' => ['cost' => 4]]);
        $hash = Passwords::make('correct horse');

        self::assertStringStartsWith('bcrypt_sha256$$2y$04$', $hash);
        self::assertTrue(Passwords::check('correct horse', $hash));
        self::assertFalse(Passwords::check('Correct horse', $hash));
        self::assertFalse(Passwords::needsRehash($hash));
        self::assertNotSame($hash, Passwords::make('correct horse'), 'Every hash has its own salt.');

        // Long passwords are not cut at bcrypt's 72 bytes.
        $long = str_repeat('a', 80);
        self::assertFalse(Passwords::check($long . 'b', Passwords::make($long . 'c')));
    }

    public function testArgon2id(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('This PHP build has no Argon2.');
        }
        $this->makeApp(['PASSWORD_HASHER' => 'argon2id', 'PASSWORD_HASHER_OPTIONS' => ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]]);
        $hash = Passwords::make('correct horse');

        self::assertStringStartsWith('argon2id$$argon2id$', $hash);
        self::assertTrue(Passwords::check('correct horse', $hash));
        self::assertFalse(Passwords::check('wrong', $hash));
    }

    public function testHashesFromOtherSettingsNeedARehash(): void
    {
        $this->makeApp(['PASSWORD_HASHER' => 'bcrypt', 'PASSWORD_HASHER_OPTIONS' => ['cost' => 4]]);
        $hash = Passwords::make('pw');
        $this->makeApp(['PASSWORD_HASHER' => 'bcrypt', 'PASSWORD_HASHER_OPTIONS' => ['cost' => 5]]);

        self::assertTrue(Passwords::needsRehash($hash));
        self::assertTrue(Passwords::check('pw', $hash), 'Old hashes keep working.');
    }

    public function testUnusableAndUnknownHashes(): void
    {
        $this->makeApp(['PASSWORD_HASHER' => 'bcrypt', 'PASSWORD_HASHER_OPTIONS' => ['cost' => 4]]);
        $unusable = Passwords::unusable();

        self::assertStringStartsWith('!', $unusable);
        self::assertFalse(Passwords::isUsable($unusable));
        self::assertFalse(Passwords::check($unusable, $unusable));
        self::assertFalse(Passwords::check('', null));
        self::assertFalse(Passwords::check('pw', 'md5$' . md5('pw')));
        self::assertFalse(Passwords::check('pw', password_hash('pw', PASSWORD_BCRYPT)), 'Hashes without a known prefix are refused.');
    }

    public function testInvalidHasherSetting(): void
    {
        $this->makeApp(['PASSWORD_HASHER' => 'md5']);

        $this->expectException(ImproperlyConfigured::class);
        Passwords::make('pw');
    }
}
