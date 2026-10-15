<?php

declare(strict_types=1);

namespace BrewPHP\Security;

use BrewPHP\Core\Application;
use BrewPHP\Core\ImproperlyConfigured;

/**
 * Signs values with an HMAC-SHA256 of the SECRET_KEY, like Django's
 * `django.core.signing`. A signed value can be read by anyone but not
 * changed: use it for tokens in URLs or cookies, never for secrets.
 *
 *     $signer = Signer::make('newsletter.unsubscribe');
 *     $token = $signer->sign('42');            // "42:Kx3..."
 *     $signer->unsign($token);                 // "42", or BadSignature
 *     $signer->unsignWithTime($token, 86400);  // with signWithTime(): at most a day old
 *
 * The salt separates uses: a token signed for one purpose is rejected by
 * a signer with another salt.
 */
final class Signer
{
    private readonly string $key;

    public function __construct(string $secretKey, private readonly string $salt = 'brewphp.signing')
    {
        if ($secretKey === '') {
            throw new ImproperlyConfigured('Signing needs a SECRET_KEY. Run "php manage.php key:generate".');
        }
        // A key per salt: a signature for one purpose is useless for another.
        $this->key = hash_hmac('sha256', 'brewphp.signer:' . $salt, $secretKey, true);
    }

    /**
     * A signer using the SECRET_KEY setting.
     */
    public static function make(string $salt = 'brewphp.signing'): self
    {
        return new self(Application::getInstance()->settings()->string('SECRET_KEY'), $salt);
    }

    public function signature(string $value): string
    {
        return self::base64(hash_hmac('sha256', $value, $this->key, true));
    }

    public function sign(string $value): string
    {
        return $value . ':' . $this->signature($value);
    }

    /**
     * The original value of a signed one.
     *
     * @throws BadSignature
     */
    public function unsign(string $signed): string
    {
        $position = strrpos($signed, ':');
        if ($position === false) {
            throw new BadSignature('No signature found.');
        }
        $value = substr($signed, 0, $position);
        if (!hash_equals($this->signature($value), substr($signed, $position + 1))) {
            throw new BadSignature('Signature does not match.');
        }

        return $value;
    }

    /**
     * Sign a value together with the current time.
     */
    public function signWithTime(string $value, ?int $now = null): string
    {
        return $this->sign($value . ':' . base_convert((string) ($now ?? time()), 10, 36));
    }

    /**
     * The value of signWithTime(), if it is at most `$maxAge` seconds old.
     *
     * @throws BadSignature|SignatureExpired
     */
    public function unsignWithTime(string $signed, int $maxAge, ?int $now = null): string
    {
        $value = $this->unsign($signed);
        $position = strrpos($value, ':');
        if ($position === false) {
            throw new BadSignature('No timestamp found.');
        }
        $timestamp = (int) base_convert(substr($value, $position + 1), 36, 10);
        $age = ($now ?? time()) - $timestamp;
        if ($age < -60) {
            throw new BadSignature('Signature timestamp is in the future.');
        }
        if ($age > $maxAge) {
            throw new SignatureExpired(sprintf('Signature age %d > %d seconds.', $age, $maxAge));
        }

        return substr($value, 0, $position);
    }

    /**
     * Sign JSON-encodable data as a compact URL-safe string.
     */
    public function dumps(mixed $data): string
    {
        return $this->sign(self::base64(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
    }

    /**
     * @throws BadSignature
     */
    public function loads(string $signed): mixed
    {
        $json = base64_decode(strtr($this->unsign($signed), '-_', '+/'), true);
        if ($json === false) {
            throw new BadSignature('Invalid payload.');
        }

        return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }

    private static function base64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
