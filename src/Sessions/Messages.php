<?php

declare(strict_types=1);

namespace BrewPHP\Sessions;

use BrewPHP\Http\Request;

/**
 * One-time notifications shown on the next page, like Django's messages
 * framework. They are kept in the session until they are displayed.
 *
 *     Messages::success($request, 'The post was saved.');
 *     return redirect('blog:index');
 *
 * With the `messages` context processor, templates loop over them (which
 * removes them):
 *
 *     {% for message in messages %}
 *       <p class="{{ message.tags }}">{{ message }}</p>
 *     {% endfor %}
 *
 * @implements \IteratorAggregate<int, Message>
 */
final class Messages implements \IteratorAggregate, \Countable
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const SUCCESS = 'success';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    private const KEY = '_messages';

    /** @var list<Message>|null */
    private ?array $loaded = null;

    /**
     * The messages of a request, read lazily (and removed) on first use.
     */
    public function __construct(private readonly Request $request)
    {
    }

    public static function add(Request $request, string $level, string $message): void
    {
        $session = $request->session();
        $messages = $session->get(self::KEY, []);
        $messages = is_array($messages) ? array_values($messages) : [];
        $messages[] = ['level' => $level, 'message' => $message];
        $session->set(self::KEY, $messages);
    }

    public static function debug(Request $request, string $message): void
    {
        self::add($request, self::DEBUG, $message);
    }

    public static function info(Request $request, string $message): void
    {
        self::add($request, self::INFO, $message);
    }

    public static function success(Request $request, string $message): void
    {
        self::add($request, self::SUCCESS, $message);
    }

    public static function warning(Request $request, string $message): void
    {
        self::add($request, self::WARNING, $message);
    }

    public static function error(Request $request, string $message): void
    {
        self::add($request, self::ERROR, $message);
    }

    /**
     * Take the pending messages out of the session.
     *
     * @return list<Message>
     */
    public static function pull(Request $request): array
    {
        if (!$request->hasSession() || !$request->session()->has(self::KEY)) {
            return [];
        }
        $messages = [];
        foreach ((array) $request->session()->pull(self::KEY, []) as $entry) {
            if (is_array($entry) && is_string($entry['level'] ?? null) && is_string($entry['message'] ?? null)) {
                $messages[] = new Message($entry['level'], $entry['message']);
            }
        }

        return $messages;
    }

    /**
     * @return \ArrayIterator<int, Message>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->load());
    }

    public function count(): int
    {
        return count($this->load());
    }

    /**
     * @return list<Message>
     */
    private function load(): array
    {
        return $this->loaded ??= self::pull($this->request);
    }
}
