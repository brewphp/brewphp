<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Security;

use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Http\Request;
use BrewPHP\Security\BadSignature;
use BrewPHP\Security\Csrf;
use BrewPHP\Security\CsrfExempt;
use BrewPHP\Security\RateLimiter;
use BrewPHP\Security\Redirects;
use BrewPHP\Security\SignatureExpired;
use BrewPHP\Security\Signer;
use BrewPHP\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SecurityTest extends TestCase
{
    public function testSignAndUnsign(): void
    {
        $signer = new Signer('secret-key', 'salt');
        $signed = $signer->sign('42');

        self::assertMatchesRegularExpression('/^42:[A-Za-z0-9_-]{43}$/', $signed);
        self::assertSame('42', $signer->unsign($signed));
        self::assertSame('a:b', $signer->unsign($signer->sign('a:b')));

        foreach (['43' . substr($signed, 2), '42', $signed . 'x'] as $tampered) {
            try {
                $signer->unsign($tampered);
                self::fail('Tampered value accepted: ' . $tampered);
            } catch (BadSignature) {
            }
        }
        // Another salt or key does not accept the signature.
        $this->expectException(BadSignature::class);
        (new Signer('secret-key', 'other'))->unsign($signed);
    }

    public function testTimestampedSignatures(): void
    {
        $signer = new Signer('secret-key');
        $signed = $signer->signWithTime('ann', now: 1000);

        self::assertSame('ann', $signer->unsignWithTime($signed, 60, now: 1050));
        try {
            $signer->unsignWithTime($signer->signWithTime('ann', now: 5000), 60, now: 1000);
            self::fail('A timestamp from the future was accepted.');
        } catch (SignatureExpired) {
            self::fail('Wrong exception.');
        } catch (BadSignature) {
        }
        $this->expectException(SignatureExpired::class);
        $signer->unsignWithTime($signed, 60, now: 1061);
    }

    public function testDumpsAndLoads(): void
    {
        $signer = new Signer('secret-key');
        self::assertSame(['id' => 5, 'tags' => ['a']], $signer->loads($signer->dumps(['id' => 5, 'tags' => ['a']])));
    }

    public function testSignerNeedsAKey(): void
    {
        $this->expectException(ImproperlyConfigured::class);
        new Signer('');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function redirectProvider(): iterable
    {
        yield 'path' => ['/accounts/profile/', true];
        yield 'relative' => ['profile/', true];
        yield 'with query' => ['/search/?q=a', true];
        yield 'same host' => ['http://localhost/x/', true];
        yield 'other host' => ['https://evil.example/', false];
        yield 'protocol relative' => ['//evil.example/', false];
        yield 'triple slash' => ['///evil.example/', false];
        yield 'backslash' => ['/\\evil.example/', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'data' => ['data:text/html,<script>', false];
        yield 'control character' => ["/\nx", false];
        yield 'user info' => ['http://localhost@evil.example/', false];
        yield 'missing host' => ['http:/evil.example', false];
        yield 'empty' => ['', false];
        yield 'trailing CRLF' => ["/admin/\r\n", false];
        yield 'surrounding spaces' => [' /admin/', false];
    }

    #[DataProvider('redirectProvider')]
    public function testSafeRedirects(string $url, bool $safe): void
    {
        self::assertSame($safe, Redirects::isSafe($url, Request::create('GET', 'http://localhost/login/')));
    }

    public function testSecureRequestsOnlyRedirectToHttps(): void
    {
        $request = Request::create('GET', 'https://localhost/login/');

        self::assertTrue(Redirects::isSafe('https://localhost/next/', $request));
        self::assertFalse(Redirects::isSafe('http://localhost/next/', $request));
        self::assertTrue(Redirects::isSafe('https://shop.example/', $request, ['shop.example']));
        self::assertFalse(Redirects::isSafe(['/array'], $request));
    }

    public function testCsrfMasking(): void
    {
        $secret = Csrf::generateSecret();
        $first = Csrf::mask($secret);
        $second = Csrf::mask($secret);

        self::assertTrue(Csrf::isValidSecret($secret));
        self::assertSame(64, strlen($first));
        self::assertNotSame($first, $second, 'Every token looks different (BREACH).');
        self::assertSame($secret, Csrf::unmask($first));
        self::assertTrue(Csrf::matches($first, $secret));
        self::assertTrue(Csrf::matches($secret, $secret));
        self::assertFalse(Csrf::matches(Csrf::mask(Csrf::generateSecret()), $secret));
        self::assertFalse(Csrf::matches(substr($first, 1), $secret));
        self::assertFalse(Csrf::matches(str_repeat('!', 64), $secret));
    }

    public function testCsrfTokensOfARequest(): void
    {
        $request = Request::create('GET', '/');
        $token = Csrf::token($request);
        $secret = $request->attribute(Csrf::SECRET_ATTRIBUTE);

        self::assertIsString($secret);
        self::assertTrue(Csrf::matches($token, $secret));
        self::assertTrue($request->attribute(Csrf::USED_ATTRIBUTE));
        Csrf::rotate($request);
        self::assertFalse(Csrf::matches($token, (string) $request->attribute(Csrf::SECRET_ATTRIBUTE)));
    }

    public function testCsrfExemptAttribute(): void
    {
        self::assertTrue(CsrfExempt::appliesTo(#[CsrfExempt] static fn (): string => ''));
        self::assertFalse(CsrfExempt::appliesTo(static fn (): string => ''));
        self::assertTrue(CsrfExempt::appliesTo([ExemptController::class, 'hook']));
        self::assertFalse(CsrfExempt::appliesTo([ExemptController::class, 'form']));
        self::assertFalse(CsrfExempt::appliesTo(['NoSuchClass', 'x']));
    }

    public function testRateLimiter(): void
    {
        $limiter = new RateLimiter($this->tempDir());

        self::assertFalse($limiter->tooManyAttempts('ann|1.2.3.4', 3));
        self::assertSame(1, $limiter->hit('ann|1.2.3.4', 60));
        self::assertSame(2, $limiter->hit('ann|1.2.3.4', 60));
        self::assertSame(3, $limiter->hit('ann|1.2.3.4', 60));
        self::assertTrue($limiter->tooManyAttempts('ann|1.2.3.4', 3));
        self::assertFalse($limiter->tooManyAttempts('bob|1.2.3.4', 3));
        self::assertGreaterThan(55, $limiter->availableIn('ann|1.2.3.4'));

        $limiter->clear('ann|1.2.3.4');
        self::assertSame(0, $limiter->attempts('ann|1.2.3.4'));
        self::assertSame(1, $limiter->hit('expired', -1));
        self::assertSame(0, $limiter->attempts('expired'));
    }
}

final class ExemptController
{
    #[CsrfExempt]
    public function hook(): string
    {
        return '';
    }

    public function form(): string
    {
        return '';
    }
}
