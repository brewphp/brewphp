<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Core;

use BrewPHP\Core\Env;
use BrewPHP\Tests\TestCase;

final class EnvTest extends TestCase
{
    public function testParsesCommonSyntax(): void
    {
        $values = Env::parse(<<<'ENV'
            # a comment
            APP_NAME=BrewPHP
            export EXPORTED=yes
            QUOTED="hello world"
            SINGLE='raw $value \n'
            ESCAPED="line\nbreak"
            INLINE=value # comment
            EMPTY=
            HASH_IN_QUOTES="a # b"
            not valid line
            9BAD=no
            ENV);

        self::assertSame([
            'APP_NAME' => 'BrewPHP',
            'EXPORTED' => 'yes',
            'QUOTED' => 'hello world',
            'SINGLE' => 'raw $value \n',
            'ESCAPED' => "line\nbreak",
            'INLINE' => 'value',
            'EMPTY' => '',
            'HASH_IN_QUOTES' => 'a # b',
        ], $values);
    }

    public function testGetConvertsSpecialValues(): void
    {
        $file = $this->tempDir() . '/.env';
        file_put_contents($file, "BREW_T=true\nBREW_F=(false)\nBREW_N=null\nBREW_E=empty\nBREW_S=text\n");
        Env::load($file);

        self::assertTrue(Env::get('BREW_T'));
        self::assertFalse(Env::get('BREW_F'));
        self::assertNull(Env::get('BREW_N', 'default'));
        self::assertSame('', Env::get('BREW_E'));
        self::assertSame('text', Env::get('BREW_S'));
        self::assertSame('fallback', Env::get('BREW_MISSING', 'fallback'));
        self::assertSame('text', $_ENV['BREW_S']);
    }

    public function testRealEnvironmentWins(): void
    {
        putenv('BREW_REAL=from-server');
        try {
            $file = $this->tempDir() . '/.env';
            file_put_contents($file, "BREW_REAL=from-file\n");
            Env::load($file);

            self::assertSame('from-server', Env::get('BREW_REAL'));
        } finally {
            putenv('BREW_REAL');
        }
    }

    public function testMissingFileIsIgnored(): void
    {
        Env::load('/does/not/exist/.env');
        self::assertNull(Env::get('ANYTHING_UNSET_123'));
    }

    public function testResetForgetsLoadedValues(): void
    {
        $file = $this->tempDir() . '/.env';
        file_put_contents($file, "BREW_RESET=1\n");
        Env::load($file);
        Env::reset();

        self::assertNull(Env::get('BREW_RESET'));
        self::assertArrayNotHasKey('BREW_RESET', $_ENV);
    }
}
