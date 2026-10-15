<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Db\Migrations;

use BrewPHP\Db\Fields\BigAutoField;
use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\Field;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Fields\IntegerField;
use BrewPHP\Db\Index;
use BrewPHP\Db\Migrations\Autodetector;
use BrewPHP\Db\Migrations\MigrationError;
use BrewPHP\Db\Migrations\ModelState;
use BrewPHP\Db\Migrations\Operations\AddField;
use BrewPHP\Db\Migrations\Operations\AlterField;
use BrewPHP\Db\Migrations\Operations\Operation;
use BrewPHP\Db\Migrations\ProjectState;
use BrewPHP\Db\Migrations\Questioner;
use BrewPHP\Tests\Fixtures\Models\Author;
use BrewPHP\Tests\Fixtures\Models\Category;
use BrewPHP\Tests\Fixtures\Models\Comment;
use BrewPHP\Tests\Fixtures\Models\Node;
use BrewPHP\Tests\Fixtures\Models\Post;
use BrewPHP\Tests\Fixtures\Models\Profile;

final class AutodetectorTest extends MigrationTestCase
{
    /**
     * @param array<string, Field> $fields
     * @param list<Index> $indexes
     */
    private static function model(string $name, array $fields, array $indexes = [], string $app = 'shop', ?string $table = null): ModelState
    {
        return new ModelState($app, $name, ['id' => new BigAutoField()] + $fields, $table ?? $app . '_' . strtolower($name), $indexes);
    }

    private static function state(ModelState ...$models): ProjectState
    {
        $state = new ProjectState();
        foreach ($models as $model) {
            $state->add($model);
        }

        return $state;
    }

    /**
     * Each operation of the changes, described.
     *
     * @param array<string, array{operations: list<Operation>, dependsOn: list<string>}> $changes
     * @return array<string, list<string>>
     */
    private static function described(array $changes): array
    {
        return array_map(static fn (array $change): array => array_map(static fn (Operation $op): string => $op->describe(), $change['operations']), $changes);
    }

    public function testCreatesModelsWithTargetsFirst(): void
    {
        $to = ProjectState::fromModels([Post::class, Comment::class, Author::class, Category::class, Profile::class, Node::class]);
        $changes = (new Autodetector(new ProjectState(), $to))->changes();

        self::assertSame(['blog'], array_keys($changes));
        $described = self::described($changes)['blog'];
        self::assertCount(6, $described);
        self::assertLessThan(array_search('Create model Post', $described, true), array_search('Create model Author', $described, true));
        self::assertLessThan(array_search('Create model Post', $described, true), array_search('Create model Category', $described, true));
        self::assertLessThan(array_search('Create model Comment', $described, true), array_search('Create model Post', $described, true));
        self::assertSame([], $changes['blog']['dependsOn']);
    }

    public function testNoChangesWhenStatesMatch(): void
    {
        $state = ProjectState::fromModels([Author::class, Post::class, Category::class]);

        self::assertSame([], (new Autodetector($state, clone $state))->changes());
    }

    public function testOptionsThatOnlyChangePhpNeedNoMigration(): void
    {
        $from = self::state(self::model('Item', ['name' => new CharField(maxLength: 50)]));
        $to = self::state(self::model('Item', ['name' => new CharField(maxLength: 50, blank: true, helpText: 'Shown in forms', verboseName: 'Name', choices: ['a' => 'A'])]));

        self::assertSame([], (new Autodetector($from, $to))->changes());
    }

    public function testFieldChanges(): void
    {
        $from = self::state(self::model('Item', [
            'name' => new CharField(maxLength: 50),
            'code' => new CharField(maxLength: 10),
        ], [new Index(['code'])]));
        $to = self::state(self::model('Item', [
            'name' => new CharField(maxLength: 100),
            'price' => new IntegerField(default: 0),
            'note' => new CharField(maxLength: 20, null: true),
        ], [new Index(['name', 'price'], unique: true)]));

        self::assertSame(['shop' => [
            'Remove index on Item (code)',
            'Add field price to Item',
            'Add field note to Item',
            'Alter field name on Item',
            'Remove field code from Item',
            'Create unique index on Item (name, price)',
        ]], self::described((new Autodetector($from, $to))->changes()));
    }

