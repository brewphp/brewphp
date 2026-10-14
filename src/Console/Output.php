<?php

declare(strict_types=1);

namespace BrewPHP\Console;

/**
 * Writes text to the terminal, with colours when the terminal supports
 * them (and NO_COLOR is not set).
 */
final class Output
{
    private const STYLES = [
        'bold' => '1', 'dim' => '2',
        'red' => '31', 'green' => '32', 'yellow' => '33', 'blue' => '34',
        'magenta' => '35', 'cyan' => '36', 'gray' => '90',
    ];

    /** @var resource */
    private $stream;

    /** @var resource */
    private $errorStream;

    private bool $decorated;

    /**
     * @param resource|null $stream
     * @param resource|null $errorStream
     */
    public function __construct($stream = null, $errorStream = null, ?bool $decorated = null)
    {
        $this->stream = $stream ?? (defined('STDOUT') ? STDOUT : self::open('php://stdout'));
        $this->errorStream = $errorStream ?? (defined('STDERR') ? STDERR : self::open('php://stderr'));
        $this->decorated = $decorated ?? self::supportsColor($this->stream);
    }

    /**
     * An output that keeps everything in memory; read it with fetch().
     */
    public static function buffered(bool $decorated = false): self
    {
        $stream = self::open('php://memory');

        return new self($stream, $stream, $decorated);
    }

    public function setDecorated(bool $decorated): void
    {
        $this->decorated = $decorated;
    }

    public function isDecorated(): bool
    {
        return $this->decorated;
    }

    public function write(string $text): void
    {
        fwrite($this->stream, $text);
    }

    public function writeln(string $text = ''): void
    {
        $this->write($text . PHP_EOL);
    }

    public function writeError(string $text): void
    {
        fwrite($this->errorStream, $text . PHP_EOL);
    }

    /**
     * Wrap text in ANSI styles: `style('Done', 'green', 'bold')`.
     */
    public function style(string $text, string ...$styles): string
    {
        if (!$this->decorated || $styles === []) {
            return $text;
        }
        $codes = array_map(static fn (string $s): string => self::STYLES[$s] ?? '0', $styles);

        return "\033[" . implode(';', $codes) . 'm' . $text . "\033[0m";
    }

    /**
     * Everything written so far (only for buffered outputs).
     */
    public function fetch(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    /**
     * Visible length of a string, ignoring ANSI codes.
     */
    public static function width(string $text): int
    {
        $plain = (string) preg_replace('/\033\[[0-9;]*m/', '', $text);

        return function_exists('mb_strwidth') ? mb_strwidth($plain) : strlen($plain);
    }

    /**
     * @param resource $stream
     */
    private static function supportsColor($stream): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }
        if (getenv('FORCE_COLOR') !== false) {
            return true;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            return function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support($stream);
        }

        return function_exists('stream_isatty') && @stream_isatty($stream);
    }

    /**
     * @return resource
     */
    private static function open(string $uri)
    {
        $stream = fopen($uri, $uri === 'php://memory' ? 'w+' : 'w');
        if ($stream === false) {
            throw new \RuntimeException(sprintf('Cannot open %s.', $uri));
        }

        return $stream;
    }
}
