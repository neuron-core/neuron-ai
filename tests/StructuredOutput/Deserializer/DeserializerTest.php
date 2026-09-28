<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Deserializer;

use DateTime;
use DateTimeImmutable;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\Tests\StructuredOutput\Stub\Address;
use NeuronAI\Tests\StructuredOutput\Stub\Article;
use NeuronAI\Tests\StructuredOutput\Stub\CodeBlock;
use NeuronAI\Tests\StructuredOutput\Stub\Color;
use NeuronAI\Tests\StructuredOutput\Stub\IntEnum;
use NeuronAI\Tests\StructuredOutput\Stub\ColorWithDefaults;
use NeuronAI\Tests\StructuredOutput\Stub\DummyEnum;
use NeuronAI\Tests\StructuredOutput\Stub\DynamicPerson;
use NeuronAI\Tests\StructuredOutput\Stub\EmailMode;
use NeuronAI\Tests\StructuredOutput\Stub\FtpMode;
use NeuronAI\Tests\StructuredOutput\Stub\Headline;
use NeuronAI\Tests\StructuredOutput\Stub\ImageBlock;
use NeuronAI\Tests\StructuredOutput\Stub\MassAssignmentTarget;
use NeuronAI\Tests\StructuredOutput\Stub\Person;
use NeuronAI\Tests\StructuredOutput\Stub\ProtectedConstructorModel;
use NeuronAI\Tests\StructuredOutput\Stub\Tag;
use NeuronAI\Tests\StructuredOutput\Stub\TextBlock;
use NeuronAI\Tests\StructuredOutput\Stub\StringEnum;
use NeuronAI\Tests\StructuredOutput\Stub\TagProperties;
use NeuronAI\Tests\StructuredOutput\Stub\TreeNode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function addslashes;
use function get_object_vars;
use function json_encode;
use function str_repeat;
use function str_replace;
use function strtolower;

use const DATE_ATOM;

class DeserializerTest extends TestCase
{
    public function test_person_deserializer(): void
    {
        $json = '{"firstName": "John", "lastName": "Doe", "address": {"street": "Via Roma", "city": "Rome", "zip": "00100"}, "tags": []}';

        $obj = Deserializer::make()->fromJson($json, Person::class);

        $this->assertInstanceOf(Person::class, $obj);
        $this->assertSame('John', $obj->firstName);
        $this->assertSame('Doe', $obj->lastName);
    }

    public function test_person_with_address(): void
    {
        $json = '{"firstName": "John", "lastName": "Doe", "address": {"street": "Via Roma", "city": "Rome", "zip": "00100"}, "tags": []}';

        $obj = Deserializer::make()->fromJson($json, Person::class);

        $this->assertInstanceOf(Person::class, $obj);
        $this->assertSame('Via Roma', $obj->address->street);
        $this->assertSame('Rome', $obj->address->city);
        $this->assertSame('00100', $obj->address->zip);
    }

    public function test_constructor_deserialize_with_default_values(): void
    {
        $json = '{}';

        $obj = Deserializer::make()->fromJson($json, ColorWithDefaults::class);

        $this->assertInstanceOf(ColorWithDefaults::class, $obj);
        $this->assertSame(100, $obj->r);
        $this->assertSame(100, $obj->g);
        $this->assertSame(100, $obj->b);
        $this->assertFalse(isset($obj->transparency));
    }

    public function test_constructor_deserialize_with_provided_values(): void
    {
        $json = '{"r": 255, "g": 0, "b": 0, "transparency": 100}';

        $obj = Deserializer::make()->fromJson($json, ColorWithDefaults::class);

        $this->assertInstanceOf(ColorWithDefaults::class, $obj);
        $this->assertSame(255, $obj->r);
        $this->assertSame(0, $obj->g);
        $this->assertSame(0, $obj->b);
        $this->assertSame(100, $obj->transparency);
    }

    public function test_optional_constructor_runs_with_deserialized_promoted_values(): void
    {
        $obj = Deserializer::make()->fromJson('{"title": "Hello World", "slug": "forged"}', Article::class);

        $this->assertInstanceOf(Article::class, $obj);
        $this->assertSame('Hello World', $obj->title);
        $this->assertSame(1, $obj->version);
        $this->assertSame('hello-world', $obj->slug);
    }

