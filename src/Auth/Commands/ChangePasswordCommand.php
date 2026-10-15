<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Commands;

use BrewPHP\Auth\Models\User;
use BrewPHP\Auth\Passwords;
use BrewPHP\Console\Command;
use BrewPHP\Core\ValidationError;

final class ChangePasswordCommand extends Command
{
    public string $name = 'changepassword';

    public string $description = 'Change the password of a user';

    protected function configure(): void
    {
        $this->addArgument('username', 'The user whose password to change', required: true);
    }

    public function handle(): int
    {
        $username = (string) $this->argument('username');
        $user = User::objects()->filter(username: $username)->first();
        if (!$user instanceof User) {
            $this->error(sprintf('User "%s" does not exist.', $username));

            return self::FAILURE;
        }
        if (!$this->input->isInteractive()) {
            $this->error('changepassword asks for the password; it cannot run with --no-interaction.');

            return self::INVALID;
        }
        $this->line(sprintf('Changing password for user "%s"', $username));
        for ($try = 0; $try < 3; $try++) {
            $password = (string) $this->secret('Password');
            if ($password !== (string) $this->secret('Password (again)')) {
                $this->error('Passwords do not match. Please try again.');
                continue;
            }
            try {
                Passwords::validate($password, $user);
            } catch (ValidationError $e) {
                foreach ($e->messages() as $message) {
                    $this->error($message);
                }
                continue;
            }
            $user->setPassword($password);
            $user->save();
            $this->success(sprintf('Password changed successfully for user "%s".', $username));

            return self::SUCCESS;
        }
        $this->error('Too many attempts; the password was not changed.');

        return self::FAILURE;
    }
}
