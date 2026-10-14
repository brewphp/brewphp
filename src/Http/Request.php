<?php

declare(strict_types=1);

namespace BrewPHP\Http;

/**
 * An incoming HTTP request.
 *
 * Build one from PHP's globals with `Request::fromGlobals()`, or by hand
 * (handy in tests) with `Request::create('GET', '/posts/?page=2')`.
 */
class Request
{
    /** @var array<string, string> lower-case name => value */
    private array $headers = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var array<string, mixed>|null */
    private ?array $json = null;

    private string $method;

    /**
     * @param array<string, mixed> $query   $_GET
     * @param array<string, mixed> $post    $_POST
     * @param array<string, string> $cookies $_COOKIE
     * @param array<string, UploadedFile|array<mixed>> $files normalised uploads
     * @param array<string, mixed> $server  $_SERVER
     */
    final public function __construct(
        private array $query = [],
        private array $post = [],
        private array $cookies = [],
        private array $files = [],
        private array $server = [],
        private string $content = '',
    ) {
        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $this->headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $this->headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }

        $method = strtoupper(is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET');
        // HTML forms can only send GET and POST; allow `_method` to override.
        if ($method === 'POST') {
            $override = $this->post['_method'] ?? $this->headers['x-http-method-override'] ?? null;
            if (is_string($override) && in_array(strtoupper($override), ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = strtoupper($override);
            }
        }
        $this->method = $method;
    }

    public static function fromGlobals(): static
    {
        /** @var array<string, string> $cookies */
        $cookies = $_COOKIE;

        return new static(
            $_GET,
            $_POST,
            $cookies,
            UploadedFile::normalize($_FILES),
            $_SERVER,
            (string) file_get_contents('php://input'),
        );
    }

    /**
     * Build a request by hand. For GET requests $parameters become the query
     * string; for other methods they become the form body.
     *
     * @param array<string, mixed> $parameters
     * @param array<string, string> $cookies
     * @param array<string, UploadedFile|array<mixed>> $files
     * @param array<string, mixed> $server
     */
    public static function create(
        string $method,
        string $uri,
        array $parameters = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        string $content = '',
    ): static {
        $method = strtoupper($method);
        $parts = parse_url($uri) ?: [];

        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $post = [];
        if (in_array($method, ['GET', 'HEAD'], true)) {
            $query = array_replace($query, $parameters);
        } else {
            $post = $parameters;
        }

        $queryString = http_build_query($query);
        $server = array_replace([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => ($parts['path'] ?? '/') . ($queryString !== '' ? '?' . $queryString : ''),
            'QUERY_STRING' => $queryString,
            'SERVER_NAME' => $parts['host'] ?? 'localhost',
            'SERVER_PORT' => $parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80),
            'HTTP_HOST' => ($parts['host'] ?? 'localhost') . (isset($parts['port']) ? ':' . $parts['port'] : ''),
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/index.php',
        ], $server);
        if (($parts['scheme'] ?? '') === 'https') {
            $server['HTTPS'] = 'on';
        }

        /** @var array<string, mixed> $query */
        return new static($query, $post, $cookies, $files, $server, $content);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * The URL path without the query string, always starting with "/".
     */
    public function path(): string
    {
        $uri = $this->serverString('REQUEST_URI', '/');
        $path = rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?? '/'));

        return '/' . ltrim($path, '/');
    }

    /**
     * The path including the query string, e.g. "/posts/?page=2".
     */
    public function fullPath(): string
    {
        $query = $this->serverString('QUERY_STRING');

        return $this->path() . ($query !== '' ? '?' . $query : '');
    }

    public function scheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    public function isSecure(): bool
    {
        $https = $this->serverString('HTTPS');

        return ($https !== '' && strtolower($https) !== 'off') || $this->serverString('SERVER_PORT') === '443';
    }

    /**
     * The host name without the port, lower-case.
     */
    public function host(): string
    {
        $host = $this->headers['host'] ?? $this->serverString('SERVER_NAME', 'localhost');

        return strtolower((string) preg_replace('/:\d+$/', '', trim($host)));
    }

    /**
     * Host with the port when it is not the default for the scheme.
     */
    public function httpHost(): string
    {
        $port = $this->port();
        $default = $this->isSecure() ? 443 : 80;

        return $this->host() . ($port !== $default ? ':' . $port : '');
    }

    public function port(): int
    {
        $host = $this->headers['host'] ?? '';
        if (preg_match('/:(\d+)$/', $host, $m)) {
            return (int) $m[1];
        }
        $port = $this->serverString('SERVER_PORT');

        return $port !== '' ? (int) $port : ($this->isSecure() ? 443 : 80);
    }

    /**
     * The absolute URL without the query string.
     */
    public function url(): string
    {
        return $this->scheme() . '://' . $this->httpHost() . $this->path();
    }

    /**
     * The absolute URL including the query string.
     */
    public function fullUrl(): string
    {
        return $this->scheme() . '://' . $this->httpHost() . $this->fullPath();
    }

    /**
     * Build an absolute URL for a path on this site.
     */
    public function absoluteUrl(string $path): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
            return $path;
        }

        return $this->scheme() . '://' . $this->httpHost() . '/' . ltrim($path, '/');
    }

    /**
     * A query-string value ($_GET), or all of them.
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    /**
     * A form-body value ($_POST), or all of them.
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->post : ($this->post[$key] ?? $default);
    }

    /**
     * A value from the body (form or JSON) or the query string, body first.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * Every input value: query string merged with the form or JSON body.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $body = $this->isJson() ? $this->json() : $this->post;

        return array_replace($this->query, $body);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * The decoded JSON body (an empty array when it is not valid JSON).
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->json === null) {
            $decoded = json_decode($this->content, true);
            $this->json = is_array($decoded) ? $decoded : [];
        }

        return $this->json;
    }

    public function isJson(): bool
    {
        return str_contains($this->header('content-type') ?? '', 'json');
    }

    public function wantsJson(): bool
    {
        return $this->isJson() || str_contains($this->header('accept') ?? '', 'application/json');
    }

    public function isAjax(): bool
    {
        return $this->header('x-requested-with') === 'XMLHttpRequest';
    }

    public function content(): string
    {
        return $this->content;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function file(string $name): ?UploadedFile
    {
        $file = $this->files[$name] ?? null;

        return $file instanceof UploadedFile ? $file : null;
    }

    /**
     * @return array<string, UploadedFile|array<mixed>>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * A `$_SERVER` value, or all of them.
     */
    public function server(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->server : ($this->server[$key] ?? $default);
    }

    public function ip(): string
    {
        return $this->serverString('REMOTE_ADDR', '127.0.0.1');
    }

    /**
     * Attributes are values attached while the request is handled, such as
     * the matched route or (later) the logged-in user.
     */
    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    private function serverString(string $key, string $default = ''): string
    {
        $value = $this->server[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }
}
