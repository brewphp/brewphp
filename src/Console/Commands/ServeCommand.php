<?php

declare(strict_types=1);

namespace BrewPHP\Console\Commands;

use BrewPHP\Console\Command;
use BrewPHP\Core\SystemChecks;

final class ServeCommand extends Command
{
    public string $name = 'serve';

    public string $description = 'Start the development server';

    public string $help = "Runs PHP's built-in web server. It is for development only:\nnever use it in production.";

    /** @var list<string> */
    public array $aliases = ['runserver'];

    protected function configure(): void
    {
        $this->addArgument('addrport', 'Address and/or port, e.g. 8080 or 0.0.0.0:8000', default: '127.0.0.1:8000');
        $this->addOption('skip-checks', 'Do not run the system checks first');
    }

    public function handle(): int
    {
        try {
            [$host, $port] = self::parseAddress((string) $this->argument('addrport'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $public = $this->app->basePath('public');
        if (!is_file($public . DIRECTORY_SEPARATOR . 'index.php')) {
            $this->error(sprintf('No front controller found at %s.', $public . DIRECTORY_SEPARATOR . 'index.php'));

            return self::FAILURE;
        }

        if (!$this->option('skip-checks')) {
            $this->comment('Performing system checks...');
            $issues = $this->app->get(SystemChecks::class)->run($this->app);
            foreach ($issues as $issue) {
                $issue->isError() ? $this->error("[{$issue->id}] {$issue->message}") : $this->warn("[{$issue->id}] {$issue->message}");
            }
            $this->line(sprintf('System check identified %s.', $issues === [] ? 'no issues' : count($issues) . ' issue(s)'));
            $this->line();
        }

        $connection = @fsockopen($host === '0.0.0.0' ? '127.0.0.1' : $host, $port, $errno, $errstr, 0.2);
        if ($connection !== false) {
            fclose($connection);
            $this->error(sprintf('Port %d is already in use. Try another one: php manage.php serve %d', $port, $port + 1));

            return self::FAILURE;
        }

        $display = $host === '0.0.0.0' ? '127.0.0.1' : $host;
        $this->line(sprintf('BrewPHP development server at %s', $this->output->style("http://$display:$port/", 'green', 'bold')));
        $this->comment('Quit the server with CTRL-C.');
        $this->line();

        $commandLine = self::commandLine($host, $port, $public, $this->app->resourcePath('server.php'));
        $process = proc_open($commandLine, [STDIN, STDOUT, STDERR], $pipes, $this->app->basePath());
        if (!is_resource($process)) {
            $this->error('Could not start the PHP built-in server.');

            return self::FAILURE;
        }

        return proc_close($process) === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The command line that starts PHP's built-in server.
     *
     * @return list<string>
     */
    public static function commandLine(string $host, int $port, string $documentRoot, string $routerScript): array
    {
        return [
            PHP_BINARY,
            '-S',
            (str_contains($host, ':') ? "[$host]" : $host) . ':' . $port,
            '-t',
            $documentRoot,
            $routerScript,
        ];
    }

    /**
     * Parse "8000", "0.0.0.0:8000", "localhost" or "[::1]:8000".
     *
     * @return array{0: string, 1: int}
     */
    public static function parseAddress(string $address): array
    {
        $host = '127.0.0.1';
        $port = 8000;

        if (ctype_digit($address)) {
            $port = (int) $address;
        } elseif (preg_match('/^\[([0-9a-f:]+)\](?::(\d+))?$/i', $address, $m)) {
            $host = $m[1];
            $port = isset($m[2]) ? (int) $m[2] : $port;
        } elseif (preg_match('/^([a-z0-9.-]+)(?::(\d+))?$/i', $address, $m)) {
            $host = $m[1];
            $port = isset($m[2]) ? (int) $m[2] : $port;
        } else {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid address. Use e.g. 8080 or 0.0.0.0:8000.', $address));
        }

        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(sprintf('Port %d is out of range (1-65535).', $port));
        }

        return [$host, $port];
    }
}
