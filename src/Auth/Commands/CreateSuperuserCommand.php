<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Commands;

use BrewPHP\Auth\Models\User;
use BrewPHP\Auth\Passwords;
use BrewPHP\Console\Command;
use BrewPHP\Core\ValidationError;

final class CreateSuperuserCommand extends Command
{
    public string $name = 'createsuperuser';

    public string $description = 'Create a user with every permission (and access to the admin)';

    public string $help = <<<'TEXT'
        Asks for a username, an email address and a password (typed twice,
        hidden). Without interaction (-n), give --username and --email, and
        the password in the BREWPHP_SUPERUSER_PASSWORD environment variable;
        without it the account gets no usable password.
        A password is never accepted as a command-line argument: it would end
        up in the shell history and the process list.
        TEXT;

    protected function configure(): void
    {
        $this->addOption('username', 'The username', value: true);
        $this->addOption('email', 'The email address', value: true);
    }

    public function handle(): int
    {
        if (!$this->app->apps()->has('auth')) {
            $this->error("Add 'brewphp.auth' to INSTALLED_APPS and run migrate first.");

            return self::FAILURE;
        }
        $interactive = $this->input->isInteractive();

        $username = $this->option('username');
        while (true) {
            if (!is_string($username) || trim($username) === '') {
                if (!$interactive) {
                    $this->error('--username is required without interaction.');

                    return self::INVALID;
                }
                $username = $this->ask('Username');
                continue;
            }
            $username = trim($username);
            $problem = $this->usernameProblem($username);
            if ($problem === null) {
                break;
            }
            $this->error($problem);
            if (!$interactive) {
                return self::FAILURE;
            }
            $username = null;
        }

        $email = $this->option('email');
        if (!is_string($email)) {
            $email = $interactive ? (string) $this->ask('Email address', '') : '';
        }
        if ($email !== '' && filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Enter a valid email address.');

            return self::INVALID;
        }

        $password = $interactive ? $this->askPassword($username, $email) : $this->environmentPassword();
        if ($password === false) {
            return self::FAILURE;
        }

        User::createSuperuser($username, $email, $password);
        $this->success('Superuser created successfully.');
        if ($password === null) {
            $this->warn('The account has no usable password. Set one with: php manage.php changepassword ' . $username);
        }

        return self::SUCCESS;
    }

    private function usernameProblem(string $username): ?string
    {
        try {
            User::validateUsername($username);
        } catch (ValidationError $e) {
            return $e->getMessage();
        }
        if (strlen($username) > 150) {
            return 'Ensure the username has at most 150 characters.';
        }
        if (User::objects()->filter(username: $username)->exists()) {
            return 'That username is already taken.';
        }

        return null;
    }

    /**
     * @return string|null|false the password, null for none, false to stop
     */
    private function environmentPassword(): string|null|false
    {
        $password = getenv('BREWPHP_SUPERUSER_PASSWORD');
        if (!is_string($password) || $password === '') {
            return null;
        }
        try {
            Passwords::validate($password);
        } catch (ValidationError $e) {
            foreach ($e->messages() as $message) {
                $this->error($message);
            }

            return false;
        }

        return $password;
    }

    private function askPassword(string $username, string $email): string|false
    {
        for ($try = 0; $try < 3; $try++) {
            $password = (string) $this->secret('Password');
            if ($password === '') {
                $this->error('Blank passwords are not allowed.');
                continue;
            }
            if ($password !== (string) $this->secret('Password (again)')) {
                $this->error('The passwords didn’t match.');
                continue;
            }
            try {
                Passwords::validate($password, new User(['username' => $username, 'email' => $email]));
            } catch (ValidationError $e) {
                foreach ($e->messages() as $message) {
                    $this->error($message);
                }
                if (!$this->confirm('Bypass password validation and create the user anyway?')) {
                    continue;
                }
            }

            return $password;
        }
        $this->error('Too many attempts; no user was created.');

        return false;
    }
}
