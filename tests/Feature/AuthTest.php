<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Feature;

use BrewPHP\Auth\Auth;
use BrewPHP\Auth\Models\Group;
use BrewPHP\Auth\Models\Permission;
use BrewPHP\Auth\Models\User;
use BrewPHP\Console\Output;
use BrewPHP\Tests\AuthTestCase;

final class AuthTest extends AuthTestCase
{
    private function login(string $username, string $password, array $extra = []): \BrewPHP\Http\Response
    {
        $this->client->get('/accounts/login/');

        return $this->client->post('/accounts/login/', [
            'username' => $username,
            'password' => $password,
            'csrfmiddlewaretoken' => $this->client->csrfToken(),
            ...$extra,
        ]);
    }

    public function testMigrateCreatesTablesAndDefaultPermissions(): void
    {
        foreach (['auth_user', 'auth_group', 'auth_permission', 'auth_user_groups', 'auth_group_permissions', 'auth_user_permissions', 'sessions_session'] as $table) {
            self::assertTrue($this->app->db()->hasTable($table), $table);
        }
        $codenames = Permission::objects()->filter(app_label: 'demo')->valuesList('codename', flat: true);
        self::assertSame(['add_note', 'add_tag', 'change_note', 'change_tag', 'delete_note', 'delete_tag', 'view_note', 'view_tag'], $codenames);
        self::assertSame('Can add tag', Permission::resolve('demo.add_tag')->name);
        self::assertTrue(Permission::objects()->filter(app_label: 'auth', codename: 'change_user')->exists());
    }

    public function testLoginPageSetsTheCsrfCookieAndIsNotCached(): void
    {
        $response = $this->client->get('/accounts/login/?next=/secret/');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="csrfmiddlewaretoken"', $response->content());
        self::assertStringContainsString('<input type="hidden" name="next" value="/secret/">', $response->content());
        self::assertStringContainsString('autocomplete="current-password"', $response->content());
        self::assertNotNull($this->client->cookie('csrftoken'));
        self::assertStringContainsString('no-store', (string) $response->getHeader('Cache-Control'));
        self::assertStringContainsString('Cookie', (string) $response->getHeader('Vary'));
        $cookie = $response->cookies()['csrftoken'];
        self::assertSame('Lax', $cookie->sameSite);
    }

    public function testLoginAndLogout(): void
    {
        $ann = $this->user();
        self::assertSame(302, $this->client->get('/secret/')->status());
        self::assertSame('/accounts/login/?next=%2Fsecret%2F', $this->client->get('/secret/')->getHeader('Location'));

        $response = $this->login('ann', 'correct horse battery', ['next' => '/secret/']);
        self::assertSame(302, $response->status());
        self::assertSame('/secret/', $response->getHeader('Location'));
        $cookie = $response->cookies()['brewphp_session'];
        self::assertTrue($cookie->httpOnly);
        self::assertSame('Lax', $cookie->sameSite);

        self::assertSame('secret of ann', $this->client->get('/secret/')->content());
        $ann->refresh();
        self::assertNotNull($ann->last_login);

        // Logout only accepts POST.
        self::assertSame(405, $this->client->get('/accounts/logout/')->status());
        $response = $this->client->post('/accounts/logout/', ['csrfmiddlewaretoken' => $this->client->csrfToken()]);
        self::assertSame(200, $response->status());
        self::assertStringContainsString('You have been logged out.', $response->content());
        self::assertSame(302, $this->client->get('/secret/')->status());
    }

    public function testLoginChangesTheSessionIdAndCsrfSecret(): void
    {
        $this->user();
        $this->client->get('/remember/?color=red');
        $before = $this->client->cookie('brewphp_session');
        $csrfBefore = $this->client->cookie('csrftoken');

        $this->login('ann', 'correct horse battery');

        self::assertNotSame($before, $this->client->cookie('brewphp_session'));
        self::assertNotSame($csrfBefore, $this->client->cookie('csrftoken'));
        // Data of the anonymous session is kept.
        self::assertSame('red', $this->client->get('/recall/')->content());
    }

    public function testWrongPasswordsAndInactiveUsersGetTheSameError(): void
    {
        $this->user();
        $this->user('bob', 'another long secret', is_active: false);

        foreach ([['ann', 'wrong password'], ['nobody', 'whatever'], ['bob', 'another long secret']] as [$username, $password]) {
            $response = $this->login($username, $password);
            self::assertSame(200, $response->status(), $username);
            self::assertStringContainsString('Please enter a correct username and password.', $response->content());
            self::assertStringNotContainsString('wrong password', $response->content(), 'The password is never sent back.');
        }
    }

    public function testFailedLoginsAreThrottled(): void
    {
        $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->login('ann', 'wrong ' . $i);
        }
        $response = $this->login('ann', 'correct horse battery');

