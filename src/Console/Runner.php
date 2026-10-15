<?php

declare(strict_types=1);

namespace BrewPHP\Console;

use BrewPHP\Core\Application;
use BrewPHP\Core\ImproperlyConfigured;

/**
 * Runs `manage.php` commands.
 *
 * Commands come from three places: the framework, each installed app's
 * `Commands/` folder (any non-abstract Command subclass there), and the
 * COMMANDS setting (a list of class names).
 *
 * Global options, accepted by every command:
 *   -h, --help            show help for the command
 *   -n, --no-interaction  never ask questions (use defaults), for CI
 *   --ansi / --no-ansi    force or disable colours
 *   -v, --verbose         show stack traces for errors
 *   -V, --version         show the BrewPHP version
 */
final class Runner
{
    /** The framework's own commands. */
    public const BUILTIN = [
        Commands\AboutCommand::class,
        Commands\CheckCommand::class,
        Commands\KeyGenerateCommand::class,
        Commands\MakeCommandCommand::class,
        Commands\MakeMigrationsCommand::class,
        Commands\MigrateCommand::class,
        Commands\RoutesCommand::class,
        Commands\ServeCommand::class,
        Commands\ShowMigrationsCommand::class,
        Commands\SqlMigrateCommand::class,
        Commands\StartAppCommand::class,
    ];

    /** @var array<string, Command> */
    private array $commands = [];

    /** @var array<string, string> alias => name */
    private array $aliases = [];

    private bool $discovered = false;

    public function __construct(private readonly Application $app)
    {
    }

    public function add(Command $command): void
    {
        if ($command->name === '') {
            throw new ImproperlyConfigured(sprintf('Command %s has no $name.', $command::class));
        }
        $this->commands[$command->name] = $command;
        foreach ($command->aliases as $alias) {
            $this->aliases[$alias] = $command->name;
        }
    }

    /**
     * @return array<string, Command>
     */
    public function all(): array
    {
        $this->discover();
        ksort($this->commands);

        return $this->commands;
    }

    public function find(string $name): Command
    {
        $this->discover();
        $name = $this->aliases[$name] ?? $name;
        if (isset($this->commands[$name])) {
            return $this->commands[$name];
        }

        $suggestions = array_filter(
            array_keys($this->commands),
            static fn (string $candidate): bool => levenshtein($name, $candidate) <= 3 || str_contains($candidate, $name),
        );

        throw new ConsoleException(sprintf(
            'Command "%s" is not defined.%s',
            $name,
            $suggestions !== [] ? ' Did you mean: ' . implode(', ', $suggestions) . '?' : ' Run "php manage.php list" to see all commands.',
        ));
    }

    /**
     * @param list<string> $argv the full argv, starting with the script name
     * @param resource|null $stdin
     */
    public function run(array $argv, ?Output $output = null, $stdin = null): int
    {
        $output ??= new Output();
        $tokens = array_slice($argv, 1);

        $interactive = true;
        $help = false;
        $verbose = false;
        $version = false;
        $rest = [];
        foreach ($tokens as $i => $token) {
            if ($token === '--') {
                array_push($rest, ...array_slice($tokens, $i));
                break;
            }
            match ($token) {
                '-n', '--no-interaction' => $interactive = false,
                '-h', '--help' => $help = true,
                '--ansi' => $output->setDecorated(true),
                '--no-ansi' => $output->setDecorated(false),
                '-v', '--verbose' => $verbose = true,
                '-V', '--version' => $version = true,
                default => $rest[] = $token,
            };
        }
        if ($version) {
            $output->writeln('BrewPHP ' . Application::VERSION);

            return Command::SUCCESS;
        }

        $name = array_shift($rest) ?? 'list';

        try {
            if ($name === 'list') {
                $this->printList($output);

                return Command::SUCCESS;
            }
            if ($name === 'help') {
                $help = true;
                $name = array_shift($rest) ?? 'list';
                if ($name === 'list') {
                    $this->printList($output);

                    return Command::SUCCESS;
                }
            }

            $command = $this->find($name);
            if ($help) {
                $this->printHelp($command, $output);

                return Command::SUCCESS;
            }

            return $command->run($this->app, new Input($rest, $interactive, $stdin), $output);
        } catch (ConsoleException $e) {
            $output->writeError($output->style('✘ ' . $e->getMessage(), 'red'));

            return Command::INVALID;
        } catch (\Throwable $e) {
            $output->writeError($output->style(sprintf('✘ %s: %s', $e::class, $e->getMessage()), 'red'));
            if ($verbose) {
                $output->writeError($e->getTraceAsString());
            } else {
                $output->writeError($output->style('  Run again with -v to see the stack trace.', 'gray'));
            }

            return Command::FAILURE;
        }
    }

