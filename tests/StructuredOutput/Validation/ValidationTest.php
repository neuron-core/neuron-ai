<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Validation;

use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\StructuredOutputException;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\Count;
use NeuronAI\StructuredOutput\Validation\Rules\Email;
use NeuronAI\StructuredOutput\Validation\Rules\Enum;
use NeuronAI\StructuredOutput\Validation\Rules\IsNotNull;
use NeuronAI\StructuredOutput\Validation\Rules\IsNull;
use NeuronAI\StructuredOutput\Validation\Rules\Length;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;
use NeuronAI\StructuredOutput\Validation\Validator;
use NeuronAI\Tests\StructuredOutput\Stub\Address;
use NeuronAI\Tests\StructuredOutput\Stub\IntEnum;
use NeuronAI\Tests\StructuredOutput\Stub\Person;
use NeuronAI\Tests\StructuredOutput\Stub\StringEnum;
use NeuronAI\Tests\StructuredOutput\Stub\Tag;
use NeuronAI\Tests\StructuredOutput\Stub\TagProperties;
use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase
{
    public function test_object_without_rules_has_no_violations(): void
    {
        $class = new class () {
            #[SchemaProperty(description: 'Not a validation rule')]
            public string $name = '';

            public ?string $untouched = null;
        };

        $this->assertSame([], Validator::validate(new $class()));
    }

    public function test_not_blank_validation(): void
    {
        $class = new class () {
            #[NotBlank(false)]
            public string $name;
        };
        $this->assertSame(['name cannot be blank'], Validator::validate(new $class()));

        $class = new class () {
            #[NotBlank(true)]
            public string $name;
        };
        $this->assertSame([], Validator::validate(new $class()));
    }

    public function test_uninitialized_properties_are_validated_as_null(): void
    {
        $class = new class () {
            #[IsNotNull]
            public string $name;
        };
        $obj = new $class();

        $this->assertSame(['name must not be null'], Validator::validate($obj));

        $obj->name = 'test';
        $this->assertSame([], Validator::validate($obj));
    }

    public function test_is_null_validation(): void
    {
        $class = new class () {
            #[IsNull]
            public ?string $name = null;
        };
        $obj = new $class();

        $this->assertSame([], Validator::validate($obj));

        $obj->name = 'test';
        $this->assertSame(['name must be null'], Validator::validate($obj));
    }

    public function test_only_public_properties_are_validated(): void
    {
        $class = new class () {
            #[NotBlank]
            protected string $internal = '';

            #[NotBlank]
            private string $secret = '';

            #[NotBlank]
            public string $visible = '';

            public function secret(): string
            {
                return $this->secret;
            }
        };

        $this->assertSame(['visible cannot be blank'], Validator::validate(new $class()));
    }

    public function test_all_rules_of_all_properties_are_reported_in_declaration_order(): void
    {
        $class = new class () {
            #[NotBlank]
            #[Length(min: 3)]
            #[Email]
            public string $email = '';

            #[Count(min: 1)]
            public array $recipients = [];

            #[Length(max: 5)]
            public string $valid = 'ok';
        };

        $this->assertSame([
            'email cannot be blank',
            'email is too short. It must be at least 3 characters',
            'email must be a valid email address',
            'recipients is too short. It must be at least 1 items',
        ], Validator::validate(new $class()));
    }

    public function test_violations_do_not_leak_between_calls(): void
    {
        $class = new class () {
            #[NotBlank]
            public string $name = '';
        };
        $obj = new $class();

        Validator::validate($obj);
        $obj->name = 'filled';

        $this->assertSame([], Validator::validate($obj));
    }

    public function test_rule_instances_are_not_shared_between_calls(): void
    {
        $class = new class () {
            #[Length(exactly: 2)]
            public string $code = 'ab';
        };
        $obj = new $class();

        $this->assertSame([], Validator::validate($obj));
        $obj->code = 'abc';
        $this->assertSame(['code must be exactly 2 characters long'], Validator::validate($obj));
        $obj->code = 'ab';
        $this->assertSame([], Validator::validate($obj));
    }

    public function test_array_of_validation(): void
    {
        $class = new class () {
            #[ArrayOf(type: 'string')]
            public array $tags = [];
        };
        $this->assertSame(['tags must be an array of string'], Validator::validate(new $class()));

        $obj = new $class();
        $obj->tags = [123];
        $this->assertSame(['tags must be an array of string'], Validator::validate($obj));

        $obj->tags = ['test'];
        $this->assertSame([], Validator::validate($obj));
    }

    public function test_nested_array_of(): void
    {
        $person = new Person();
        $person->firstName = 'test';
        $tag = new Tag();
        $tag->name = 'test';
        $tagProperty = new TagProperties();
        $tagProperty->value = 'test';
        $tag->properties = [$tagProperty];
        $person->tags = [$tag];

        $this->assertSame([], Validator::validate($person));

        $tagProperty->value = '';
        $this->assertSame(['tags must be an array of '.Tag::class], Validator::validate($person));
    }

    public function test_nested_array_with_deserialize(): void
    {
        $json = '{"firstName": "John", "lastName": "Doe", "tags": [{"name": "agent", "properties": [{"value": "prop"}]}]}';

        $obj = Deserializer::make()->fromJson($json, Person::class);

        $this->assertSame([], Validator::validate($obj));
    }

    public function test_invalid_deeply_nested_value_after_deserialize_is_reported_on_the_root_property(): void
    {
        $json = '{"firstName": "John", "lastName": "Doe", "tags": [{"name": "agent", "properties": [{"value": ""}]}]}';

        $obj = Deserializer::make()->fromJson($json, Person::class);

        $this->assertSame(['tags must be an array of '.Tag::class], Validator::validate($obj));
    }

    public function test_array_of_nested_validation(): void
    {
        $class = new class () {
            #[ArrayOf(type: Person::class)]
            public array $people;
        };
        $obj = new $class();

        $obj->people = [new Person()];
        $this->assertSame(['people must be an array of '.Person::class], Validator::validate($obj));

        $person = new Person();
        $person->firstName = 'test';
        $obj->people = [$person];
        $this->assertSame([], Validator::validate($obj));
    }

    public function test_array_of_multiple_types(): void
    {
        $class = new class () {
            #[ArrayOf(type: [Person::class, Address::class])]
            public array $people;
        };
        $obj = new $class();

        $person = new Person();
        $person->firstName = 'test';

        $address = new Address();
        $address->street = 'test';
        $address->zip = '80100';

        $obj->people = [$person, $address];
        $this->assertSame([], Validator::validate($obj));

        $obj->people = [$person, $address, new TagProperties()];
        $this->assertSame(
            ['people must be an array of '.Person::class.', '.Address::class],
            Validator::validate($obj)
        );
    }

    public function test_enum_validation(): void
    {
        $class = new class () {
            #[Enum(values: ['one', 'two', 'three'])]
            public string $number = 'one';

            #[Enum(class: StringEnum::class)]
            public string $enumNumber = 'one';

            #[Enum(class: IntEnum::class)]
            public IntEnum $intEnum = IntEnum::ONE;
        };

        $obj = new $class();

        $this->assertSame([], Validator::validate($obj));

        $obj->number = 'four';
        $this->assertSame(
            ['number must be one of the following allowed values: one, two, three.'],
            Validator::validate($obj)
        );

        $obj->enumNumber = 'five';
        $this->assertSame([
            'number must be one of the following allowed values: one, two, three.',
            'enumNumber must be one of the following allowed values: one, two, three.',
        ], Validator::validate($obj));
    }

    public function test_misconfigured_rule_errors_surface_from_the_validator(): void
    {
        $class = new class () {
            #[Enum(values: ['one', 'two', 'three'], class: StringEnum::class)]
            public string $number = 'one';
        };

        $this->expectException(StructuredOutputException::class);
        $this->expectExceptionMessage('You cannot provide both "values" and "class" options simultaneously. Please use only one.');

        Validator::validate(new $class());
    }
}
