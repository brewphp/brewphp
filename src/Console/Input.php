<?php

declare(strict_types=1);

namespace BrewPHP\Console;

/**
 * Parsed command-line arguments and options, plus the stream that answers
 * interactive questions.
 *
 * Supported syntax: positional arguments, `--name=value`, `--name value`
 * (for options that take a value), `--flag`, `-s` short flags, and `--` to
 * end option parsing.
 */
final class Input
{
    /** @var array<string, mixed> */
    private array $arguments = [];

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var resource */
    private $stream;

    /**
     * @param list<string> $tokens command-line words after the command name
     * @param resource|null $stream where answers are read from (STDIN by default)
     */
    public function __construct(
        private readonly array $tokens = [],
        private readonly bool $interactive = true,
        $stream = null,
    ) {
        $stream ??= defined('STDIN') ? STDIN : fopen('php://stdin', 'r');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Cannot open standard input.');
        }
        $this->stream = $stream;
    }

    /**
     * A non-interactive input whose questions are answered by $answers.
     *
     * @param list<string> $tokens
     * @param list<string> $answers
     */
    public static function fake(array $tokens = [], array $answers = []): self
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, implode("\n", $answers) . ($answers !== [] ? "\n" : ''));
        rewind($stream);

        return new self($tokens, $answers !== [], $stream);
    }

    /**
     * Match the tokens against a command's definition.
     *
     * @param array<string, array{description: string, required: bool, default: mixed, array: bool}> $arguments
     * @param array<string, array{description: string, shortcut: ?string, value: bool, default: mixed}> $options
     */
    public function bind(array $arguments, array $options): void
    {
        $positional = [];
        $values = [];
        $shortcuts = [];
        foreach ($options as $name => $option) {
            if ($option['shortcut'] !== null) {
                $shortcuts[$option['shortcut']] = $name;
            }
        }

        $tokens = $this->tokens;
        $endOfOptions = false;
        while ($tokens !== []) {
            $token = array_shift($tokens);
            if ($endOfOptions || $token === '-' || !str_starts_with($token, '-')) {
                $positional[] = $token;
            } elseif ($token === '--') {
                $endOfOptions = true;
            } elseif (str_starts_with($token, '--')) {
                [$name, $value] = array_pad(explode('=', substr($token, 2), 2), 2, null);
                if (!isset($options[$name])) {
                    throw new ConsoleException(sprintf('The "--%s" option does not exist.', $name));
                }
                if ($options[$name]['value'] && $value === null) {
                    if ($tokens === [] || str_starts_with($tokens[0], '-')) {
                        throw new ConsoleException(sprintf('The "--%s" option needs a value.', $name));
                    }
                    $value = array_shift($tokens);
                } elseif (!$options[$name]['value'] && $value !== null) {
                    throw new ConsoleException(sprintf('The "--%s" option does not accept a value.', $name));
                }
                $values[$name] = $value ?? true;
            } else {
                foreach (str_split(substr($token, 1)) as $short) {
                    $name = $shortcuts[$short] ?? throw new ConsoleException(sprintf('The "-%s" option does not exist.', $short));
                    if ($options[$name]['value']) {
                        $values[$name] = $tokens !== [] ? array_shift($tokens) : throw new ConsoleException(sprintf('The "-%s" option needs a value.', $short));
                    } else {
                        $values[$name] = true;
                    }
                }
            }
        }

        $names = array_keys($arguments);
        $variadic = $arguments !== [] && end($arguments)['array'];
        if (!$variadic && count($positional) > count($names)) {
            throw new ConsoleException(sprintf(
                'Too many arguments%s.',
                $names === [] ? ': this command takes none' : ', expected: ' . implode(' ', $names),
            ));
        }
        foreach ($arguments as $name => $argument) {
            if ($argument['array']) {
                if ($positional === [] && $argument['required']) {
                    throw new ConsoleException(sprintf('Missing required argument "%s".', $name));
                }
                $this->arguments[$name] = $positional !== [] ? $positional : $argument['default'];
                $positional = [];
                continue;
            }
            $value = array_shift($positional);
            if ($value === null && $argument['required']) {
                throw new ConsoleException(sprintf('Missing required argument "%s".', $name));
            }
            $this->arguments[$name] = $value ?? $argument['default'];
        }
        foreach ($options as $name => $option) {
            $this->options[$name] = $values[$name] ?? $option['default'];
        }
    }

    public function argument(string $name): mixed
    {
        if (!array_key_exists($name, $this->arguments)) {
            throw new ConsoleException(sprintf('The "%s" argument does not exist.', $name));
        }

        return $this->arguments[$name];
    }

    public function option(string $name): mixed
    {
        if (!array_key_exists($name, $this->options)) {
            throw new ConsoleException(sprintf('The "--%s" option does not exist.', $name));
        }

        return $this->options[$name];
    }

    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    /**
     * Read one line of the user's answer, or null at end of input.
     */
    public function readLine(): ?string
    {
        $line = fgets($this->stream);

        return $line === false ? null : rtrim($line, "\r\n");
    }

    /**
     * Read a line without echoing it, when the terminal supports that.
     */
    public function readSecret(): ?string
    {
        // A pipe with buffered data makes stream_isatty() warn; it is not a terminal either way.
        $isTty = function_exists('stream_isatty') && @stream_isatty($this->stream);
        if ($isTty && DIRECTORY_SEPARATOR === '/') {
            shell_exec('stty -echo');
            try {
                return $this->readLine();
            } finally {
                shell_exec('stty echo');
                echo PHP_EOL;
            }
        }

        return $this->readLine();
    }
}
