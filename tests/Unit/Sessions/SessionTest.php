<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Sessions;

use BrewPHP\Db\Migrations\Migrator;
use BrewPHP\Sessions\ArraySessionStore;
use BrewPHP\Sessions\DatabaseSessionStore;
use BrewPHP\Sessions\FileSessionStore;
use BrewPHP\Sessions\Session;
use BrewPHP\Sessions\SessionStore;
use BrewPHP\Tests\TestCase;

final class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        ArraySessionStore::reset();
    }

    public function testValuesAreLoadedLazilyAndSaved(): void
    {
        $store = new ArraySessionStore();
        $session = new Session($store);
        self::assertFalse($session->wasAccessed());
        self::assertNull($session->id());
        self::assertTrue($session->wasAccessed());

        $session->set('cart', [1, 2]);
        $session->set('name', 'Ann');
        self::assertTrue($session->isModified());
        $id = $session->save();
        self::assertTrue(Session::isValidId($id));
        self::assertFalse($session->isModified());

        $again = new Session($store, $id);
        self::assertSame([1, 2], $again->get('cart'));
        self::assertSame('Ann', $again->pull('name'));
        self::assertFalse($again->has('name'));
        self::assertSame('x', $again->get('missing', 'x'));
        self::assertCount(1, $again);
    }

    public function testUnknownAndMalformedIdsAreNeverAdopted(): void
    {
        $store = new ArraySessionStore();
        foreach ([str_repeat('a', 40), '../../etc/passwd', 'short'] as $id) {
            $session = new Session($store, $id);
            $session->set('x', 1);
            self::assertNotSame($id, $session->save());
        }
    }

    public function testCycleKeyAndFlush(): void
    {
        $store = new ArraySessionStore();
        $session = new Session($store);
        $session->set('cart', [1]);
        $old = $session->save();

        $session->cycleKey();
        $new = $session->save();
        self::assertNotSame($old, $new);
        self::assertFalse($store->exists($old));
        self::assertSame([1], (new Session($store, $new))->get('cart'));

        $session->flush();
        self::assertTrue($session->isEmpty());
        self::assertNull($session->id());
        self::assertFalse($store->exists($new));
    }

    public function testValuesMustBeJson(): void
    {
        $session = new Session(new ArraySessionStore());
        $session->set('object', fopen('php://memory', 'r'));

        $this->expectException(\JsonException::class);
        $session->save();
    }

    public function testFileStore(): void
    {
        $directory = $this->tempDir() . '/sessions';
        $store = new FileSessionStore($directory);
        $this->assertStoreWorks($store);

        // Ids that are not session ids never touch the file system.
        self::assertNull($store->read('../../secrets'));
        self::assertFalse($store->exists('..\\x'));
        $this->expectException(\InvalidArgumentException::class);
        $store->write('../evil', [], 60);
    }

    public function testFileStoreExpiryAndGc(): void
    {
        $directory = $this->tempDir();
        $store = new FileSessionStore($directory);
        $id = Session::generateId();
        $store->write($id, ['a' => 1], -1);
        $store->write(Session::generateId(), ['b' => 2], 60);

        self::assertNull($store->read($id));
        file_put_contents($directory . '/' . Session::generateId() . '.json', 'not json');
        self::assertSame(1, $store->gc());
        self::assertCount(1, glob($directory . '/*.json') ?: []);
    }

    public function testDatabaseStore(): void
    {
        $app = $this->makeApp([
            'INSTALLED_APPS' => ['brewphp.sessions'],
            'DATABASES' => ['default' => ['ENGINE' => 'sqlite', 'NAME' => ':memory:']],
        ]);
        $executor = (new Migrator($app))->executor();
        $executor->migrate($executor->plan());

        $store = new DatabaseSessionStore($app->db());
        $this->assertStoreWorks($store);

        $expired = Session::generateId();
        $store->write($expired, ['x' => 1], -10);
        self::assertNull($store->read($expired));
        self::assertSame(1, $store->gc());
    }

    private function assertStoreWorks(SessionStore $store): void
    {
        $id = Session::generateId();
        self::assertNull($store->read($id));
        self::assertFalse($store->exists($id));

        $store->write($id, ['user' => 5, 'text' => 'é <b>'], 60);
        self::assertTrue($store->exists($id));
        self::assertSame(['user' => 5, 'text' => 'é <b>'], $store->read($id));

        $store->write($id, ['user' => 6], 60);
        self::assertSame(['user' => 6], $store->read($id));

        $store->destroy($id);
        self::assertNull($store->read($id));
    }
}
