<?php

declare(strict_types=1);

namespace BrewPHP\Core;

use BrewPHP\Http\HttpException;
use BrewPHP\Http\JsonResponse;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Routing\RouteNotFound;

/**
 * Turns exceptions into error responses.
 *
 * With DEBUG on you get a detailed page (stack trace, request, settings).
 * With DEBUG off you get a plain page from the `404.php`, `403.php`,
 * `400.php` or `500.php` template, which a project overrides by creating a
 * template with the same name; server errors are written to the PHP error
 * log. Requests that ask for JSON get a JSON error instead.
 */
final class ErrorHandler
{
    private const SENSITIVE = '/SECRET|PASSWORD|PASS|KEY|TOKEN|SIGNATURE|AUTH|COOKIE|CREDENTIAL|PRIVATE/i';

    private const TITLES = [
        400 => 'Bad request',
        403 => 'Permission denied',
        404 => 'Page not found',
        405 => 'Method not allowed',
        500 => 'Server error',
    ];

    public function __construct(private readonly Application $app)
    {
    }

    public function render(\Throwable $e, Request $request): Response
    {
        $status = $e instanceof HttpException ? $e->status() : 500;
        $headers = $e instanceof HttpException ? $e->headers() : [];

        if ($status >= 500) {
            error_log(sprintf('[BrewPHP] %s %s: %s', $request->method(), $request->path(), (string) $e));
        }

        try {
            $response = match (true) {
                $request->wantsJson() => $this->json($e, $status),
                $this->app->isDebug() => new Response($this->debugPage($e, $request, $status), $status),
                default => new Response($this->productionPage($e, $status), $status),
            };
        } catch (\Throwable $renderError) {
            error_log('[BrewPHP] Error while rendering the error page: ' . $renderError);
            $response = new Response(sprintf('<h1>%d %s</h1>', $status, self::TITLES[$status] ?? 'Error'), $status);
        }

        foreach ($headers as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }

    /**
     * Convert PHP warnings and notices into exceptions and catch fatal
     * errors, for web requests.
     */
    public static function register(Application $app): void
    {
        ini_set('display_errors', $app->isDebug() ? '1' : '0');
        self::convertErrors();

        set_exception_handler(static function (\Throwable $e) use ($app): void {
            (new self($app))->render($e, Request::fromGlobals())->send();
        });

        register_shutdown_function(static function () use ($app): void {
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $exception = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
            (new self($app))->render($exception, Request::fromGlobals())->send();
        });
    }

    public static function registerForConsole(): void
    {
        self::convertErrors();
    }

    /**
     * Replace the values of settings whose names look secret.
     *
     * @param array<mixed> $values
     * @return array<mixed>
     */
    public static function mask(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key)) {
                $values[$key] = '********************';
            } elseif (is_array($value)) {
                $values[$key] = self::mask($value);
            }
        }

        return $values;
    }

    private static function convertErrors(): void
    {
        set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
            if ((error_reporting() & $level) === 0) {
                return false;
            }
            throw new \ErrorException($message, 0, $level, $file, $line);
        });
    }

    /**
     * The message that is safe to show without DEBUG: the text of a 4xx
     * HttpException raised on purpose. Router internals and server errors
     * are never shown.
     */
    private static function publicMessage(\Throwable $e, int $status): string
    {
        if (!$e instanceof HttpException || $e instanceof RouteNotFound || $status >= 500) {
            return '';
        }

        return $e->getMessage();
    }

    private function json(\Throwable $e, int $status): JsonResponse
    {
        $message = self::publicMessage($e, $status);
        $data = ['error' => $message !== '' ? $message : (self::TITLES[$status] ?? 'Error'), 'status' => $status];
        if ($this->app->isDebug()) {
            $data['exception'] = [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 20),
            ];
        }

        return new JsonResponse($data, $status);
    }

    private function productionPage(\Throwable $e, int $status): string
    {
        $message = self::publicMessage($e, $status);

        return $this->app->templates()->render([$status . '.php', 'brewphp/error.php'], [
            'status' => $status,
            'title' => self::TITLES[$status] ?? (Response::STATUS_TEXTS[$status] ?? 'Error'),
            'message' => $message,
        ]);
    }

    private function debugPage(\Throwable $e, Request $request, int $status): string
    {
        if ($e instanceof RouteNotFound) {
            return $this->app->templates()->render('brewphp/debug_404.php', [
                'path' => $e->path,
                'routes' => $e->tried,
                'urlconf' => $this->app->settings()->string('ROOT_URLCONF'),
                'request' => $request,
            ]);
        }

        $exceptions = [];
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $exceptions[] = $current;
        }

        return $this->app->templates()->render('brewphp/debug.php', [
            'status' => $status,
            'exceptions' => $exceptions,
            'frames' => self::frames($e),
            'request' => $request,
            'settings' => self::mask($this->app->settings()->all()),
            'server' => self::mask(array_filter($request->server(), 'is_scalar')),
            'basePath' => $this->app->basePath(),
            'version' => Application::VERSION,
        ]);
    }

    /**
     * Stack frames with a few lines of source code around each line.
     *
     * @return list<array{file: string, line: int, function: string, vendor: bool, code: array<int, string>}>
     */
    private static function frames(\Throwable $e): array
    {
        // Each location is where the code was when the exception happened;
        // the function running there is the one from the next trace entry.
        $trace = $e->getTrace();
        $locations = [[$e->getFile(), $e->getLine(), self::functionName($trace[0] ?? null)]];
        foreach ($trace as $i => $frame) {
            if (isset($frame['file'], $frame['line'])) {
                $locations[] = [$frame['file'], $frame['line'], self::functionName($trace[$i + 1] ?? null)];
            }
        }

        $frames = [];
        foreach ($locations as [$file, $line, $function]) {
            $normalized = str_replace('\\', '/', $file);
            $frames[] = [
                'file' => $file,
                'line' => $line,
                'function' => $function,
                'vendor' => str_contains($normalized, '/vendor/') || str_contains($normalized, '/brewphp/src/'),
                'code' => self::excerpt($file, $line),
            ];
        }

        return $frames;
    }

    /**
     * @param array{function: string, class?: class-string, type?: string}|null $frame
     */
    private static function functionName(?array $frame): string
    {
        if ($frame === null) {
            return '{main}';
        }

        return ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . '()';
    }

    /**
     * @return array<int, string> line number => source line
     */
    private static function excerpt(string $file, int $line, int $around = 6): array
    {
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $start = max(1, $line - $around);
        $excerpt = [];
        for ($n = $start; $n <= min(count($lines), $line + $around); $n++) {
            $excerpt[$n] = $lines[$n - 1];
        }

        return $excerpt;
    }
}
