<?php

declare(strict_types=1);

namespace BrewPHP\Http;

/**
 * An HTTP response: content, a status code, headers and cookies.
 *
 *     return (new Response('<h1>Hello</h1>'))
 *         ->header('Cache-Control', 'no-store');
 */
class Response
{
    public const STATUS_TEXTS = [
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified',
        307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 409 => 'Conflict', 410 => 'Gone', 413 => 'Content Too Large',
        415 => 'Unsupported Media Type', 419 => 'Page Expired', 422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
    ];

    /** @var array<string, array{0: string, 1: string}> lower-case name => [name, value] */
    private array $headers = [];

    /** @var array<string, Cookie> */
    private array $cookies = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        protected string $content = '',
        protected int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }
        if (!$this->hasHeader('Content-Type')) {
            $this->header('Content-Type', 'text/html; charset=utf-8');
        }
    }

    public function content(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException(sprintf('Invalid HTTP status code %d.', $status));
        }
        $this->status = $status;

        return $this;
    }

    public function statusText(): string
    {
        return self::STATUS_TEXTS[$this->status] ?? '';
    }

    /**
     * Set a header, replacing any previous value with the same name.
     */
    public function header(string $name, string $value): static
    {
        if (preg_match('/[\r\n]/', $name . $value)) {
            throw new \InvalidArgumentException('Header names and values cannot contain line breaks.');
        }
        $this->headers[strtolower($name)] = [$name, $value];

        return $this;
    }

    /**
     * Add header names to `Vary` (once each), e.g. `vary('Cookie')` when the
     * response depends on the session.
     */
    public function vary(string ...$headers): static
    {
        $current = array_filter(array_map('trim', explode(',', (string) $this->getHeader('Vary'))), static fn (string $h): bool => $h !== '');
        $lower = array_map('strtolower', $current);
        foreach ($headers as $header) {
            if (!in_array(strtolower($header), $lower, true)) {
                $current[] = $header;
                $lower[] = strtolower($header);
            }
        }

        return $this->header('Vary', implode(', ', $current));
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)][1] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function removeHeader(string $name): static
    {
        unset($this->headers[strtolower($name)]);

        return $this;
    }

    /**
     * @return array<string, string> name => value, with the original casing
     */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->headers as [$name, $value]) {
            $headers[$name] = $value;
        }

        return $headers;
    }

    public function cookie(Cookie $cookie): static
    {
        $this->cookies[$cookie->name] = $cookie;

        return $this;
    }

    /**
     * Tell the browser to delete a cookie.
     */
    public function deleteCookie(string $name, string $path = '/', ?string $domain = null): static
    {
        return $this->cookie(Cookie::forget($name, $path, $domain));
    }

    /**
     * @return array<string, Cookie>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * Send status, headers, cookies and content to the client.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers() as $name => $value) {
                header($name . ': ' . $value, true);
            }
            foreach ($this->cookies as $cookie) {
                header('Set-Cookie: ' . $cookie->toHeader(), false);
            }
        }
        $this->sendContent();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    protected function sendContent(): void
    {
        echo $this->content;
    }
}
