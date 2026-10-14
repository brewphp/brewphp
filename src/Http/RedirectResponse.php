<?php

declare(strict_types=1);

namespace BrewPHP\Http;

/**
 * Redirect the browser to another URL (302 Found by default).
 */
class RedirectResponse extends Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(private string $url, int $status = 302, array $headers = [])
    {
        if ($url === '') {
            throw new \InvalidArgumentException('Cannot redirect to an empty URL.');
        }
        if ($status < 300 || $status > 399) {
            throw new \InvalidArgumentException(sprintf('A redirect needs a 3xx status, got %d.', $status));
        }

        $escaped = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        parent::__construct(
            sprintf('<!doctype html><meta charset="utf-8"><title>Redirecting</title><p>Redirecting to <a href="%s">%s</a>.</p>', $escaped, $escaped),
            $status,
            $headers,
        );
        $this->header('Location', $url);
    }

    public function targetUrl(): string
    {
        return $this->url;
    }
}
