<?php

declare(strict_types=1);

namespace BrewPHP\Console;

use BrewPHP\Core\Application;

/**
 * Base class for `manage.php` commands.
 *
 *     final class GreetCommand extends Command
 *     {
 *         public string $name = 'greet';
 *         public string $description = 'Say hello';
 *
 *         protected function configure(): void
 *         {
 *             $this->addArgument('who', 'Who to greet', default: 'world');
 *             $this->addOption('shout', 'Print in capitals', shortcut: 's');
 *         }
 *
 *         public function handle(): int
 *         {
 *             $text = 'Hello, ' . $this->argument('who');
 *             $this->info($this->option('shout') ? strtoupper($text) : $text);
 *
 *             return self::SUCCESS;
 *         }
 *     }
 */
abstract class Command
{
    public const SUCCESS = 0;
    public const FAILURE = 1;
    public const INVALID = 2;

    /** The command name, e.g. "make:command". */
    public string $name = '';

    /** One line shown in the command list. */
    public string $description = '';

    /** Longer text shown by `--help`. */
    public string $help = '';

    /** @var list<string> other names for the command */
    public array $aliases = [];

    protected Application $app;

    protected Input $input;

    protected Output $output;

    /** @var array<string, array{description: string, required: bool, default: mixed}> */
    private array $argumentDefinitions = [];

    /** @var array<string, array{description: string, shortcut: ?string, value: bool, default: mixed}> */
    private array $optionDefinitions = [];

    private bool $configured = false;

    /**
     * The work of the command. Return an exit code (self::SUCCESS on success).
     */
    abstract public function handle(): int;

    /**
     * Declare arguments and options here with addArgument() and addOption().
     */
    protected function configure(): void
    {
    }

    public function run(Application $app, Input $input, Output $output): int
    {
        $this->app = $app;
        $this->input = $input;
        $this->output = $output;
        $input->bind($this->arguments(), $this->options());

        return $this->handle();
    }

    /**
     * @return array<string, array{description: string, required: bool, default: mixed}>
     */
    public function arguments(): array
    {
        $this->ensureConfigured();

        return $this->argumentDefinitions;
    }

    /**
     * @return array<string, array{description: string, shortcut: ?string, value: bool, default: mixed}>
     */
    public function options(): array
    {
        $this->ensureConfigured();

        return $this->optionDefinitions;
    }

    protected function addArgument(string $name, string $description = '', bool $required = false, mixed $default = null): static
    {
        $this->argumentDefinitions[$name] = ['description' => $description, 'required' => $required, 'default' => $default];

        return $this;
    }

    /**
     * Add an option. Without `value: true` it is a flag (true when given).
     */
    protected function addOption(string $name, string $description = '', ?string $shortcut = null, bool $value = false, mixed $default = null): static
    {
        $this->optionDefinitions[$name] = [
            'description' => $description,
            'shortcut' => $shortcut,
            'value' => $value,
            'default' => $default ?? ($value ? null : false),
        ];

        return $this;
    }

    protected function argument(string $name): mixed
    {
        return $this->input->argument($name);
    }

    protected function option(string $name): mixed
    {
        return $this->input->option($name);
    }

    // Output -----------------------------------------------------------------

    protected function line(string $text = ''): void
    {
        $this->output->writeln($text);
    }

    protected function info(string $text): void
    {
        $this->output->writeln($this->output->style($text, 'cyan'));
    }

    protected function success(string $text): void
    {
        $this->output->writeln($this->output->style('✔ ', 'green') . $text);
    }

    protected function comment(string $text): void
    {
        $this->output->writeln($this->output->style($text, 'gray'));
    }

    protected function warn(string $text): void
    {
        $this->output->writeln($this->output->style('! ' . $text, 'yellow'));
    }

    protected function error(string $text): void
    {
        $this->output->writeError($this->output->style('✘ ' . $text, 'red'));
    }

    /**
     * A section title.
     */
    protected function title(string $text): void
    {
        $this->output->writeln($this->output->style($text, 'bold', 'yellow'));
    }

    /**
     * Print rows as an aligned table.
     *
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    protected function table(array $headers, array $rows): void
    {
        $widths = array_map(static fn (string $h): int => Output::width($h), $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, Output::width($cell));
            }
        }

        $pad = static fn (string $text, int $width): string => $text . str_repeat(' ', max(0, $width - Output::width($text)));
        $border = '+' . implode('+', array_map(static fn (int $w): string => str_repeat('-', $w + 2), $widths)) . '+';

        $this->line($border);
        $this->line('| ' . implode(' | ', array_map(fn (string $h, int $w): string => $this->output->style($pad($h, $w), 'bold'), $headers, $widths)) . ' |');
        $this->line($border);
        foreach ($rows as $row) {
            $cells = [];
            foreach ($widths as $i => $width) {
                $cells[] = $pad($row[$i] ?? '', $width);
            }
            $this->line('| ' . implode(' | ', $cells) . ' |');
        }
        $this->line($border);
    }

    /**
     * Print "label ........ value" lines, like `about`.
     *
     * @param array<string, string> $pairs
     */
    protected function definitionList(array $pairs, int $width = 60): void
    {
        foreach ($pairs as $label => $value) {
            $dots = max(2, $width - Output::width($label) - Output::width($value) - 2);
            $this->line('  ' . $label . ' ' . $this->output->style(str_repeat('.', $dots), 'gray') . ' ' . $value);
        }
    }

    // Interaction ------------------------------------------------------------

    /**
     * Ask a question. Without interaction (`--no-interaction`) the default
     * is returned.
     */
    protected function ask(string $question, ?string $default = null): ?string
    {
        if (!$this->input->isInteractive()) {
            return $default;
        }
        $this->output->write($this->output->style($question, 'green') . ($default !== null ? " [$default]" : '') . ': ');
        $answer = $this->input->readLine();

        return $answer === null || $answer === '' ? $default : $answer;
    }

    protected function secret(string $question): ?string
    {
        if (!$this->input->isInteractive()) {
            return null;
        }
        $this->output->write($this->output->style($question, 'green') . ': ');

        return $this->input->readSecret();
    }

    protected function confirm(string $question, bool $default = false): bool
    {
        if (!$this->input->isInteractive()) {
            return $default;
        }
        $this->output->write($this->output->style($question, 'green') . ($default ? ' [Y/n]' : ' [y/N]') . ': ');
        $answer = strtolower(trim((string) $this->input->readLine()));

        return $answer === '' ? $default : in_array($answer, ['y', 'yes'], true);
    }

    /**
     * Ask the user to pick one of $choices; returns the chosen value.
     *
     * @param list<string> $choices
     */
    protected function choice(string $question, array $choices, ?string $default = null): ?string
    {
        if (!$this->input->isInteractive() || $choices === []) {
            return $default;
        }
        $this->output->writeln($this->output->style($question, 'green'));
        foreach ($choices as $i => $choice) {
            $this->line(sprintf('  [%d] %s', $i + 1, $choice));
        }
        while (true) {
            $answer = $this->ask('Choose a number', $default !== null ? (string) (array_search($default, $choices, true) + 1) : null);
            if ($answer === null) {
                return $default;
            }
            if (ctype_digit($answer) && isset($choices[(int) $answer - 1])) {
                return $choices[(int) $answer - 1];
            }
            if (in_array($answer, $choices, true)) {
                return $answer;
            }
            $this->warn('Please choose one of the listed numbers.');
        }
    }

    private function ensureConfigured(): void
    {
        if (!$this->configured) {
            $this->configured = true;
            $this->configure();
        }
    }
}
