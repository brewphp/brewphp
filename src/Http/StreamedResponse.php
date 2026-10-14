<?php

declare(strict_types=1);

namespace BrewPHP\Http;

/**
 * A response whose body is produced by a callback while it is sent, for
 * large downloads or CSV exports that should not be built in memory.
 *
 *     return new StreamedResponse(function (): void {
 *         foreach ($rows as $row) {
 *             echo implode(',', $row), "\n";
 *         }
 *     }, headers: ['Content-Type' => 'text/csv']);
 */
class StreamedResponse extends Response
{
    /** @var \Closure(): void */
    private \Closure $callback;

    /**
     * @param callable(): void $callback
     * @param array<string, string> $headers
     */
    public function __construct(callable $callback, int $status = 200, array $headers = [])
    {
        parent::__construct('', $status, $headers);
        $this->callback = \Closure::fromCallable($callback);
    }

    /**
     * Run the callback and return what it printed (useful in tests).
     */
    public function content(): string
    {
        ob_start();
        try {
            ($this->callback)();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    protected function sendContent(): void
    {
        ($this->callback)();
        flush();
    }
}
