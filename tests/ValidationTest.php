<?php

declare(strict_types=1);

namespace NeuronAI\Tests;

use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\StructuredOutputException;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\Enum;
use NeuronAI\StructuredOutput\Validation\Rules\Count;
use NeuronAI\StructuredOutput\Validation\Rules\Email;
use NeuronAI\StructuredOutput\Validation\Rules\EqualTo;
use NeuronAI\StructuredOutput\Validation\Rules\GreaterThan;
use NeuronAI\StructuredOutput\Validation\Rules\GreaterThanEqual;
use NeuronAI\StructuredOutput\Validation\Rules\IPAddress;
use NeuronAI\StructuredOutput\Validation\Rules\IsFalse;
use NeuronAI\StructuredOutput\Validation\Rules\IsNull;
use NeuronAI\StructuredOutput\Validation\Rules\IsTrue;
use NeuronAI\StructuredOutput\Validation\Rules\Json;
use NeuronAI\StructuredOutput\Validation\Rules\Length;
use NeuronAI\StructuredOutput\Validation\Rules\LowerThan;
use NeuronAI\StructuredOutput\Validation\Rules\LowerThanEqual;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;
use NeuronAI\StructuredOutput\Validation\Rules\NotEqualTo;
use NeuronAI\StructuredOutput\Validation\Rules\IsNotNull;
use NeuronAI\StructuredOutput\Validation\Rules\Url;
use NeuronAI\StructuredOutput\Validation\Rules\WordsCount;
use NeuronAI\StructuredOutput\Validation\Validator;
use NeuronAI\Tests\Stubs\DummyEnum;
use NeuronAI\Tests\Stubs\IntEnum;
use NeuronAI\Tests\Stubs\StructuredOutput\Person;
use NeuronAI\Tests\Stubs\StringEnum;
use NeuronAI\Tests\Stubs\StructuredOutput\Tag;
use NeuronAI\Tests\Stubs\StructuredOutput\TagProperties;
use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase
{
    public function test_not_blank_validation(): void
    {
        $class = new class () {
            #[NotBlank(false)]
            public string $name;
        };
        $violations = Validator::validate(new $class());
        $this->assertCount(1, $violations);

        $class = new class () {
            #[NotBlank(true)]
            public string $name;
        };
        $violations = Validator::validate(new $class());
        $this->assertCount(0, $violations);
    }

    public function test_not_null_validation(): void
    {
        $class = new class () {
            #[IsNotNull]
            public string $name;
        };
        $class = new $class();

        $violations = Validator::validate(new $class());
        $this->assertCount(1, $violations);

        $class->name = 'test';
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);
    }

    public function test_is_null_validation(): void
    {
        $class = new class () {
            #[IsNull]
            public ?string $name = null;
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->name = 'test';
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->name = null;
        $violations = Validator::validate(new $class());
        $this->assertCount(0, $violations);
    }

    public function test_url_validation(): void
    {
        $class = new class () {
            #[Url]
            public string $url = 'test';
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->url = 'https://inspector.dev';
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);
    }

    public function test_array_of_validation(): void
    {
        $class = new class () {
            #[ArrayOf(type: 'string')]
            public array $tags = [];
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class = new class () {
            #[ArrayOf(type: 'string', allowEmpty: true)]
            public array $tags = [];
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class = new class () {
            #[ArrayOf(type: 'string')]
            public array $tags;
        };
        $class = new $class();

        $class->tags = [123];
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->tags = ['test'];
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);
    }

    public function test_nested_array_of(): void
    {
        $class = new Person();
        $tag = new Tag();
        $tag->name = 'test';
        $tagProp = new TagProperties();
        $tagProp->value = 'test';
        $tag->properties = [$tagProp];
        $class->firstName = 'test';
        $class->tags = [$tag];

        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);
    }

    public function test_nested_array_with_deserialize(): void
    {
        $json = '{"firstName": "John", "lastName": "Doe", "tags": [{"name": "agent", "properties": [{"value": "prop"}]}]}';

        $class = Deserializer::fromJson($json, Person::class);
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);
    }

    public function test_array_of_nested_validation(): void
    {
        $class = new class () {
            #[ArrayOf(type: Person::class)]
            public array $people;
        };
        $class = new $class();

        $class->people = [new Person()];
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $person = new Person();
        $person->firstName = 'test';
        $class->people = [$person];
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);
    }

    public function test_length_validation(): void
    {
        $class = new class () {
            #[Length(exactly: 10)]
            public string $name;
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->name = 'xxxxx';
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->name = 'xxxxxxxxxx';
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->name = 'xxxxxxxxxxx';
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class = new class () {
            #[Length(min: 1)]
            public string $name = 'x';
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->name = '';
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class = new class () {
            #[Length(max: 1)]
            public string $name = 'x';
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->name = 'xx';
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);
    }

    public function test_count_validation(): void
    {
        $class = new class () {
            #[Count(exactly: 10)]
            public array $tags;
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->tags = ['x'];
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);


        $class->tags = \range(1, 10);
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->tags = \range(1, 11);
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class = new class () {
            #[Count(min: 1)]
            public array $tags = ['x'];
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->tags = [];
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class = new class () {
            #[Count(max: 1)]
            public array $tags = ['x'];
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->tags = ['x', 'x'];
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);
    }

    public function test_email_validation(): void
    {
        $class = new class () {
            #[Email]
            public string $email = 'test';
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->email = 'info@email.com';
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);
    }

    public function test_equal_to_validation(): void
    {
        $class = new class () {
            #[EqualTo(reference: 'test')]
            public string $name = 'test';
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->name = 'test2';
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);
    }

    public function test_not_equal_to_validation(): void
    {
        $class = new class () {
            #[NotEqualTo(reference: 'test')]
            public string $name = 'test';
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->name = 'test2';
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);
    }

    public function test_greater_than_validation(): void
    {
        $class = new class () {
            #[GreaterThan(reference: 30)]
            public int $age;
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->age = 29;
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->age = 30;
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->age = 31;
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);
    }

    public function test_greater_than_equal_validation(): void
    {
        $class = new class () {
            #[GreaterThanEqual(reference: 30)]
            public int $age;
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->age = 29;
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->age = 30;
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->age = 31;
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);
    }

    public function test_lower_than_validation(): void
    {
        $class = new class () {
            #[LowerThan(reference: 30)]
            public int $age;
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->age = 29;
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->age = 30;
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->age = 31;
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);
    }

    public function test_lower_than_equal_validation(): void
    {
        $class = new class () {
            #[LowerThanEqual(reference: 30)]
            public int $age;
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->age = 29;
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->age = 30;
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->age = 31;
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);
    }

    public function test_is_false_validation(): void
    {
        $class = new class () {
            #[IsFalse]
            public bool $completed;
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->completed = false;
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->completed = true;
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);
    }

    public function test_is_true_validation(): void
    {
        $class = new class () {
            #[IsTrue]
            public bool $completed;
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->completed = false;
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->completed = true;
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);
    }

    public function test_ip_address_validation(): void
    {
        $class = new class () {
            #[IPAddress]
            public string $ip = '127.0.0.1';
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->ip = '127.0.0';
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);
    }

    public function test_json_validation(): void
    {
        $class = new class () {
            #[Json]
            public string $json = '{}';
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->json = 'invalid json';
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);
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

        $violations = Validator::validate($obj);
        $this->assertCount(0, $violations);

        $obj->number = 'four';
        $violations = Validator::validate($obj);
        $this->assertCount(1, $violations);

        $obj->enumNumber = 'five';
        $violations = Validator::validate($obj);
        $this->assertCount(2, $violations);

        // $obj->intEnum = 3; -> TypeError anyway...
    }

    public function test_enum_validation_exception_both_option_provided(): void
    {
        $class = new class () {
            #[Enum(values: ['one', 'two', 'three'], class: StringEnum::class)]
            public string $number = 'one';
        };

        $obj = new $class();

        $this->expectException(StructuredOutputException::class);

        Validator::validate($obj);
    }

    public function test_enum_validation_exception_no_option_provided(): void
    {
        $class = new class () {
            #[Enum()]
            public string $number = 'one';
        };

        $obj = new $class();

        $this->expectException(StructuredOutputException::class);

        Validator::validate($obj);
    }

    public function test_choice_validation_exception_invalid_enum(): void
    {
        $class = new class () {
            #[Enum(class: Person::class)]
            public string $number = 'one';
        };

        $obj = new $class();

        $this->expectException(StructuredOutputException::class);

        Validator::validate($obj);
    }

    public function test_enum_validation_exception_enum_non_backed(): void
    {
        $class = new class () {
            #[Enum(class: DummyEnum::class)]
            public string $number = 'one';
        };

        $obj = new $class();

        $this->expectException(StructuredOutputException::class);

        Validator::validate($obj);
    }

    public function test_words_count_validation(): void
    {
        $class = new class () {
            #[WordsCount(exactly: 3)]
            public string $text = 'hello world test';
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->text = 'hello world';
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class->text = 'hello world test extra';
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class = new class () {
            #[WordsCount(min: 2)]
            public string $text = 'hello world';
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->text = 'hello';
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);

        $class = new class () {
            #[WordsCount(max: 2)]
            public string $text = 'hello world';
        };
        $class = new $class();

        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->text = 'hello world test';
        $violations = Validator::validate($class);
        $this->assertCount(1, $violations);
    }
}