    private function discover(): void
    {
        if ($this->discovered) {
            return;
        }
        $this->discovered = true;

        $classes = self::BUILTIN;
        foreach ($this->app->apps() as $app) {
            foreach (glob($app->commandsPath() . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                $classes[] = $app->namespace . '\\Commands\\' . basename($file, '.php');
            }
        }
        foreach ($this->app->settings()->array('COMMANDS') as $class) {
            if (is_string($class)) {
                $classes[] = $class;
            }
        }

        foreach ($classes as $class) {
            if (!class_exists($class) || !is_subclass_of($class, Command::class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $this->add($this->app->make($class));
        }
    }

    private function printList(Output $output): void
    {
        $output->writeln($output->style('BrewPHP', 'bold', 'yellow') . ' ' . Application::VERSION);
        $output->writeln();
        $output->writeln($output->style('Usage:', 'yellow'));
        $output->writeln('  php manage.php <command> [options] [arguments]');
        $output->writeln();
        $output->writeln($output->style('Global options:', 'yellow'));
        foreach (self::globalOptions() as $option => $description) {
            $output->writeln('  ' . $output->style(str_pad($option, 24), 'green') . $description);
        }
        $output->writeln();
        $output->writeln($output->style('Available commands:', 'yellow'));

        $commands = $this->all();
        $width = max(array_map('strlen', array_keys($commands)) ?: [10]) + 2;
        $groups = [];
        foreach ($commands as $name => $command) {
            $group = str_contains($name, ':') ? strstr($name, ':', true) : '';
            $groups[$group][$name] = $command;
        }
        ksort($groups);
        foreach ($groups as $group => $members) {
            if ($group !== '') {
                // Numeric group names become int array keys.
                $output->writeln(' ' . $output->style((string) $group, 'yellow'));
            }
            foreach ($members as $name => $command) {
                $alias = $command->aliases !== [] ? ' ' . $output->style('[' . implode(', ', $command->aliases) . ']', 'gray') : '';
                $output->writeln('  ' . $output->style(str_pad($name, $width), 'green') . $command->description . $alias);
            }
        }
    }

    private function printHelp(Command $command, Output $output): void
    {
        $usage = 'php manage.php ' . $command->name;
        if ($command->options() !== []) {
            $usage .= ' [options]';
        }
        foreach ($command->arguments() as $name => $argument) {
            $usage .= match (true) {
                $argument['array'] => $argument['required'] ? " <$name>..." : " [<$name>...]",
                default => $argument['required'] ? " <$name>" : " [<$name>]",
            };
        }

        $output->writeln($output->style('Description:', 'yellow'));
        $output->writeln('  ' . $command->description);
        $output->writeln();
        $output->writeln($output->style('Usage:', 'yellow'));
        $output->writeln('  ' . $usage);
        if ($command->aliases !== []) {
            $output->writeln('  Aliases: ' . implode(', ', $command->aliases));
        }

        if ($command->arguments() !== []) {
            $output->writeln();
            $output->writeln($output->style('Arguments:', 'yellow'));
            foreach ($command->arguments() as $name => $argument) {
                $default = $argument['default'] !== null && $argument['default'] !== [] ? $output->style(' [default: ' . self::export($argument['default']) . ']', 'yellow') : '';
                $output->writeln('  ' . $output->style(str_pad($name, 24), 'green') . $argument['description'] . $default);
            }
        }

        $output->writeln();
        $output->writeln($output->style('Options:', 'yellow'));
        foreach ($command->options() as $name => $option) {
            $label = ($option['shortcut'] !== null ? '-' . $option['shortcut'] . ', ' : '    ') . '--' . $name . ($option['value'] ? '=VALUE' : '');
            $default = $option['value'] && $option['default'] !== null ? $output->style(' [default: ' . self::export($option['default']) . ']', 'yellow') : '';
            $output->writeln('  ' . $output->style(str_pad($label, 24), 'green') . $option['description'] . $default);
        }
        foreach (self::globalOptions() as $option => $description) {
            $output->writeln('  ' . $output->style(str_pad($option, 24), 'green') . $description);
        }

        if ($command->help !== '') {
            $output->writeln();
            $output->writeln($output->style('Help:', 'yellow'));
            foreach (explode("\n", $command->help) as $line) {
                $output->writeln('  ' . $line);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function globalOptions(): array
    {
        return [
            '-h, --help' => 'Show help for a command',
            '-n, --no-interaction' => 'Do not ask any questions',
            '    --ansi|--no-ansi' => 'Force (or disable) coloured output',
            '-v, --verbose' => 'Show stack traces for errors',
            '-V, --version' => 'Show the BrewPHP version',
        ];
    }

    private static function export(mixed $value): string
    {
        return is_scalar($value) ? var_export($value, true) : (string) json_encode($value);
    }
}