    public function test_a_readonly_promoted_property_is_written_once_by_the_constructor(): void
    {
        $this->assertSame('Hello', Deserializer::make()->fromJson('{"title": "Hello"}', Headline::class)->title);
        $this->assertSame('untitled', Deserializer::make()->fromJson('{}', Headline::class)->title);
    }

    public function test_a_readonly_property_keeps_the_value_its_constructor_assigns(): void
    {
        $class = new class () {
            public readonly string $slug;

            public function __construct(public string $title = '')
            {
                $this->slug = strtolower(str_replace(' ', '-', $this->title));
            }
        };

        $obj = Deserializer::make()->fromJson('{"title": "Hello World", "slug": "forged"}', $class::class);

        $this->assertSame('hello-world', $obj->slug);
    }

    public function test_a_property_promoted_by_a_parent_constructor_is_written_like_any_other(): void
    {
        $class = new class () extends Headline {
            public function __construct(public int $version = 1)
            {
            }
        };

        $obj = Deserializer::make()->fromJson('{"title": "Hello", "version": 2}', $class::class);

        $this->assertSame('Hello', $obj->title);
        $this->assertSame(2, $obj->version);
    }

    public function test_constructor_with_required_parameters_is_not_invoked(): void
    {
        $obj = Deserializer::make()->fromJson('{"r": 0.5, "g": "0.25", "b": 1}', Color::class);

        $this->assertInstanceOf(Color::class, $obj);
        $this->assertSame(0.5, $obj->r);
        $this->assertSame(0.25, $obj->g);
        $this->assertSame(1.0, $obj->b);
    }

    public function test_non_public_constructor_is_not_invoked(): void
    {
        $fromJson = Deserializer::make()->fromJson('{"source": "json"}', ProtectedConstructorModel::class);
        $withoutData = Deserializer::make()->fromJson('{}', ProtectedConstructorModel::class);

        $this->assertInstanceOf(ProtectedConstructorModel::class, $fromJson);
        $this->assertSame('json', $fromJson->source);
        $this->assertInstanceOf(ProtectedConstructorModel::class, $withoutData);
        $this->assertSame('default', $withoutData->source);
    }

    public function test_deserialize_array_with_schema_properties_interface(): void
    {
        $json = '{"firstName": "John", "lastName": "Doe", "nickName": "JD", "tags": [{"name": "agent"}]}';

        $obj = Deserializer::make()->fromJson($json, DynamicPerson::class);

        $this->assertInstanceOf(DynamicPerson::class, $obj);
        $this->assertSame('JD', $obj->nickName);
        $this->assertCount(1, $obj->tags);
        $this->assertInstanceOf(Tag::class, $obj->tags[0]);
        $this->assertSame('agent', $obj->tags[0]->name);
    }

    public function test_deserialize_array(): void
    {
        $json = '{"firstName": "John", "lastName": "Doe", "address": {"street": "Via Roma", "city": "Rome", "zip": "00100"}, "tags": [{"name": "agent"}, {"name": "ops"}]}';

        $obj = Deserializer::make()->fromJson($json, Person::class);

        $this->assertInstanceOf(Person::class, $obj);
        $this->assertCount(2, $obj->tags);
        $this->assertContainsOnlyInstancesOf(Tag::class, $obj->tags);
        $this->assertSame('agent', $obj->tags[0]->name);
        $this->assertSame('ops', $obj->tags[1]->name);
    }

    public function test_array_without_item_type_keeps_raw_values(): void
    {
        $class = new class () {
            public array $values;
        };

        $obj = Deserializer::make()->fromJson('{"values": ["a", 1, {"nested": true}, [null]]}', $class::class);

        $this->assertSame(['a', 1, ['nested' => true], [null]], $obj->values);
    }

    public function test_deserialize_string_enum(): void
    {
        $class = new class () {
            #[SchemaProperty]
            public StringEnum $number;
        };

        $json = '{"number": "one"}';

        $obj = Deserializer::make()->fromJson($json, $class::class);

        $this->assertInstanceOf($class::class, $obj);
        $this->assertSame(StringEnum::ONE, $obj->number);
    }