    public function testRenamedFieldWhenConfirmed(): void
    {
        $from = self::state(self::model('Item', ['title' => new CharField(maxLength: 50, null: true)], [new Index(['title'])]));
        $to = self::state(self::model('Item', ['name' => new CharField(maxLength: 50, null: true)], [new Index(['name'])]));

        $yes = new Questioner(static fn (string $question): bool => $question === 'Was Item.title renamed to Item.name?', static fn (): ?string => null);
        self::assertSame(['shop' => ['Rename field title on Item to name']], self::described((new Autodetector($from, $to, $yes))->changes()));

        // Without confirmation: a new field, a removed one, and new indexes.
        self::assertSame(['shop' => [
            'Remove index on Item (title)',
            'Add field name to Item',
            'Remove field title from Item',
            'Create index on Item (name)',
        ]], self::described((new Autodetector($from, $to))->changes()));
    }

    public function testRenamedModelWhenConfirmed(): void
    {
        $from = self::state(self::model('Article', ['title' => new CharField()]), self::model('Note', ['article' => new ForeignKey('shop.Article')]));
        $to = self::state(self::model('Post', ['title' => new CharField()]), self::model('Note', ['article' => new ForeignKey('shop.Post')]));

        $yes = new Questioner(static fn (): bool => true, static fn (): ?string => null);
        self::assertSame(
            ['shop' => ['Rename model Article to Post', 'Rename table of Post to shop_post']],
            self::described((new Autodetector($from, $to, $yes))->changes()),
        );
    }

    public function testNotNullFieldNeedsAValue(): void
    {
        $from = self::state(self::model('Item', []));
        $to = self::state(self::model('Item', ['sku' => new CharField(maxLength: 20)]));

        $ask = new Questioner(static fn (): bool => false, static fn (): ?string => '"N/A"');
        $operation = (new Autodetector($from, $to, $ask))->changes()['shop']['operations'][0];
        self::assertInstanceOf(AddField::class, $operation);
        self::assertFalse($operation->preserveDefault);
        self::assertSame('N/A', $operation->field->default);

        $this->expectException(MigrationError::class);
        $this->expectExceptionMessage('Cannot add the non-nullable field "sku" to Item without a default');
        (new Autodetector($from, $to))->changes();
    }

    public function testFieldBecomingNotNullAsksForAValue(): void
    {
        $from = self::state(self::model('Item', ['qty' => new IntegerField(null: true)]));
        $to = self::state(self::model('Item', ['qty' => new IntegerField()]));

        $ask = new Questioner(static fn (): bool => false, static fn (): ?string => '0');
        $operation = (new Autodetector($from, $to, $ask))->changes()['shop']['operations'][0];
        self::assertInstanceOf(AlterField::class, $operation);
        self::assertFalse($operation->preserveDefault);
        self::assertSame(0, $operation->field->default);

        $operation = (new Autodetector($from, $to))->changes()['shop']['operations'][0];
        self::assertInstanceOf(AlterField::class, $operation);
        self::assertTrue($operation->preserveDefault);
    }

    public function testCircularForeignKeysAreAddedAfterTheTables(): void
    {
        $to = self::state(
            self::model('A', ['b' => new ForeignKey('shop.B', null: true)]),
            self::model('B', ['a' => new ForeignKey('shop.A', null: true)]),
        );

        self::assertSame(
            ['shop' => ['Create model A', 'Create model B', 'Add field b to A']],
            self::described((new Autodetector(new ProjectState(), $to))->changes()),
        );
    }

    public function testDeletesReferencingModelsFirstAndTracksOtherApps(): void
    {
        $from = self::state(
            self::model('Order', []),
            self::model('Line', ['order' => new ForeignKey('shop.Order')]),
        );
        self::assertSame(['shop' => ['Delete model Line', 'Delete model Order']], self::described((new Autodetector($from, new ProjectState()))->changes()));

        $to = self::state(self::model('User', [], app: 'auth'), self::model('Order', ['user' => new ForeignKey('auth.User')]));
        $changes = (new Autodetector(self::state(self::model('User', [], app: 'auth')), $to))->changes();
        self::assertSame(['auth'], $changes['shop']['dependsOn']);
    }

    public function testTableChange(): void
    {
        $from = self::state(self::model('Item', []));
        $to = self::state(self::model('Item', [], table: 'items'));

        self::assertSame(['shop' => ['Rename table of Item to items']], self::described((new Autodetector($from, $to))->changes()));
    }

    public function testParsesLiterals(): void
    {
        self::assertSame(3, Questioner::parseLiteral(' 3 '));
        self::assertSame(-1.5, Questioner::parseLiteral('-1.5'));
        self::assertTrue(Questioner::parseLiteral('TRUE'));
        self::assertSame('draft', Questioner::parseLiteral("'draft'"));
        self::assertSame('plain text', Questioner::parseLiteral('plain text'));
    }
}
