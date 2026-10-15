<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Forms;

use BrewPHP\Core\ValidationError;
use BrewPHP\Forms\Fields;
use BrewPHP\Forms\Fields\Field;
use BrewPHP\Http\UploadedFile;
use BrewPHP\Tests\Fixtures\Models\PostStatus;
use PHPUnit\Framework\TestCase;

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

    public function testCharField(): void
    {
        $field = new Fields\CharField(maxLength: 5, minLength: 2);

        self::assertSame('ab', $field->clean('  ab '));
        self::assertSame('This field is required.', self::error($field, '   '));
        self::assertSame('Ensure this value has at most 5 characters (it has 6).', self::error($field, 'abcdef'));
        self::assertSame('Ensure this value has at least 2 characters (it has 1).', self::error($field, 'a'));
        self::assertSame('Enter a valid value.', self::error($field, ['ab']), 'Arrays are not text.');
        self::assertSame('Null characters are not allowed.', self::error($field, "a\0b"));
        self::assertSame('', (new Fields\CharField(required: false))->clean(null));
        self::assertNull((new Fields\CharField(required: false, emptyValue: null))->clean(''));
        self::assertSame(' a ', (new Fields\CharField(strip: false))->clean(' a '));
        self::assertSame('Please.', self::error(new Fields\CharField(errorMessages: ['required' => 'Please.']), ''));
    }

    public function testEmailAndUrl(): void
    {
        self::assertSame('ann@example.com', (new Fields\EmailField())->clean(' ann@example.com '));
        self::assertSame('Enter a valid email address.', self::error(new Fields\EmailField(), 'ann@'));

        $url = new Fields\URLField();
        self::assertSame('https://example.com/a', $url->clean('example.com/a'));
        self::assertSame('http://example.com', $url->clean('http://example.com'));
        foreach (['javascript:alert(1)', 'data:text/html,x', 'ftp://example.com', 'http://'] as $bad) {
            self::assertSame('Enter a valid URL.', self::error($url, $bad), $bad);
        }
    }

    public function testNumbers(): void
    {
        $integer = new Fields\IntegerField(minValue: 1, maxValue: 10);
        self::assertSame(5, $integer->clean(' 5 '));
        self::assertSame(5, $integer->clean('5.0'));
        foreach (['5.5', '1e3', '0x10', 'abc'] as $bad) {
            self::assertSame('Enter a whole number.', self::error($integer, $bad), $bad);
        }
        self::assertSame('Ensure this value is less than or equal to 10.', self::error($integer, '11'));
        self::assertSame('Ensure this value is greater than or equal to 1.', self::error($integer, '0'));
        self::assertNull((new Fields\IntegerField(required: false))->clean(''));

        self::assertSame(1.5, (new Fields\FloatField())->clean('1.5'));
        self::assertSame('Enter a number.', self::error(new Fields\FloatField(), 'INF'));

        $decimal = new Fields\DecimalField(maxDigits: 5, decimalPlaces: 2);
        self::assertSame('12.50', $decimal->clean('012.50'));
        self::assertSame('-0.5', $decimal->clean('-.5'));
        self::assertSame('Ensure that there are no more than 2 decimal places.', self::error($decimal, '1.234'));
        self::assertSame('Ensure that there are no more than 3 digits before the decimal point.', self::error($decimal, '1234'));
        self::assertSame('Enter a number.', self::error($decimal, '1,5'));
    }

    public function testBoolean(): void
    {
        $optional = new Fields\BooleanField(required: false);
        self::assertTrue($optional->clean('on'));
        self::assertFalse($optional->clean('false'));
        self::assertFalse($optional->clean(null));
        self::assertSame('This field is required.', self::error(new Fields\BooleanField(), false));
    }

    public function testChoices(): void
    {
        $choice = new Fields\ChoiceField(['tea' => 'Tea', 'coffee' => 'Coffee']);
        self::assertSame('tea', $choice->clean('tea'));
        self::assertSame('Select a valid choice. beer is not one of the available choices.', self::error($choice, 'beer'));
        self::assertSame(['' => '---------', 'tea' => 'Tea', 'coffee' => 'Coffee'], $choice->widget instanceof \BrewPHP\Forms\Widgets\Select ? $choice->widget->choices : []);

        $enum = new Fields\ChoiceField(PostStatus::class);
        self::assertSame(PostStatus::Published, $enum->clean('published'));

        $multiple = new Fields\MultipleChoiceField(['a' => 'A', 'b' => 'B']);
        self::assertSame(['a', 'b'], $multiple->clean(['a', 'b', 'a']));
        self::assertSame('Select a valid choice. c is not one of the available choices.', self::error($multiple, ['a', 'c']));
        self::assertSame('Enter a list of values.', self::error($multiple, 'a'));
        self::assertSame('Enter a list of values.', self::error($multiple, [['nested']]));
        self::assertSame([PostStatus::Draft], (new Fields\MultipleChoiceField(PostStatus::class))->clean(['draft']));

        $typed = new Fields\ChoiceField(['1' => 'One'], coerce: static fn (string $v): int => (int) $v);
        self::assertSame(1, $typed->clean('1'));
    }

    public function testDatesAndTimes(): void
    {
        $date = (new Fields\DateField())->clean('2026-10-15');
        self::assertInstanceOf(\DateTimeImmutable::class, $date);
        self::assertSame('2026-10-15 00:00:00', $date->format('Y-m-d H:i:s'));
        self::assertSame('Enter a valid date.', self::error(new Fields\DateField(), '2026-02-30'));
        self::assertSame('Enter a valid date.', self::error(new Fields\DateField(), '15/10/2026'));
        self::assertSame('15/10/2026', (new Fields\DateField(inputFormats: ['d/m/Y']))->clean('15/10/2026')->format('d/m/Y'));

        $dateTime = (new Fields\DateTimeField())->clean('2026-10-15T08:30');
        self::assertSame('2026-10-15 08:30:00', $dateTime->format('Y-m-d H:i:s'));
        self::assertSame('08:05:00', (new Fields\TimeField())->clean('08:05'));
        self::assertSame('Enter a valid time.', self::error(new Fields\TimeField(), '25:00'));
        self::assertSame('12345678-1234-1234-1234-123456789abc', (new Fields\UUIDField())->clean('{12345678123412341234123456789ABC}'));
        self::assertSame(['a' => [1, 2]], (new Fields\JsonField())->clean('{"a": [1, 2]}'));
        self::assertSame('Enter a valid JSON.', self::error(new Fields\JsonField(), '{a'));
    }

    public function testFiles(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'brewphp');
        file_put_contents((string) $path, 'hello');
        $upload = UploadedFile::fake((string) $path, 'notes.txt');
        $field = new Fields\FileField(maxSize: 10, extensions: ['txt', 'pdf']);

        self::assertSame($upload, $field->clean($upload));
        self::assertSame('File extension “php” is not allowed. Allowed extensions are: txt, pdf.', self::error($field, UploadedFile::fake((string) $path, 'shell.php')));
        self::assertSame('Ensure this file is at most 3 bytes (it is 5).', self::error(new Fields\FileField(maxSize: 3), $upload));
        self::assertSame('No file was submitted. Check the encoding type on the form.', self::error($field, 'notes.txt'));
        self::assertSame(['accept' => '.txt,.pdf'], $field->widgetAttrs($field->widget));
        unlink((string) $path);
    }

    public function testValidators(): void
    {
        $field = new Fields\CharField(validators: [
            static function (string $value): void {
                if ($value !== strtolower($value)) {
                    throw new ValidationError('Use lower case.');
                }
            },
        ]);

        self::assertSame('Use lower case.', self::error($field, 'ABC'));
    }
}
