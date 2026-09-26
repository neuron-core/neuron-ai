<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Validation;

use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Validation\Validator;
use NeuronAI\Tests\StructuredOutput\Stub\Address;
use NeuronAI\Tests\StructuredOutput\Stub\Person;
use PHPUnit\Framework\TestCase;

class ValidatorNestedObjectTest extends TestCase
{
    public function test_rules_declared_on_a_nested_object_are_validated(): void
    {
        $person = new Person();
        $person->firstName = 'John';
        $person->tags = [];
        $person->address = new Address();
        $person->address->street = '';
        $person->address->city = 'Rome';
        $person->address->zip = '';

        $this->assertCount(2, Validator::validate($person));
    }

    public function test_blank_nested_fields_returned_by_the_model_are_rejected(): void
    {
        $json = '{"firstName": "John", "lastName": "Doe", "address": {"street": "", "city": "Rome", "zip": ""}, "tags": []}';

        $person = Deserializer::make()->fromJson($json, Person::class);

        $this->assertInstanceOf(Address::class, $person->address);
        $this->assertSame('', $person->address->street);
        $this->assertCount(2, Validator::validate($person));
    }
}