        self::assertStringContainsString('Too many failed login attempts.', $response->content());
        self::assertSame(302, $this->client->get('/secret/')->status());
    }

    public function testUnsafeNextUrlsAreIgnored(): void
    {
        $this->user();
        foreach (['https://evil.example/', '//evil.example', '/\\evil.example', 'javascript:alert(1)', "/\x00x"] as $next) {
            $this->client->clearCookies();
            $response = $this->login('ann', 'correct horse battery', ['next' => $next]);
            self::assertSame('/', $response->getHeader('Location'), $next);
        }
        $this->client->clearCookies();
        self::assertSame('http://localhost/secret/', $this->login('ann', 'correct horse battery', ['next' => 'http://localhost/secret/'])->getHeader('Location'));
    }

    public function testCsrfProtection(): void
    {
        $this->client->get('/token/');

        // Missing or wrong token.
        self::assertSame(403, $this->client->post('/echo/', ['value' => 'x'])->status());
        self::assertSame(403, $this->client->post('/echo/', ['value' => 'x', 'csrfmiddlewaretoken' => str_repeat('a', 64)])->status());
        self::assertStringContainsString('CSRF verification failed.', $this->client->post('/echo/')->content());

        // A form token or the X-CSRFToken header.
        self::assertSame('posted x', $this->client->post('/echo/', ['value' => 'x', 'csrfmiddlewaretoken' => $this->client->csrfToken()])->content());
        self::assertSame('posted y', $this->client->post('/echo/', ['value' => 'y'], ['X-CSRFToken' => $this->client->csrfToken()])->content());

        // Another site's Origin is refused even with a valid token.
        $response = $this->client->post('/echo/', ['csrfmiddlewaretoken' => $this->client->csrfToken()], ['Origin' => 'https://evil.example']);
        self::assertSame(403, $response->status());
        self::assertSame(200, $this->client->post('/echo/', ['csrfmiddlewaretoken' => $this->client->csrfToken()], ['Origin' => 'http://localhost'])->status());

        // Exempt views and unknown URLs.
        self::assertSame('hook ok', $this->client->post('/hook/')->content());
        self::assertSame(404, $this->client->post('/nowhere/')->status());
    }

    public function testCsrfOverHttpsChecksTheReferer(): void
    {
        $this->client->get('https://localhost/token/');
        $token = $this->client->csrfToken();

        self::assertSame(403, $this->client->post('https://localhost/echo/', ['csrfmiddlewaretoken' => $token])->status());
        self::assertSame(403, $this->client->post('https://localhost/echo/', ['csrfmiddlewaretoken' => $token], ['Referer' => 'http://localhost/token/'])->status());
        self::assertSame(403, $this->client->post('https://localhost/echo/', ['csrfmiddlewaretoken' => $token], ['Referer' => 'https://evil.example/'])->status());
        self::assertSame(200, $this->client->post('https://localhost/echo/', ['csrfmiddlewaretoken' => $token], ['Referer' => 'https://localhost/token/'])->status());
    }

    public function testCsrfTrustedOrigins(): void
    {
        $this->app->settings()->set('CSRF_TRUSTED_ORIGINS', ['https://*.example.com']);
        $this->client->get('/token/');
        $token = $this->client->csrfToken();

        self::assertSame(200, $this->client->post('/echo/', ['csrfmiddlewaretoken' => $token], ['Origin' => 'https://shop.example.com'])->status());
        self::assertSame(403, $this->client->post('/echo/', ['csrfmiddlewaretoken' => $token], ['Origin' => 'https://example.com.evil.net'])->status());
        self::assertSame(403, $this->client->post('/echo/', ['csrfmiddlewaretoken' => $token], ['Origin' => 'http://shop.example.com'])->status());
    }

    public function testTokenTemplateAndAuthContext(): void
    {
        $ann = $this->user();
        $content = $this->client->get('/token/')->content();
        self::assertMatchesRegularExpression('/<input type="hidden" name="csrfmiddlewaretoken" value="[A-Za-z0-9]{64}">/', $content);
        self::assertStringContainsString('anonymous', $content);

        $ann->grant('demo.add_tag');
        $this->client->login($ann);
        self::assertStringContainsString('hi ann can-add', $this->client->get('/token/')->content());
    }

    public function testPermissionRequired(): void
    {
        $ann = $this->user();
        self::assertSame(302, $this->client->get('/tags/new/')->status());

        $this->client->login($ann);
        self::assertSame(403, $this->client->get('/tags/new/')->status());

        $editors = Group::create(['name' => 'Editors']);
        $editors->grant('demo.add_tag');
        $ann->addToGroup($editors);
        self::assertSame('can add tags', $this->client->get('/tags/new/')->content());

        $ann->removeFromGroup($editors);
        self::assertSame(403, $this->client->get('/tags/new/')->status());
    }

    public function testPasswordChangeLogsOutOtherSessions(): void
    {
        $ann = $this->user();
        $this->client->login($ann);
        $other = new \BrewPHP\Testing\Client($this->app);
        $other->login($ann);
        self::assertSame(200, $other->get('/secret/')->status());

        $this->client->get('/accounts/password_change/');
        $response = $this->client->post('/accounts/password_change/', [
            'old_password' => 'correct horse battery',
            'new_password1' => 'a brand new passphrase',
            'new_password2' => 'a brand new passphrase',
            'csrfmiddlewaretoken' => $this->client->csrfToken(),
        ]);
        self::assertSame('/accounts/password_change/done/', $response->getHeader('Location'));

        self::assertSame(200, $this->client->get('/secret/')->status(), 'This session stays logged in.');
        self::assertSame(302, $other->get('/secret/')->status(), 'Other sessions are logged out.');
        $ann->refresh();
        self::assertTrue($ann->checkPassword('a brand new passphrase'));
    }

    public function testPasswordChangeValidation(): void
    {
        $this->app->settings()->set('AUTH_PASSWORD_VALIDATORS', [
            \BrewPHP\Auth\Validators\MinimumLengthValidator::class,
            \BrewPHP\Auth\Validators\CommonPasswordValidator::class,
            \BrewPHP\Auth\Validators\NumericPasswordValidator::class,
            \BrewPHP\Auth\Validators\UserAttributeSimilarityValidator::class,
        ]);
        $this->client->login($this->user());
        $this->client->get('/accounts/password_change/');
        $post = fn (string $old, string $new, string $again): string => $this->client->post('/accounts/password_change/', [
            'old_password' => $old,
            'new_password1' => $new,
            'new_password2' => $again,
            'csrfmiddlewaretoken' => $this->client->csrfToken(),
        ])->content();

        self::assertStringContainsString('Your old password was entered incorrectly.', $post('nope', 'a brand new passphrase', 'a brand new passphrase'));
        self::assertStringContainsString('The two password fields didn’t match.', $post('correct horse battery', 'a brand new passphrase', 'another passphrase'));
        $content = $post('correct horse battery', '12345678', '12345678');
        self::assertStringContainsString('This password is too common.', $content);
        self::assertStringContainsString('This password is entirely numeric.', $content);
        self::assertStringContainsString('This password is too short.', $post('correct horse battery', 'x7#', 'x7#'));
        self::assertStringContainsString('The password is too similar to the email address.', $post('correct horse battery', 'ann@example', 'ann@example'));
    }

    public function testSessionsAndMessages(): void
    {
        self::assertSame('none', $this->client->get('/recall/')->content());
        $response = $this->client->get('/remember/?color=green');
        self::assertNotNull($response->cookies()['brewphp_session'] ?? null);
        self::assertSame('green', $this->client->get('/recall/')->content());

        // Messages are shown (escaped) once.
        self::assertStringContainsString('<p class="success">Saved &lt;b&gt;green&lt;/b&gt;.</p>', $this->client->get('/flash/')->content());
        self::assertStringContainsString('none', $this->client->get('/flash/')->content());

        // An unknown session id from the browser is never adopted.
        $this->client->setCookie('brewphp_session', str_repeat('a', 40));
        $this->client->get('/remember/?color=red');
        self::assertNotSame(str_repeat('a', 40), $this->client->cookie('brewphp_session'));
    }

    public function testPagesThatDoNotUseTheSessionSetNoCookie(): void
    {
        $response = $this->client->get('/recall/');
        self::assertSame([], array_keys($response->cookies()));
    }

    public function testAuthenticateLoginLogoutApi(): void
    {
        $ann = $this->user();
        self::assertNull(Auth::authenticate(null, ['username' => 'ann', 'password' => 'nope']));
        $user = Auth::authenticate(null, ['username' => 'ann', 'password' => 'correct horse battery']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame($ann->pk, $user->pk);
        self::assertSame(\BrewPHP\Auth\Backends\ModelBackend::class, $user->authBackend);
    }

    public function testCreateSuperuserAndChangePassword(): void
    {
        $run = function (array $arguments, string $answers = ''): array {
            $output = Output::buffered();
            $stdin = fopen('php://memory', 'r+');
            self::assertIsResource($stdin);
            fwrite($stdin, $answers);
            rewind($stdin);
            $code = $this->app->console()->run(['manage.php', ...$arguments], $output, $stdin);

            return [$code, $output->fetch()];
        };

        [$code, $out] = $run(['createsuperuser', '--username=root', '--email=root@example.com'], "a strong passphrase\na strong passphrase\n");
        self::assertSame(0, $code, $out);
        $root = User::objects()->get(username: 'root');
        self::assertTrue($root->isSuperuser() && $root->isStaff());
        self::assertTrue($root->checkPassword('a strong passphrase'));
        self::assertTrue($root->hasPerm('anything.at_all'));

        [$code, $out] = $run(['createsuperuser', '-n', '--username=root']);
        self::assertSame(1, $code);
        self::assertStringContainsString('That username is already taken.', $out);

        [$code, $out] = $run(['createsuperuser', '-n', '--username=bad name']);
        self::assertStringContainsString('Enter a valid username.', $out);

        [$code, $out] = $run(['createsuperuser', '-n', '--username=nopass']);
        self::assertSame(0, $code, $out);
        self::assertFalse(User::objects()->get(username: 'nopass')->hasUsablePassword());

        [$code, $out] = $run(['changepassword', 'root'], "new secret phrase\nnew secret phrase\n");
        self::assertSame(0, $code, $out);
        $root->refresh();
        self::assertTrue($root->checkPassword('new secret phrase'));

        self::assertSame(1, $run(['changepassword', 'ghost'])[0]);
    }
}
