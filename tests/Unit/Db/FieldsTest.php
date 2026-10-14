<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Db;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Fields\AutoField;
use BrewPHP\Db\Fields\BigIntegerField;
use BrewPHP\Db\Fields\BooleanField;
use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\DateField;
use BrewPHP\Db\Fields\DateTimeField;
use BrewPHP\Db\Fields\DecimalField;
use BrewPHP\Db\Fields\EmailField;
use BrewPHP\Db\Fields\Field;
use BrewPHP\Db\Fields\FloatField;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Fields\IntegerField;
use BrewPHP\Db\Fields\JsonField;
use BrewPHP\Db\Fields\OneToOneField;
use BrewPHP\Db\Fields\PositiveIntegerField;
use BrewPHP\Db\Fields\SlugField;
use BrewPHP\Db\Fields\TextField;
use BrewPHP\Db\Fields\TimeField;
use BrewPHP\Db\Fields\URLField;
use BrewPHP\Db\Fields\UUIDField;
use BrewPHP\Db\Grammars\SqliteGrammar;
use BrewPHP\Db\OnDelete;
use BrewPHP\Tests\Fixtures\Models\Author;
use BrewPHP\Tests\Fixtures\Models\PostStatus;
use BrewPHP\Tests\TestCase;

final class FieldsTest extends TestCase
{
    private static function error(Field $field, mixed $value): string
    {
        try {
            $field->clean($value);
        } catch (ValidationError $e) {
            return $e->getMessage();
        }

        return '';
    }

    public function testConversions(): void
    {
        self::assertSame(5, (new IntegerField())->toPhp('5'));
        self::assertSame(5, (new IntegerField())->toPhp(5.0));
        self::assertNull((new IntegerField())->toPhp(''));
        self::assertSame(1.5, (new FloatField())->toPhp('1.5'));
        self::assertSame('19.90', (new DecimalField())->toPhp('19.9'));
        self::assertSame('19.90', (new DecimalField())->toPhp(19.9));
        self::assertSame('-0.123', (new DecimalField(decimalPlaces: 2))->toPhp('-.123'));
        self::assertTrue((new BooleanField())->toPhp('on'));
        self::assertFalse((new BooleanField())->toPhp(0));
        self::assertNull((new BooleanField(null: true))->toPhp(''));
        self::assertSame('42', (new CharField())->toPhp(42));
        self::assertSame('09:05:00', (new TimeField())->toPhp('9:05'));
        self::assertSame('10:00:00', (new TimeField())->toPhp(new \DateTimeImmutable('2026-01-01 10:00')));
        self::assertSame('2026-01-02', (new DateField())->toDb('2026-01-02 15:00'));
        self::assertSame('2026-01-02 15:00:07', (new DateTimeField())->toDb(new \DateTime('2026-01-02 15:00:07.5')));
        self::assertNull((new DateTimeField())->toDb(null));
        self::assertSame('0123abcd-0000-4000-8000-00000000000f', (new UUIDField())->toPhp('{0123ABCD-0000-4000-8000-00000000000F}'));
        self::assertSame('{"a":[1,"é"]}', (new JsonField())->toDb(['a' => [1, 'é']]));
        self::assertSame(['a' => 1], (new JsonField())->fromDb('{"a":1}'));
        self::assertSame('plain', (new JsonField())->fromDb('plain'));
        self::assertSame('draft', (new CharField(choices: PostStatus::class))->toDb(PostStatus::Draft));
        self::assertSame(PostStatus::Published, (new CharField(choices: PostStatus::class))->toPhp('published'));
    }

    public function testInvalidValues(): void
    {
        self::assertSame('"abc" value must be an integer.', self::error(new IntegerField(), 'abc'));
        self::assertSame('"x" value must be a float.', self::error(new FloatField(), 'x'));
        self::assertSame('"1.2.3" value must be a decimal number.', self::error(new DecimalField(), '1.2.3'));
        self::assertSame('Ensure that there are no more than 2 decimal places.', self::error(new DecimalField(), '1.234'));
        self::assertSame('Ensure that there are no more than 3 digits before the decimal point.', self::error(new DecimalField(maxDigits: 5), '1234'));
        self::assertSame('"maybe" value must be either true or false.', self::error(new BooleanField(), 'maybe'));
        self::assertSame('"tomorrow-ish" value has an invalid date format.', self::error(new DateField(), 'tomorrow-ish'));
        self::assertSame('"25:00" value has an invalid time format.', self::error(new TimeField(), '25:00'));
        self::assertSame('"nope" is not a valid UUID.', self::error(new UUIDField(), 'nope'));
        self::assertSame('Enter a valid email address.', self::error(new EmailField(), 'not-an-email'));
        self::assertSame('Enter a valid URL.', self::error(new URLField(), 'javascript:alert(1)'));
        self::assertSame('', self::error(new URLField(), 'https://brewphp.dev/docs/'));
        self::assertSame('Enter a valid "slug" consisting of letters, numbers, underscores or hyphens.', self::error(new SlugField(), 'a b'));
        self::assertSame('Ensure this value is greater than or equal to 0.', self::error(new PositiveIntegerField(), -1));
        self::assertSame('Ensure this value has at most 3 characters (it has 4).', self::error(new CharField(maxLength: 3), 'abcd'));
        self::assertSame('This field cannot be null.', self::error(new IntegerField(), null));
        self::assertSame('This field cannot be blank.', self::error(new CharField(), ''));
        self::assertSame('', self::error(new CharField(blank: true), ''));
        self::assertSame('', self::error(new IntegerField(null: true, blank: true), null));
        self::assertSame("Value 'x' is not a valid choice.", self::error(new CharField(choices: ['a' => 'A']), 'x'));
        self::assertSame("Value 'archived' is not a valid choice.", self::error(new CharField(choices: PostStatus::class), 'archived'));
        self::assertSame('Expected text, got array.', self::error(new TextField(), ['x']));
        self::assertStringStartsWith('Value must be valid JSON', self::error(new JsonField(), NAN));
    }