    public function test_deserialize_int_enum(): void
    {
        $class = new class () {
            #[SchemaProperty]
            public IntEnum $number;
        };

        $json = '{"number": 1}';

        $obj = Deserializer::make()->fromJson($json, $class::class);

        $this->assertInstanceOf($class::class, $obj);
        $this->assertSame(IntEnum::ONE, $obj->number);
    }

    public function test_a_numeric_string_finds_the_int_backed_case(): void
    {
        $class = new class () {
            public IntEnum $number;
        };

        $this->assertSame(IntEnum::ONE, Deserializer::make()->fromJson('{"number": "1"}', $class::class)->number);
    }

    public function test_an_array_of_enum_values_is_deserialized(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [StringEnum::class])]
            public array $numbers;
        };

        // The schema asks for the backing values, so this answer follows it exactly
        $items = JsonSchema::make()->generate($class::class)['properties']['numbers']['items'];
        $this->assertSame(['type' => 'string', 'enum' => ['one', 'two', 'three']], $items);

        $obj = Deserializer::make()->fromJson('{"numbers": ["one", "three"]}', $class::class);

        $this->assertSame([StringEnum::ONE, StringEnum::THREE], $obj->numbers);
    }

    public function test_an_invalid_enum_value_in_an_array_is_rejected(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [StringEnum::class])]
            public array $numbers;
        };

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage("Invalid enum value 'four' for " . StringEnum::class);

        Deserializer::make()->fromJson('{"numbers": ["one", "four"]}', $class::class);
    }

    public function test_a_pure_enum_is_read_by_the_case_name_the_schema_advertises(): void
    {
        $class = new class () {
            public DummyEnum $choice;

            #[SchemaProperty(anyOf: [DummyEnum::class])]
            public array $choices;
        };

        $this->assertSame(['A', 'B'], JsonSchema::make()->generate($class::class)['properties']['choice']['enum']);

        $obj = Deserializer::make()->fromJson('{"choice": "A", "choices": ["B", "A"]}', $class::class);

        $this->assertSame(DummyEnum::A, $obj->choice);
        $this->assertSame([DummyEnum::B, DummyEnum::A], $obj->choices);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidCaseNames(): array
    {
        return [
            'an unknown name' => ['C', "Invalid enum value 'C' for " . DummyEnum::class],
            'a name in the wrong case' => ['a', "Invalid enum value 'a' for " . DummyEnum::class],
            'not a string' => [1, "Invalid enum value '1' for " . DummyEnum::class],
        ];
    }

    #[DataProvider('invalidCaseNames')]
    public function test_a_pure_enum_rejects_anything_but_an_exact_case_name(mixed $choice, string $message): void
    {
        $class = new class () {
            public DummyEnum $choice;
        };

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage($message);

        Deserializer::make()->fromJson((string) json_encode(['choice' => $choice]), $class::class);
    }

    public function test_deserialize_invalid_int_enum_value(): void
    {
        $class = new class () {
            public IntEnum $number;
        };

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage("Invalid enum value '4' for ".IntEnum::class);

        Deserializer::make()->fromJson('{"number": 4}', $class::class);
    }

    public function test_deserialize_invalid_input(): void
    {
        $class = new class () {
            #[SchemaProperty]
            public StringEnum $number;
        };

        $json = '{"number": "kangaroo"}';

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage("Invalid enum value 'kangaroo' for ".StringEnum::class);

        Deserializer::make()->fromJson($json, $class::class);
    }

    public function test_enum_value_matching_is_case_sensitive(): void
    {
        $class = new class () {
            public StringEnum $number;
        };

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage("Invalid enum value 'ONE'");

        Deserializer::make()->fromJson('{"number": "ONE"}', $class::class);
    }

    public function test_deserialize_null_input(): void
    {
        $class = new class () {
            #[SchemaProperty]
            public StringEnum $number;
        };

        $json = '{"number": null}';

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage('Property "number" must not be null');

        Deserializer::make()->fromJson($json, $class::class);
    }

    public function test_deserialize_empty_input(): void
    {
        $class = new class () {
            #[SchemaProperty]
            public StringEnum $number;
        };

        $json = '{}';

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage('Property "number" is required');

        Deserializer::make()->fromJson($json, $class::class);
    }

    public function test_missing_values_keep_property_defaults(): void
    {
        $class = new class () {
            public string $status = 'draft';
            public ?string $note = null;
        };

        $obj = Deserializer::make()->fromJson('{}', $class::class);

        $this->assertSame('draft', $obj->status);
        $this->assertNull($obj->note);
    }

    /**
     * One property per case of the required rule.
     */
    protected function requiredRuleClass(): string
    {
        return (new class () {
            public string $name;

            #[SchemaProperty(required: true)]
            public ?string $note;

            public ?string $nickname = 'guest';

            public string $status = 'draft';

            public ?string $alias;

            #[SchemaProperty(required: false)]
            public int $age;
        })::class;
    }

    public function test_an_optional_value_left_out_gets_its_default_or_null(): void
    {
        $obj = Deserializer::make()->fromJson('{"name": "Ada", "note": null}', $this->requiredRuleClass());

        $this->assertNull($obj->note);
        $this->assertSame('guest', $obj->nickname);
        $this->assertSame('draft', $obj->status);
        $this->assertNull($obj->alias);
        $this->assertFalse(isset($obj->age));
    }

    public function test_an_explicit_null_is_kept_where_the_property_can_hold_it(): void
    {
        $obj = Deserializer::make()->fromJson('{"name": "Ada", "note": "x", "nickname": null, "status": null}', $this->requiredRuleClass());

        $this->assertNull($obj->nickname);
        $this->assertSame('draft', $obj->status);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function requiredValuesLeftOut(): array
    {
        return [
            'a required property left out' => ['{"note": null}', 'Property "name" is required'],
            'null for a required property that cannot hold it' => ['{"name": null, "note": null}', 'Property "name" must not be null'],
            'a required nullable property left out' => ['{"name": "Ada"}', 'Property "note" is required'],
        ];
    }

    #[DataProvider('requiredValuesLeftOut')]
    public function test_a_required_value_left_out_is_rejected(string $json, string $message): void
    {
        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage($message);

        Deserializer::make()->fromJson($json, $this->requiredRuleClass());
    }

    public function test_a_promoted_default_applies_when_the_constructor_cannot_run(): void
    {
        $class = new class ('Ada') {
            public function __construct(public string $name, public string $title = 'untitled', public ?string $nickname = null)
            {
            }
        };

        $obj = Deserializer::make()->fromJson('{"name": "Ada"}', $class::class);

        $this->assertSame('untitled', $obj->title);
        $this->assertNull($obj->nickname);
    }

    public function test_invalid_json_is_rejected(): void
    {
        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage('Invalid JSON: Syntax error');

        Deserializer::make()->fromJson('{"firstName": "John",', Person::class);
    }

    public function test_json_nested_beyond_the_decoder_depth_limit_is_rejected(): void
    {
        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage('Invalid JSON: Maximum stack depth exceeded');

        Deserializer::make()->fromJson('{"values":'.str_repeat('[', 600).str_repeat(']', 600).'}', Person::class);
    }

    public function test_unknown_target_class_is_rejected(): void
    {
        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage('Class NeuronAI\Tests\StructuredOutput\Stub\DoesNotExist does not exist');

        Deserializer::make()->fromJson('{}', 'NeuronAI\Tests\StructuredOutput\Stub\DoesNotExist');
    }

    public function test_keys_are_matched_by_snake_case_and_camel_case_variants(): void
    {
        $class = new class () {
            public string $firstName;
            public string $last_name;
        };

        $obj = Deserializer::make()->fromJson('{"first_name": "John", "lastName": "Doe"}', $class::class);

        $this->assertSame('John', $obj->firstName);
        $this->assertSame('Doe', $obj->last_name);
    }

    public function test_exact_key_takes_precedence_over_case_variants(): void
    {
        $class = new class () {
            public string $firstName;
        };

        $obj = Deserializer::make()->fromJson('{"first_name": "snake", "firstName": "exact"}', $class::class);

        $this->assertSame('exact', $obj->firstName);
    }

    public function test_unknown_fields_are_ignored(): void
    {
        $class = new class () {
            public string $name;
        };

        $obj = Deserializer::make()->fromJson('{"name": "John", "role": "admin", "__proto__": {"x": 1}}', $class::class);

        $this->assertSame(['name' => 'John'], get_object_vars($obj));
    }

    public function test_numeric_values_are_coerced_to_declared_scalar_types(): void
    {
        $class = new class () {
            public int $count;
            public float $ratio;
            public string $code;
            public ?int $optional;
        };

        $obj = Deserializer::make()->fromJson('{"count": "42", "ratio": 2, "code": 7, "optional": "3"}', $class::class);

        $this->assertSame(42, $obj->count);
        $this->assertSame(2.0, $obj->ratio);
        $this->assertSame('7', $obj->code);
        $this->assertSame(3, $obj->optional);
    }

    public function test_json_booleans_are_kept(): void
    {
        $class = new class () {
            public bool $enabled;
            public bool $archived;
            public ?bool $verified;
        };

        $obj = Deserializer::make()->fromJson('{"enabled": true, "archived": false, "verified": false}', $class::class);

        $this->assertTrue($obj->enabled);
        $this->assertFalse($obj->archived);
        $this->assertFalse($obj->verified);
    }

    public function test_quoted_booleans_keep_their_meaning(): void
    {
        $class = new class () {
            public bool $approved;
            public bool $archived;
        };

        $obj = Deserializer::make()->fromJson('{"approved": "false", "archived": "true"}', $class::class);

        $this->assertFalse($obj->approved);
        $this->assertTrue($obj->archived);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function valuesThatWouldChange(): array
    {
        return [
            'a word for an integer' => ['{"age": "unknown"}', 'Property "age" must be of type integer, string given'],
            'a fraction for an integer' => ['{"age": 2.7}', 'Property "age" must be of type integer, float given'],
            'a list for an integer' => ['{"age": [30]}', 'Property "age" must be of type integer, array given'],
            'a word for a number' => ['{"ratio": "n/a"}', 'Property "ratio" must be of type number, string given'],
            'a list for a string' => ['{"name": ["John"]}', 'Property "name" must be of type string, array given'],
            'an object for a string' => ['{"name": {"first": "John"}}', 'Property "name" must be of type string, array given'],
            'a boolean for a string' => ['{"name": true}', 'Property "name" must be of type string, bool given'],
            'a word for a boolean' => ['{"approved": "maybe"}', 'Property "approved" must be of type boolean, string given'],
        ];
    }

    #[DataProvider('valuesThatWouldChange')]
    public function test_a_value_that_would_change_is_rejected(string $json, string $message): void
    {
        $class = new class () {
            public int $age = 0;
            public float $ratio = 0.0;
            public string $name = '';
            public bool $approved = false;
        };

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage($message);

        Deserializer::make()->fromJson($json, $class::class);
    }

    public function test_a_union_tries_its_next_type_when_a_value_would_change(): void
    {
        $class = new class () {
            public float|int $amount;
        };

        $this->assertSame(2.5, Deserializer::make()->fromJson('{"amount": "2.5"}', $class::class)->amount);

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage('Cannot cast value to any type in union for property amount');

        Deserializer::make()->fromJson('{"amount": "a lot"}', $class::class);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function valuesOfTheWrongShape(): array
    {
        return [
            'a string instead of an object' => ['"John"', 'The JSON must be of type object, string given'],
            'null instead of an object' => ['null', 'The JSON must be of type object, null given'],
            'a string for a nested object' => ['{"address": "Rome"}', 'Property "address" must be of type object, string given'],
            'a string for an array' => ['{"tags": "agent"}', 'Property "tags" must be of type array, string given'],
            'a string item for an array of objects' => ['{"tags": [{"name": "agent"}, "ops"]}', 'Property "tags" element 1 must be of type object, string given'],
            'a string item for an array of several object types' => ['{"modes": ["ftp"]}', 'Property "modes" element 0 must be of type object, string given'],
            'an object for an enum' => ['{"level": {"value": 1}}', "Invalid enum value '{\"value\":1}' for " . IntEnum::class],
        ];
    }

    #[DataProvider('valuesOfTheWrongShape')]
    public function test_a_value_of_the_wrong_shape_is_rejected(string $json, string $message): void
    {
        $class = new class () {
            public ?Address $address = null;
            #[SchemaProperty(anyOf: [Tag::class])]
            public array $tags = [];
            #[SchemaProperty(anyOf: [FtpMode::class, EmailMode::class])]
            public array $modes = [];
            public ?IntEnum $level = null;
        };

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage($message);

        Deserializer::make()->fromJson($json, $class::class);
    }

    public function test_unicode_strings_are_preserved(): void
    {
        $class = new class () {
            public string $text;
        };

        $obj = Deserializer::make()->fromJson('{"text": "Jürgen 🚀 你好"}', $class::class);

        $this->assertSame("J\u{00FC}rgen \u{1F680} \u{4F60}\u{597D}", $obj->text);
    }

    public function test_date_time_properties_accept_date_strings_and_unix_timestamps(): void
    {
        $class = new class () {
            public DateTime $createdAt;
            public ?DateTimeImmutable $updatedAt;
        };

        $obj = Deserializer::make()->fromJson(
            '{"createdAt": "2024-01-02T03:04:05+00:00", "updatedAt": 1700000000}',
            $class::class
        );

        $this->assertSame('2024-01-02T03:04:05+00:00', $obj->createdAt->format(DATE_ATOM));
        $this->assertSame('2023-11-14T22:13:20+00:00', $obj->updatedAt->format(DATE_ATOM));
    }

    public function test_date_time_from_unix_timestamp(): void
    {
        $class = new class () {
            public DateTime $createdAt;
        };

        $obj = Deserializer::make()->fromJson('{"createdAt": 86400}', $class::class);

        $this->assertSame('1970-01-02T00:00:00+00:00', $obj->createdAt->format(DATE_ATOM));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidDateProvider(): array
    {
        return [
            'DateTime from garbage string' => ['{"createdAt": "not a date"}', 'Cannot create DateTime from: not a date'],
            'DateTime from boolean' => ['{"createdAt": true}', 'Cannot create DateTime from value type: boolean'],
            'DateTime from object' => ['{"createdAt": {"date": "2024-01-01"}}', 'Cannot create DateTime from value type: array'],
            'DateTimeImmutable from garbage string' => ['{"updatedAt": "yesterday-ish"}', 'Cannot create DateTimeImmutable from: yesterday-ish'],
            'DateTimeImmutable from list' => ['{"updatedAt": [1]}', 'Cannot create DateTimeImmutable from value type: array'],
        ];
    }

    #[DataProvider('invalidDateProvider')]
    public function test_invalid_dates_are_rejected(string $json, string $message): void
    {
        $class = new class () {
            public ?DateTime $createdAt = null;
            public ?DateTimeImmutable $updatedAt = null;
        };

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage($message);

        Deserializer::make()->fromJson($json, $class::class);
    }

    public function test_nested_object_array(): void
    {
        $json = '{"firstName": "John", "lastName": "Doe", "address": {"street": "Via Roma", "city": "Rome", "zip": "00100"}, "tags": [{"name": "agent", "properties": [{"value": "prop"}]}]}';

        $obj = Deserializer::make()->fromJson($json, Person::class);
        $this->assertInstanceOf(Person::class, $obj);
        $this->assertInstanceOf(Tag::class, $obj->tags[0]);
        $this->assertCount(1, $obj->tags[0]->properties);
        $this->assertInstanceOf(TagProperties::class, $obj->tags[0]->properties[0]);
        $this->assertSame('prop', $obj->tags[0]->properties[0]->value);
    }

    public function test_nested_object_type_is_decided_by_the_declared_property_type_only(): void
    {
        $json = '{"firstName": "John", "lastName": "Doe", "address": {"__classname__": "person", "@type": "'.addslashes(Person::class).'", "street": "Via Roma", "city": "Rome", "zip": "1"}, "tags": []}';

        $obj = Deserializer::make()->fromJson($json, Person::class);

        $this->assertSame(Address::class, $obj->address::class);
        $this->assertSame(['street' => 'Via Roma', 'city' => 'Rome', 'zip' => '1'], get_object_vars($obj->address));
    }

    public function test_recursive_structures_are_deserialized_at_any_depth(): void
    {
        $json = '{"name": "root", "parent": {"name": "grandparent"}, "children": [{"name": "child", "children": [{"name": "leaf"}]}]}';

        $obj = Deserializer::make()->fromJson($json, TreeNode::class);

        $this->assertInstanceOf(TreeNode::class, $obj->parent);
        $this->assertSame('grandparent', $obj->parent->name);
        $this->assertNull($obj->parent->parent);
        $this->assertInstanceOf(TreeNode::class, $obj->children[0]);
        $this->assertInstanceOf(TreeNode::class, $obj->children[0]->children[0]);
        $this->assertSame('leaf', $obj->children[0]->children[0]->name);
        $this->assertSame([], $obj->children[0]->children[0]->children);
    }

    public function test_deserialize_multi_type_array_with_discriminator(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [FtpMode::class, EmailMode::class])]
            public array $modes;
        };

        $json = '{
            "modes": [
                {"__classname__": "ftpmode", "mode": "ftp", "account": "user123"},
                {"__classname__": "emailmode", "mode": "email", "mailingList": "list@example.com"},
                {"__classname__": "ftpmode", "mode": "ftp", "account": "backup"}
            ]
        }';

        $obj = Deserializer::make()->fromJson($json, $class::class);

        $this->assertInstanceOf($class::class, $obj);
        $this->assertCount(3, $obj->modes);

        $this->assertInstanceOf(FtpMode::class, $obj->modes[0]);
        $this->assertSame('ftp', $obj->modes[0]->mode);
        $this->assertSame('user123', $obj->modes[0]->account);

        $this->assertInstanceOf(EmailMode::class, $obj->modes[1]);
        $this->assertSame('email', $obj->modes[1]->mode);
        $this->assertSame('list@example.com', $obj->modes[1]->mailingList);

        $this->assertInstanceOf(FtpMode::class, $obj->modes[2]);
        $this->assertSame('ftp', $obj->modes[2]->mode);
        $this->assertSame('backup', $obj->modes[2]->account);
    }

    public function test_deserialize_multi_type_array_with_anyof(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [ImageBlock::class, TextBlock::class])]
            public array $blocks;
        };

        $json = '{
            "blocks": [
                {"__classname__": "imageblock", "type": "image", "url": "https://example.com/image.png"},
                {"__classname__": "textblock", "type": "text", "content": "Hello world"}
            ]
        }';

        $obj = Deserializer::make()->fromJson($json, $class::class);

        $this->assertInstanceOf($class::class, $obj);
        $this->assertCount(2, $obj->blocks);

        $this->assertInstanceOf(ImageBlock::class, $obj->blocks[0]);
        $this->assertSame('image', $obj->blocks[0]->type);
        $this->assertSame('https://example.com/image.png', $obj->blocks[0]->url);

        $this->assertInstanceOf(TextBlock::class, $obj->blocks[1]);
        $this->assertSame('text', $obj->blocks[1]->type);
        $this->assertSame('Hello world', $obj->blocks[1]->content);
    }

    public function test_multi_type_array_can_be_empty(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [FtpMode::class, EmailMode::class])]
            public array $modes;
        };

        $obj = Deserializer::make()->fromJson('{"modes": []}', $class::class);

        $this->assertSame([], $obj->modes);
    }

    public function test_discriminator_value_is_case_insensitive(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [FtpMode::class, EmailMode::class])]
            public array $modes;
        };

        $obj = Deserializer::make()->fromJson('{"modes": [{"__classname__": "FtpMode", "account": "a"}]}', $class::class);

        $this->assertInstanceOf(FtpMode::class, $obj->modes[0]);
        $this->assertSame('a', $obj->modes[0]->account);
    }

    public function test_discriminator_field_does_not_reach_the_target_object(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [CodeBlock::class, TextBlock::class])]
            public array $blocks;
        };

        $obj = Deserializer::make()->fromJson('{"blocks": [{"__classname__": "codeblock", "code": "echo 1;"}]}', $class::class);

        $this->assertInstanceOf(CodeBlock::class, $obj->blocks[0]);
        $this->assertSame('echo 1;', $obj->blocks[0]->code);
        $this->assertNull($obj->blocks[0]->__classname__);
    }

    public function test_single_type_array_ignores_the_discriminator_field(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [CodeBlock::class])]
            public array $blocks;
        };

        $obj = Deserializer::make()->fromJson('{"blocks": [{"__classname__": "textblock", "code": "x"}]}', $class::class);

        $this->assertInstanceOf(CodeBlock::class, $obj->blocks[0]);
        $this->assertSame('textblock', $obj->blocks[0]->__classname__);
    }

    public function test_custom_discriminator_name(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [FtpMode::class, EmailMode::class])]
            public array $modes;
        };

        $obj = Deserializer::make('kind')->fromJson(
            '{"modes": [{"kind": "emailmode", "mailingList": "a@b.c"}, {"kind": "ftpmode", "account": "x"}]}',
            $class::class
        );

        $this->assertInstanceOf(EmailMode::class, $obj->modes[0]);
        $this->assertInstanceOf(FtpMode::class, $obj->modes[1]);
    }

    public function test_default_discriminator_is_ignored_when_a_custom_one_is_configured(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [FtpMode::class, EmailMode::class])]
            public array $modes;
        };

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage('Missing kind discriminator field in data for multi-type array deserialization');

        Deserializer::make('kind')->fromJson('{"modes": [{"__classname__": "ftpmode", "account": "x"}]}', $class::class);
    }

    public function test_deserialize_multi_type_array_missing_discriminator(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [FtpMode::class, EmailMode::class])]
            public array $modes;
        };

        $json = '{
            "modes": [
                {"mode": "ftp", "account": "user123"}
            ]
        }';

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage('Missing __classname__ discriminator field in data for multi-type array deserialization');

        Deserializer::make()->fromJson($json, $class::class);
    }

    public function test_null_discriminator_is_treated_as_missing(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [FtpMode::class, EmailMode::class])]
            public array $modes;
        };

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage('Missing __classname__ discriminator field');

        Deserializer::make()->fromJson('{"modes": [{"__classname__": null, "account": "x"}]}', $class::class);
    }

    public function test_deserialize_multi_type_array_invalid_discriminator(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [FtpMode::class, EmailMode::class])]
            public array $modes;
        };

        $json = '{
            "modes": [
                {"__classname__": "invalidtype", "mode": "ftp", "account": "user123"}
            ]
        }';

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage("Unknown discriminator value 'invalidtype'. Expected one of: ftpmode, emailmode");

        Deserializer::make()->fromJson($json, $class::class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forgedDiscriminatorProvider(): array
    {
        return [
            'short name of an existing class outside anyOf' => ['person'],
            'fully qualified name of an existing class outside anyOf' => [Person::class],
            'fully qualified name of an allowed class' => [FtpMode::class],
            'global class name' => ['DateTime'],
            'path traversal like value' => ['../ftpmode'],
        ];
    }

    public function test_model_output_never_reaches_non_public_or_static_properties(): void
    {
        try {
            $target = Deserializer::make()->fromJson(
                '{"name": "John", "isAdmin": true, "internalToken": "forged", "registry": "forged"}',
                MassAssignmentTarget::class
            );

            $this->assertInstanceOf(MassAssignmentTarget::class, $target);
            $this->assertSame('John', $target->name);
            $this->assertFalse($target->isAdmin());
            $this->assertSame('server-side', $target->internalToken());
            $this->assertSame('', MassAssignmentTarget::$registry);
        } finally {
            MassAssignmentTarget::$registry = '';
        }
    }

    #[DataProvider('forgedDiscriminatorProvider')]
    public function test_discriminator_cannot_select_a_class_outside_the_declared_types(string $discriminator): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [FtpMode::class, EmailMode::class])]
            public array $modes;
        };

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage('Unknown discriminator value');

        Deserializer::make()->fromJson(
            '{"modes": [{"__classname__": '.json_encode($discriminator).', "firstName": "x"}]}',
            $class::class
        );
    }
}
