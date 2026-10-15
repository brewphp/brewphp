<?php

declare(strict_types=1);

namespace BrewPHP\Sessions;

/**
 * The session of one visitor: `$request->session()`.
 *
 *     $session = $request->session();
 *     $session->set('cart', [12, 15]);
 *     $session->get('cart', []);
 *     $session->pull('coupon');      // read and remove
 *     $session->cycleKey();          // new id, same data (after login)
 *     $session->flush();             // no data, new id (logout)
 *
 * Data is loaded on first use and saved by SessionMiddleware when it
 * changed. Values must be JSON-encodable: strings, numbers, booleans,
 * null and arrays of those. Store model ids, not model objects.
 *
 * An id sent by the browser is only used if the store knows it, so an
 * attacker cannot choose a victim's session id (session fixation).
 */
final class Session implements \Countable
{
    /** @var array<string, mixed>|null */
    private ?array $data = null;

    private bool $modified = false;

    private bool $accessed = false;

    public function __construct(private readonly SessionStore $store, private ?string $id = null, private readonly int $lifetime = 1209600)
    {
        if ($id !== null && !self::isValidId($id)) {
            $this->id = null;
        }
    }

    /**
     * The session id, or null for a new session that was never saved.
     */
    public function id(): ?string
    {
        $this->load();

        return $this->id;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $data = $this->all();

        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->load();
        $this->data[$key] = $value;
        $this->modified = true;
    }

    public function has(string $key): bool
    {
        $this->load();

        return array_key_exists($key, (array) $this->data);
    }

    public function remove(string $key): void
    {
        $this->load();
        if (array_key_exists($key, (array) $this->data)) {
            unset($this->data[$key]);
            $this->modified = true;
        }
    }

    /**
     * Read a value and remove it.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->load();

        return (array) $this->data;
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    public function count(): int
    {
        return count($this->all());
    }

    /**
     * Remove every value, but keep the session id.
     */
    public function clear(): void
    {
        $this->load();
        $this->data = [];
        $this->modified = true;
    }

    /**
     * Remove every value and forget the id (the stored session is deleted).
     * Use it on logout.
     */
    public function flush(): void
    {
        $this->load();
        $this->data = [];
        $this->retireId();
        $this->modified = true;
    }

    /**
     * Keep the data under a new id, and delete the old one. Use it when
     * the user's privileges change, such as on login.
     */
    public function cycleKey(): void
    {
        $this->load();
        $this->retireId();
        $this->modified = true;
    }

    public function isModified(): bool
    {
        return $this->modified;
    }

    public function wasAccessed(): bool
    {
        return $this->accessed;
    }

    /**
     * Write the session to the store (creating an id if needed) and return the id.
     */
    public function save(): string
    {
        $this->load();
        if ($this->id === null) {
            do {
                $id = self::generateId();
            } while ($this->store->exists($id));
            $this->id = $id;
        }
        $this->store->write($this->id, (array) $this->data, $this->lifetime);
        $this->modified = false;

        return $this->id;
    }

    /**
     * Delete the stored session, if any. The session object is then empty.
     */
    public function destroy(): void
    {
        $this->load();
        $this->retireId();
        $this->data = [];
    }

    public function lifetime(): int
    {
        return $this->lifetime;
    }

    /**
     * 40 random characters from [a-z0-9].
     */
    public static function generateId(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $id = '';
        for ($i = 0; $i < 40; $i++) {
            $id .= $chars[random_int(0, 35)];
        }

        return $id;
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/^[a-z0-9]{40}$/', $id) === 1;
    }

    /**
     * Delete the stored session now, so the old id stops working at once,
     * and get a new id at the next save.
     */
    private function retireId(): void
    {
        if ($this->id !== null) {
            $this->store->destroy($this->id);
            $this->id = null;
        }
    }

    private function load(): void
    {
        $this->accessed = true;
        if ($this->data !== null) {
            return;
        }
        $data = $this->id !== null ? $this->store->read($this->id) : null;
        if ($data === null) {
            // Unknown or expired: never adopt an id the browser chose.
            $this->id = null;
        }
        $this->data = $data ?? [];
    }
}
