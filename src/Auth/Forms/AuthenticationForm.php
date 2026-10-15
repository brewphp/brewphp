<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Forms;

use BrewPHP\Auth\Auth;
use BrewPHP\Auth\Models\User;
use BrewPHP\Core\Application;
use BrewPHP\Core\ValidationError;
use BrewPHP\Forms\Fields\CharField;
use BrewPHP\Forms\Form;
use BrewPHP\Forms\Widgets\PasswordInput;
use BrewPHP\Forms\Widgets\TextInput;
use BrewPHP\Http\Request;
use BrewPHP\Security\RateLimiter;

/**
 * The login form: a username and a password, checked with
 * Auth::authenticate().
 *
 * Failed attempts are counted per username and IP address; after
 * AUTH_LOGIN_ATTEMPTS failures (5 by default) the form refuses to check
 * passwords for AUTH_LOGIN_LOCKOUT seconds (900). Set AUTH_LOGIN_ATTEMPTS
 * to 0 to turn this off. The error never says whether the username exists.
 */
class AuthenticationForm extends Form
{
    private ?User $user = null;

    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $files
     * @param array<string, mixed> $initial
     */
    public function __construct(?array $data = null, array $files = [], array $initial = [], string $prefix = '', ?string $autoId = 'id_%s', protected ?Request $request = null)
    {
        parent::__construct($data, $files, $initial, $prefix, $autoId);
    }

    public function fields(): array
    {
        return [
            'username' => new CharField(maxLength: 150, widget: new TextInput(['autofocus' => true, 'autocomplete' => 'username', 'autocapitalize' => 'none'])),
            'password' => new CharField(strip: false, widget: new PasswordInput(['autocomplete' => 'current-password'])),
        ];
    }

    public function clean(array $data): array
    {
        $username = $data['username'] ?? null;
        $password = $data['password'] ?? null;
        if (!is_string($username) || !is_string($password) || $username === '' || $password === '') {
            return $data;
        }
        $settings = Application::getInstance()->settings();
        $maxAttempts = (int) $settings->get('AUTH_LOGIN_ATTEMPTS', 5);
        $limiter = RateLimiter::make();
        $key = 'login|' . self::throttleName($username) . '|' . ($this->request?->ip() ?? '');
        if ($maxAttempts > 0) {
            // Count the attempt before checking the password, in one locked step,
            // so parallel requests cannot all slip in before a failure is counted.
            if ($limiter->hit($key, (int) $settings->get('AUTH_LOGIN_LOCKOUT', 900)) > $maxAttempts) {
                throw new ValidationError(sprintf(
                    'Too many failed login attempts. Try again in %d minute(s).',
                    max(1, (int) ceil($limiter->availableIn($key) / 60)),
                ), 'too_many_attempts');
            }
        }

        $this->user = Auth::authenticate($this->request, ['username' => $username, 'password' => $password]);
        if ($this->user === null) {
            throw new ValidationError(
                'Please enter a correct username and password. Note that both fields may be case-sensitive.',
                'invalid_login',
            );
        }
        $limiter->clear($key);

        return $data;
    }

    /**
     * The name the throttle counts attempts under: the stored username when
     * one matches (the database may match "Ánn" to "ann"), so spelling
     * variants of one account share a single counter.
     */
    private static function throttleName(string $username): string
    {
        $stored = User::objects()->filter(username: $username)->valuesList('username', flat: true)[0] ?? $username;
        $name = is_string($stored) ? $stored : $username;

        return function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
    }

    /**
     * The authenticated user, once the form is valid.
     */
    public function getUser(): ?User
    {
        return $this->isValid() ? $this->user : null;
    }

    /**
     * The user the credentials matched, before other checks (for subclasses
     * that add rules in clean()).
     */
    protected function getUserUnchecked(): ?User
    {
        return $this->user;
    }
}
