<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

/**
 * An error while rendering a template, with the template name and line
 * when they are known. Errors raised inside a compiled template (an
 * unknown URL name, a failing filter...) are wrapped in one, so the debug
 * page can point at the template line instead of the compiled PHP file.
 *
 * @phpstan-consistent-constructor
 */
class TemplateError extends \RuntimeException
{
    public ?string $templateName = null;

    public ?int $templateLine = null;

    /** Absolute path of the template source, null for string templates. */
    public ?string $templateFile = null;

    private string $rawMessage;

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->rawMessage = $message;
    }

    /**
     * An error at a known line; the engine adds the template name.
     */
    public static function atLine(string $message, int $line): static
    {
        $error = new static($message);
        $error->templateLine = $line;

        return $error;
    }

    /**
     * Record where the error happened and add it to the message.
     */
    public function setLocation(string $name, ?int $line, ?string $file = null): static
    {
        $this->templateName = $name;
        $this->templateLine = $line;
        $this->templateFile ??= $file;
        $this->message = $line === null
            ? sprintf('%s (in template "%s")', $this->rawMessage, $name)
            : sprintf('%s (in template "%s", line %d)', $this->rawMessage, $name, $line);

        return $this;
    }

    /**
     * The message without the location.
     */
    public function rawMessage(): string
    {
        return $this->rawMessage;
    }
}