    public function testCustomValidators(): void
    {
        $even = static function (mixed $value): void {
            if (is_int($value) && $value % 2 !== 0) {
                throw new ValidationError('Enter an even number.');
            }
        };
        $field = new IntegerField(validators: [$even]);

        self::assertSame(4, $field->clean('4'));
        self::assertSame('Enter an even number.', self::error($field, 3));
    }

    public function testDefaults(): void
    {
        self::assertSame('', (new CharField())->getDefault());
        self::assertNull((new CharField(null: true))->getDefault());
        self::assertSame(3, (new IntegerField(default: 3))->getDefault());
        self::assertSame([], (new JsonField(default: fn (): array => []))->getDefault());
        self::assertSame('date', (new CharField(default: 'date'))->getDefault(), 'only closures are called');
        self::assertNotSame((new UUIDField(default: UUIDField::uuid4(...)))->getDefault(), (new UUIDField(default: UUIDField::uuid4(...)))->getDefault());
        self::assertFalse((new IntegerField())->hasDefault());
    }

    public function testChoices(): void
    {
        self::assertSame(['draft' => 'Draft (hidden)', 'published' => 'Published'], (new CharField(choices: PostStatus::class))->choices());
        self::assertSame(['s' => 'Small'], (new CharField(choices: ['s' => 'Small']))->choices());
        self::assertNull((new CharField())->choices());

        $this->expectException(\InvalidArgumentException::class);
        new CharField(choices: \stdClass::class);
    }

    public function testOptionsAndLabels(): void
    {
        $field = new SlugField();
        self::assertTrue($field->dbIndex);
        self::assertSame(50, $field->maxLength);
        self::assertTrue($field->needsIndex());
        self::assertFalse((new SlugField(unique: true))->needsIndex());

        $date = new DateTimeField(autoNow: true);
        self::assertFalse($date->editable);
        self::assertTrue($date->blank);

        $field = new CharField(verboseName: 'Headline');
        self::assertSame('Headline', $field->label());
        $field = new CharField();
        $field->contributeTo(Author::class, 'first_name');
        self::assertSame('first name', $field->label());
        self::assertSame('first_name', $field->column);

        $fk = new ForeignKey(Author::class, dbColumn: 'writer');
        $fk->contributeTo(Author::class, 'mentor');
        self::assertSame('mentor_id', $fk->attname);
        self::assertSame('writer', $fk->column);
        self::assertSame('bigint', $fk->columnType(new SqliteGrammar()));
        self::assertSame('author_set', $fk->relatedAccessorName());
        self::assertSame('author', $fk->relatedQueryName());

        $one = new OneToOneField(Author::class);
        $one->contributeTo(Author::class, 'twin');
        self::assertTrue($one->unique);
        self::assertSame('author', $one->relatedAccessorName());

        self::assertTrue((new AutoField())->primaryKey);
        self::assertFalse((new AutoField())->editable);
        self::assertSame('integer', (new AutoField())->relatedColumnType(new SqliteGrammar()));
        self::assertSame('bigint', (new BigIntegerField())->columnType(new SqliteGrammar()));
    }

    public function testDecimalPlacesCannotExceedDigits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DecimalField(maxDigits: 2, decimalPlaces: 3);
    }

    public function testDeconstruct(): void
    {
        self::assertSame(['class' => CharField::class, 'options' => ['maxLength' => 200]], (new CharField(maxLength: 200))->deconstruct());
        self::assertSame(['class' => SlugField::class, 'options' => ['unique' => true]], (new SlugField(unique: true))->deconstruct());
        self::assertSame(['class' => SlugField::class, 'options' => ['dbIndex' => false]], (new SlugField(dbIndex: false))->deconstruct());
        self::assertSame(['class' => AutoField::class, 'options' => []], (new AutoField())->deconstruct());
        self::assertSame(
            ['class' => ForeignKey::class, 'options' => ['to' => Author::class, 'onDelete' => OnDelete::SetNull, 'relatedName' => 'posts', 'null' => true]],
            (new ForeignKey(Author::class, onDelete: OnDelete::SetNull, relatedName: 'posts', null: true))->deconstruct(),
        );
        self::assertSame(
            ['class' => DecimalField::class, 'options' => ['maxDigits' => 8, 'choices' => ['1.00' => 'One'], 'helpText' => 'Price']],
            (new DecimalField(maxDigits: 8, helpText: 'Price', choices: ['1.00' => 'One']))->deconstruct(),
        );
    }
}
