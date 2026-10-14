<?php

declare(strict_types=1);

namespace BrewPHP\Middleware;

use BrewPHP\Core\Settings;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use Closure;

/**
 * Adds security-related headers to every response. Each header is driven
 * by a setting, and a header the view already set is left alone.
 *
 * | Setting                            | Header                       | Default       |
 * |------------------------------------|------------------------------|---------------|
 * | SECURE_CONTENT_TYPE_NOSNIFF        | X-Content-Type-Options       | nosniff       |
 * | SECURE_FRAME_OPTIONS               | X-Frame-Options              | DENY          |
 * | SECURE_REFERRER_POLICY             | Referrer-Policy              | same-origin   |
 * | SECURE_CROSS_ORIGIN_OPENER_POLICY  | Cross-Origin-Opener-Policy   | same-origin   |
 * | SECURE_HSTS_SECONDS (+ options)    | Strict-Transport-Security    | off (0)       |
 * | SECURE_CSP                         | Content-Security-Policy      | off (null)    |
 */
final class SecurityHeadersMiddleware implements Middleware
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function process(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->settings->bool('SECURE_CONTENT_TYPE_NOSNIFF')) {
            $this->add($response, 'X-Content-Type-Options', 'nosniff');
        }
        $this->add($response, 'X-Frame-Options', $this->settings->string('SECURE_FRAME_OPTIONS'));
        $this->add($response, 'Referrer-Policy', $this->settings->string('SECURE_REFERRER_POLICY'));
        $this->add($response, 'Cross-Origin-Opener-Policy', $this->settings->string('SECURE_CROSS_ORIGIN_OPENER_POLICY'));
        $this->add($response, 'Content-Security-Policy', $this->settings->string('SECURE_CSP'));

        $seconds = (int) $this->settings->get('SECURE_HSTS_SECONDS', 0);
        if ($seconds > 0 && $request->isSecure()) {
            $value = 'max-age=' . $seconds;
            if ($this->settings->bool('SECURE_HSTS_INCLUDE_SUBDOMAINS')) {
                $value .= '; includeSubDomains';
            }
            if ($this->settings->bool('SECURE_HSTS_PRELOAD')) {
                $value .= '; preload';
            }
            $this->add($response, 'Strict-Transport-Security', $value);
        }

        return $response;
    }

    private function add(Response $response, string $name, string $value): void
    {
        if ($value !== '' && !$response->hasHeader($name)) {
            $response->header($name, $value);
        }
    }
}
