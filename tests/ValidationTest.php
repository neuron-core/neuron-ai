<?php

namespace NeuronAI\Tests;

use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
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
use NeuronAI\StructuredOutput\Validation\Validator;
use NeuronAI\Tests\stubs\models\Person;
use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase
{
    public function test_not_blank_validation()
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

    public function test_not_null_validation()
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

    public function test_is_null_validation()
    {
        $class = new class () {
            #[IsNull]
            public ?string $name;
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

    public function test_url_validation()
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

    public function test_array_of_validation()
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

    public function test_array_of_nested_validation()
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

    public function test_length_validation()
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

    public function test_count_validation()
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


        $class->tags = range(1, 10);
        $violations = Validator::validate($class);
        $this->assertCount(0, $violations);

        $class->tags = range(1, 11);
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

    public function test_email_validation()
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

    public function test_equal_to_validation()
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

    public function test_not_equal_to_validation()
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

    public function test_greater_than_validation()
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

    public function test_greater_than_equal_validation()
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

    public function test_lower_than_validation()
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

    public function test_lower_than_equal_validation()
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

    public function test_is_false_validation()
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

    public function test_is_true_validation()
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

    public function test_ip_address_validation()
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

    public function test_json_validation()
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
}
