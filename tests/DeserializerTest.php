<?php

namespace NeuronAI\Tests;

use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\Tests\stubs\models\Person;
use NeuronAI\Tests\stubs\models\Tag;
use PHPUnit\Framework\TestCase;

class DeserializerTest extends TestCase
{
    public function test_person_deserializer()
    {
        $json = '{"firstName": "John", "lastName": "Doe"}';

        $obj = Deserializer::fromJson($json, Person::class);

        $this->assertInstanceOf(Person::class, $obj);
        $this->assertEquals('John', $obj->firstName);
        $this->assertEquals('Doe', $obj->lastName);
    }

    public function test_person_with_address()
    {
        $json = '{"firstName": "John", "lastName": "Doe", "address": {"city": "Rome"}}';

        $obj = Deserializer::fromJson($json, Person::class);

        $this->assertInstanceOf(Person::class, $obj);
        $this->assertEquals('Rome', $obj->address->city);
    }

    public function test_deserialize_array()
    {
        $json = '{"firstName": "John", "lastName": "Doe", "tags": [{"name": "agent"}]}';

        $obj = Deserializer::fromJson($json, Person::class);

        $this->assertInstanceOf(Person::class, $obj);
        $this->assertInstanceOf(Tag::class, $obj->tags[0]);
        $this->assertEquals('agent', $obj->tags[0]->name);
    }
}
