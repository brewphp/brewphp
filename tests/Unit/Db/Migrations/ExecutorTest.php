<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Db\Migrations;

use BrewPHP\Db\Connection;
use BrewPHP\Db\Fields\BigAutoField;
use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Fields\IntegerField;
use BrewPHP\Db\Index;
use BrewPHP\Db\IntegrityError;
use BrewPHP\Db\Migrations\Migration;
use BrewPHP\Db\Migrations\MigrationError;
use BrewPHP\Db\Migrations\Operations\AddField;
use BrewPHP\Db\Migrations\Operations\AddIndex;
use BrewPHP\Db\Migrations\Operations\AlterField;
use BrewPHP\Db\Migrations\Operations\AlterModelTable;
use BrewPHP\Db\Migrations\Operations\CreateModel;
use BrewPHP\Db\Migrations\Operations\DeleteModel;
use BrewPHP\Db\Migrations\Operations\RemoveField;
use BrewPHP\Db\Migrations\Operations\RemoveIndex;
use BrewPHP\Db\Migrations\Operations\RenameField;
use BrewPHP\Db\Migrations\Operations\RunPHP;
use BrewPHP\Db\Migrations\Operations\RunSQL;
use BrewPHP\Db\Migrations\ProjectState;

final class ExecutorTest extends MigrationTestCase
{
    /**
     * shop.0001 creates Category and Product, shop.0002 changes Product.
     *
     * @return list<Migration>
     */
    private function shop(): array
    {
        return [
            self::migration('shop', '0001_initial', [
                new CreateModel('Category', ['id' => new BigAutoField(), 'name' => new CharField(maxLength: 50, unique: true)], 'shop_category'),
                new CreateModel('Product', [
                    'id' => new BigAutoField(),
                    'title' => new CharField(maxLength: 100),
                    'category' => new ForeignKey('shop.Category'),
                ], 'shop_product', [new Index(['category', 'title'], unique: true)]),
            ]),
            self::migration('shop', '0002_product_changes', [
                new AddField('Product', 'stock', new IntegerField(default: 7), preserveDefault: false),
                new RenameField('Product', 'title', 'name'),
                new AlterField('Product', 'name', new CharField(maxLength: 200, null: true)),
                new AddIndex('Product', new Index(['stock'])),
            ], ['shop.0001_initial']),
        ];
    }

    public function testAppliesAndUnappliesEverything(): void
    {
        $executor = $this->executor($this->shop());
        $plan = $executor->plan();
        self::assertSame([['shop.0001_initial', false], ['shop.0002_product_changes', false]], $plan);

        $executor->migrate([$plan[0]]);
        $this->db()->insert('shop_category', ['name' => 'Tea']);
        $this->db()->insert('shop_product', ['title' => 'Assam', 'category_id' => 1]);
        $executor->migrate($executor->plan());

        self::assertSame(['id', 'name', 'category_id', 'stock'], $this->columns('shop_product'));
        self::assertSame(['Assam', 7], array_values($this->db()->selectOne('SELECT name, stock FROM shop_product') ?? []));
        self::assertMatchesRegularExpression('/^shop_product_category_id_\w+_idx,shop_product_category_id_name_\w+_uniq,shop_product_stock_\w+_idx$/', implode(',', $this->indexes('shop_product')));
        self::assertSame(['shop.0001_initial' => true, 'shop.0002_product_changes' => true], $executor->recorder()->applied());
        self::assertSame([], $executor->plan());
        self::assertSame(1, (int) $this->db()->scalar('PRAGMA foreign_keys'));

        // Back to 0001: the column gets its old name, "stock" goes away.
        $executor->migrate($executor->plan('shop', '0001'));
        self::assertSame(['id', 'title', 'category_id'], $this->columns('shop_product'));
        self::assertMatchesRegularExpression('/^shop_product_category_id_\w+_idx,shop_product_category_id_title_\w+_uniq$/', implode(',', $this->indexes('shop_product')));

        $executor->migrate($executor->plan('shop', 'zero'));
        self::assertFalse($this->db()->hasTable('shop_product'));
        self::assertFalse($this->db()->hasTable('shop_category'));
        self::assertSame([], $executor->recorder()->applied());
    }

    public function testPlansAcrossApps(): void
    {
        $migrations = [
            ...$this->shop(),
            self::migration('orders', '0001_initial', [
                new CreateModel('Order', ['id' => new BigAutoField(), 'product' => new ForeignKey('shop.Product')], 'orders_order'),
            ], ['shop.0001_initial']),
        ];
        $executor = $this->executor($migrations);

        self::assertSame([['shop.0001_initial', false], ['orders.0001_initial', false]], $executor->plan('orders'));
        $executor->migrate($executor->plan());
        // Unapplying shop.0001 first unapplies what depends on it.
        self::assertSame(
            [['shop.0002_product_changes', true], ['orders.0001_initial', true], ['shop.0001_initial', true]],
            $executor->plan('shop', 'zero'),
        );
    }

