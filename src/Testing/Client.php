<?php

declare(strict_types=1);

namespace BrewPHP\Testing;

use BrewPHP\Auth\Auth;
use BrewPHP\Auth\Models\User;
use BrewPHP\Core\Application;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Security\Csrf;
use BrewPHP\Sessions\Session;
use BrewPHP\Sessions\SessionMiddleware;

/**
 * A browser for tests, like Django's test client: it sends requests to the
 * application and keeps the cookies it gets back.
 *
 *     $client = new Client($app);
 *     $client->get('/accounts/login/');
 *     $response = $client->post('/accounts/login/', [
 *         'username' => 'ann',
 *         'password' => 'secret phrase',
 *         'csrfmiddlewaretoken' => $client->csrfToken(),
 *     ]);
 *     $client->login($ann);   // log in without the form
 */
final class Client
{
    /** @var array<string, string> */
    private array $cookies = [];

    /**
     * @param array<string, mixed> $server defaults for every request ($_SERVER keys)
     */
    public function __construct(private readonly Application $app, private readonly array $server = [])
    {
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers e.g. ['Accept' => 'application/json']
     */
    public function get(string $uri, array $query = [], array $headers = []): Response
    {
        return $this->request('GET', $uri, $query, $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @param array<string, mixed> $files
     */
    public function post(string $uri, array $data = [], array $headers = [], array $files = []): Response
    {
        return $this->request('POST', $uri, $data, $headers, $files);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, string> $headers
     * @param array<string, mixed> $files
     */
    public function request(string $method, string $uri, array $parameters = [], array $headers = [], array $files = []): Response
    {
        $server = $this->server;
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = $value;
        }
        /** @var array<string, \BrewPHP\Http\UploadedFile|array<mixed>> $files */
        $request = Request::create($method, $uri, $parameters, $this->cookies, $files, $server);
        $response = $this->app->handle($request);
        foreach ($response->cookies() as $cookie) {
            if ($cookie->expires !== 0 && $cookie->expires < time()) {
                unset($this->cookies[$cookie->name]);
            } else {
                $this->cookies[$cookie->name] = $cookie->value;
            }
        }

        return $response;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function setCookie(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
    }

    /**
     * A valid CSRF token for the next form post (creates the CSRF cookie
     * when there is none yet).
     */
    public function csrfToken(): string
    {
        $name = $this->app->settings()->string('CSRF_COOKIE_NAME', 'csrftoken');
        $secret = $this->cookies[$name] ?? null;
        if ($secret === null || !Csrf::isValidSecret($secret)) {
            $secret = Csrf::generateSecret();
            $this->cookies[$name] = $secret;
        }

        return Csrf::mask($secret);
    }

    /**
     * Log a user in without the login form, by creating their session.
     */
    public function login(User $user): void
    {
        $session = new Session(SessionMiddleware::store($this->app));
        $session->set(Auth::SESSION_KEY, $user->pk);
        $session->set(Auth::BACKEND_SESSION_KEY, $user->authBackend ?? Auth::backends()[0]::class);
        $session->set(Auth::HASH_SESSION_KEY, $user->sessionAuthHash());
        $this->cookies[$this->app->settings()->string('SESSION_COOKIE_NAME', 'brewphp_session')] = $session->save();
    }

    /**
     * Forget every cookie (log out, drop the session).
     */
    public function clearCookies(): void
    {
        $this->cookies = [];
    }
}
