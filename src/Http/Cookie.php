<?php

declare(strict_types=1);

namespace BrewPHP\Http;

/**
 * A cookie to send with a response. HttpOnly and SameSite=Lax are on by
 * default.
 *
 *     $response->cookie(new Cookie('theme', 'dark', expires: '+1 year'));
 */
final class Cookie
{
    public readonly int $expires;

    /**
     * @param int|string|\DateTimeInterface $expires Unix timestamp, a strtotime()
     *        string such as "+1 week", a date, or 0 for a session cookie
     * @param 'Lax'|'Strict'|'None'|null $sameSite
     */
    public function __construct(
        public readonly string $name,
        public readonly string $value = '',
        int|string|\DateTimeInterface $expires = 0,
        public readonly string $path = '/',
        public readonly ?string $domain = null,
        public readonly bool $secure = false,
        public readonly bool $httpOnly = true,
        public readonly ?string $sameSite = 'Lax',
    ) {
        if ($name === '' || preg_match('/[=,; \t\r\n\013\014]/', $name)) {
            throw new \InvalidArgumentException(sprintf('Invalid cookie name "%s".', $name));
        }
        if ($sameSite === 'None' && !$secure) {
            throw new \InvalidArgumentException('A SameSite=None cookie must also be secure.');
        }

        $this->expires = match (true) {
            $expires instanceof \DateTimeInterface => $expires->getTimestamp(),
            is_string($expires) => (int) strtotime($expires),
            default => $expires,
        };
    }

    /**
     * A cookie that tells the browser to delete $name.
     */
    public static function forget(string $name, string $path = '/', ?string $domain = null): self
    {
        return new self($name, '', 1, $path, $domain);
    }

    /**
     * The value of a `Set-Cookie` header.
     */
    public function toHeader(): string
    {
        $parts = [$this->name . '=' . rawurlencode($this->value)];
        if ($this->expires !== 0) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', $this->expires) . ' GMT';
            $parts[] = 'Max-Age=' . max(0, $this->expires - time());
        }
        $parts[] = 'Path=' . $this->path;
        if ($this->domain !== null) {
            $parts[] = 'Domain=' . $this->domain;
        }
        if ($this->secure) {
            $parts[] = 'Secure';
        }
        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }
        if ($this->sameSite !== null) {
            $parts[] = 'SameSite=' . $this->sameSite;
        }

        return implode('; ', $parts);
    }
}