    public function testFakeOnlyRecords(): void
    {
        $executor = $this->executor($this->shop());
        $executor->migrate($executor->plan('shop', '0001'), fake: true);

        self::assertFalse($this->db()->hasTable('shop_product'));
        self::assertSame(['shop.0001_initial' => true], $executor->recorder()->applied());
    }

    public function testFailedMigrationIsRolledBack(): void
    {
        $executor = $this->executor([
            self::migration('shop', '0001_initial', [
                new CreateModel('Thing', ['id' => new BigAutoField()], 'shop_thing'),
                new RunSQL('INSERT INTO no_such_table VALUES (1)'),
            ]),
        ]);

        try {
            $executor->migrate($executor->plan());
            self::fail('The migration should fail.');
        } catch (\Throwable) {
        }
        self::assertFalse($this->db()->hasTable('shop_thing'));
        self::assertSame([], $executor->recorder()->applied());
        self::assertSame(1, (int) $this->db()->scalar('PRAGMA foreign_keys'));
    }

    public function testForeignKeyViolationsAreCaught(): void
    {
        $executor = $this->executor([
            self::migration('shop', '0001_initial', [
                new CreateModel('Category', ['id' => new BigAutoField()], 'shop_category'),
                new CreateModel('Product', ['id' => new BigAutoField(), 'category' => new ForeignKey('shop.Category')], 'shop_product'),
                new RunSQL('INSERT INTO shop_product (category_id) VALUES (42)'),
            ]),
        ]);

        $this->expectException(IntegrityError::class);
        $executor->migrate($executor->plan());
    }

    public function testRunPhpAndIrreversibleOperations(): void
    {
        $calls = [];
        $executor = $this->executor([
            self::migration('shop', '0001_initial', [
                new CreateModel('Thing', ['id' => new BigAutoField(), 'name' => new CharField(maxLength: 10)], 'shop_thing'),
                new RunPHP(static function (Connection $db, ProjectState $state) use (&$calls): void {
                    $calls[] = 'forwards';
                    $db->insert($state->get('shop', 'Thing')->table, ['name' => 'first']);
                }),
            ]),
        ]);
        $executor->migrate($executor->plan());
        self::assertSame(['forwards'], $calls);
        self::assertSame('first', $this->db()->scalar('SELECT name FROM shop_thing'));

        $this->expectException(MigrationError::class);
        $this->expectExceptionMessage('Migration shop.0001_initial cannot be unapplied: "PHP operation" is not reversible.');
        $executor->migrate($executor->plan('shop', 'zero'));
    }

    public function testCollectsSqlWithoutRunningIt(): void
    {
        $executor = $this->executor($this->shop());
        $sql = $executor->collectSql('shop.0001_initial');

        self::assertSame('-- Create model Category', $sql[0]);
        self::assertStringStartsWith('CREATE TABLE "shop_category"', $sql[1]);
        self::assertContains('-- Create model Product', $sql);
        self::assertStringContainsString('REFERENCES "shop_category" ("id")', implode("\n", $sql));
        self::assertFalse($this->db()->hasTable('shop_category'));

        $backwards = $executor->collectSql('shop.0001_initial', backwards: true);
        self::assertSame(['-- Create model Product', 'DROP TABLE "shop_product"', '-- Create model Category', 'DROP TABLE "shop_category"'], $backwards);
    }

    public function testInconsistentHistory(): void
    {
        $executor = $this->executor($this->shop());
        $executor->recorder()->record('shop', '0002_product_changes');

        $this->expectException(MigrationError::class);
        $this->expectExceptionMessage('Inconsistent migration history: shop.0002_product_changes is applied before its dependency shop.0001_initial.');
        $executor->migrate($executor->plan());
    }

    public function testModelLevelOperations(): void
    {
        $executor = $this->executor([
            ...$this->shop(),
            self::migration('shop', '0003_more', [
                new RemoveIndex('Product', new Index(['stock'])),
                new RemoveField('Product', 'stock'),
                new AlterModelTable('Product', 'products'),
                new DeleteModel('Product'),
            ], ['shop.0002_product_changes']),
        ]);
        $executor->migrate($executor->plan('shop', '0002'));
        self::assertTrue($this->db()->hasTable('shop_product'));

        $executor->migrate($executor->plan());
        self::assertFalse($this->db()->hasTable('shop_product'));
        self::assertFalse($this->db()->hasTable('products'));

        $executor->migrate($executor->plan('shop', '0002'));
        self::assertSame(['id', 'name', 'category_id', 'stock'], $this->columns('shop_product'));
    }
}
