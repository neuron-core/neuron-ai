<?php

declare(strict_types=1);

namespace NeuronAI\Tests;

use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\Tests\Stubs\DummyEnum;
use NeuronAI\Tests\Stubs\IntEnum;
use NeuronAI\Tests\Stubs\StructuredOutput\ColorWithDefaults;
use NeuronAI\Tests\Stubs\StructuredOutput\Person;
use NeuronAI\Tests\Stubs\StructuredOutput\Tag;
use NeuronAI\Tests\Stubs\StringEnum;
use NeuronAI\Tests\Stubs\StructuredOutput\TagProperties;
use PHPUnit\Framework\TestCase;

class DeserializerTest extends TestCase
{
    public function test_person_deserializer(): void
    {
        $json = '{"firstName": "John", "lastName": "Doe"}';

        $obj = Deserializer::make()->fromJson($json, Person::class);

        $this->assertInstanceOf(Person::class, $obj);
        $this->assertEquals('John', $obj->firstName);
        $this->assertEquals('Doe', $obj->lastName);
    }

    public function test_person_with_address(): void
    {
        $json = '{"firstName": "John", "lastName": "Doe", "address": {"city": "Rome"}}';

        $obj = Deserializer::make()->fromJson($json, Person::class);

        $this->assertInstanceOf(Person::class, $obj);
        $this->assertEquals('Rome', $obj->address->city);
    }

    public function test_constructor_deserialize_with_default_values(): void
    {
        // Create a new instance from json deserialization, where all properties are optional and have default values (will be black)
        $json = '{}';

        $obj = Deserializer::make()->fromJson($json, ColorWithDefaults::class);

        $this->assertInstanceOf(ColorWithDefaults::class, $obj);
        $this->assertEquals(100, $obj->r);
        $this->assertEquals(100, $obj->g);
        $this->assertEquals(100, $obj->b);
    }

    public function test_constructor_deserialize_with_provided_values(): void
    {
        // Create a new instance, where properties are being provided for a "green" color
        $json = '{"r": 255, "g": 0, "b": 0, "transparency": 100}';

        $obj = Deserializer::make()->fromJson($json, ColorWithDefaults::class);

        $this->assertInstanceOf(ColorWithDefaults::class, $obj);
        $this->assertEquals(255, $obj->r);
        $this->assertEquals(0, $obj->g);
        $this->assertEquals(0, $obj->b);
        $this->assertEquals(100, $obj->transparency);
    }

    public function test_deserialize_array(): void
    {
        $json = '{"firstName": "John", "lastName": "Doe", "tags": [{"name": "agent"}]}';

        $obj = Deserializer::make()->fromJson($json, Person::class);

        $this->assertInstanceOf(Person::class, $obj);
        $this->assertInstanceOf(Tag::class, $obj->tags[0]);
        $this->assertEquals('agent', $obj->tags[0]->name);
    }

    public function test_deserialize_array_with_alternative_syntax(): void
    {
        $json = '{"firstName": "John", "lastName": "Doe", "tags": [{"name": "agent"}]}';

        $obj = Deserializer::make()->fromJson($json, Person::class);

        $this->assertInstanceOf(Person::class, $obj);
        $this->assertInstanceOf(Tag::class, $obj->tags[0]);
        $this->assertEquals('agent', $obj->tags[0]->name);
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
        $this->assertInstanceOf(StringEnum::class, $obj->number);
        $this->assertEquals('one', $obj->number->value);
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
        $this->assertInstanceOf(IntEnum::class, $obj->number);
        $this->assertEquals(1, $obj->number->value);
    }

    public function test_deserialize_invalid_enum(): void
    {
        $class = new class () {
            #[SchemaProperty]
            public DummyEnum $number;
        };

        $json = '{"number": 1}';

        $this->expectException(DeserializerException::class);

        Deserializer::make()->fromJson($json, $class::class);
    }

    public function test_deserialize_invalid_input(): void
    {
        $class = new class () {
            #[SchemaProperty]
            public StringEnum $number;
        };

        $json = '{"number": "kangaroo"}';

        $this->expectException(DeserializerException::class);

        Deserializer::make()->fromJson($json, $class::class);
    }

    public function test_deserialize_null_input(): void
    {
        $class = new class () {
            #[SchemaProperty]
            public StringEnum $number;
        };

        $json = '{"number": null}';

        $obj = Deserializer::make()->fromJson($json, $class::class);
        $this->assertInstanceOf($class::class, $obj);
        $this->assertTrue(! isset($obj->number));
    }
    public function test_deserialize_empty_input(): void
    {
        $class = new class () {
            #[SchemaProperty]
            public StringEnum $number;
        };

        $json = '{}';

        $obj = Deserializer::make()->fromJson($json, $class::class);
        $this->assertInstanceOf($class::class, $obj);
        $this->assertTrue(! isset($obj->number));
    }

    public function test_nested_object_array(): void
    {
        $json = '{"firstName": "John", "lastName": "Doe", "tags": [{"name": "agent", "properties": [{"value": "prop"}]}]}';

        $obj = Deserializer::make()->fromJson($json, Person::class);
        $this->assertInstanceOf(Person::class, $obj);
        $this->assertInstanceOf(Tag::class, $obj->tags[0]);
        $this->assertCount(1, $obj->tags[0]->properties);
        $this->assertInstanceOf(TagProperties::class, $obj->tags[0]->properties[0]);
        $this->assertEquals('prop', $obj->tags[0]->properties[0]->value);
    }

    public function test_deserialize_multi_type_array_with_discriminator(): void
    {
        $class = new class () {
            /**
             * @var \NeuronAI\Tests\Stubs\StructuredOutput\FtpMode[]|\NeuronAI\Tests\Stubs\StructuredOutput\EmailMode[]
             */
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

        // First item should be FtpMode
        $this->assertInstanceOf(\NeuronAI\Tests\Stubs\StructuredOutput\FtpMode::class, $obj->modes[0]);
        $this->assertEquals('ftp', $obj->modes[0]->mode);
        $this->assertEquals('user123', $obj->modes[0]->account);

        // Second item should be EmailMode
        $this->assertInstanceOf(\NeuronAI\Tests\Stubs\StructuredOutput\EmailMode::class, $obj->modes[1]);
        $this->assertEquals('email', $obj->modes[1]->mode);
        $this->assertEquals('list@example.com', $obj->modes[1]->mailingList);

        // Third item should be FtpMode again
        $this->assertInstanceOf(\NeuronAI\Tests\Stubs\StructuredOutput\FtpMode::class, $obj->modes[2]);
        $this->assertEquals('ftp', $obj->modes[2]->mode);
        $this->assertEquals('backup', $obj->modes[2]->account);
    }

    public function test_deserialize_multi_type_array_with_array_syntax(): void
    {
        $class = new class () {
            /**
             * @var array<\NeuronAI\Tests\Stubs\StructuredOutput\ImageBlock|\NeuronAI\Tests\Stubs\StructuredOutput\TextBlock>
             */
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

        // First item should be ImageBlock
        $this->assertInstanceOf(\NeuronAI\Tests\Stubs\StructuredOutput\ImageBlock::class, $obj->blocks[0]);
        $this->assertEquals('image', $obj->blocks[0]->type);
        $this->assertEquals('https://example.com/image.png', $obj->blocks[0]->url);

        // Second item should be TextBlock
        $this->assertInstanceOf(\NeuronAI\Tests\Stubs\StructuredOutput\TextBlock::class, $obj->blocks[1]);
        $this->assertEquals('text', $obj->blocks[1]->type);
        $this->assertEquals('Hello world', $obj->blocks[1]->content);
    }

    public function test_deserialize_multi_type_array_missing_discriminator(): void
    {
        $class = new class () {
            /**
             * @var \NeuronAI\Tests\Stubs\StructuredOutput\FtpMode[]|\NeuronAI\Tests\Stubs\StructuredOutput\EmailMode[]
             */
            public array $modes;
        };

        $json = '{
            "modes": [
                {"mode": "ftp", "account": "user123"}
            ]
        }';

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage('Missing __classname__ discriminator field');

        Deserializer::make()->fromJson($json, $class::class);
    }

    public function test_deserialize_multi_type_array_invalid_discriminator(): void
    {
        $class = new class () {
            /**
             * @var \NeuronAI\Tests\Stubs\StructuredOutput\FtpMode[]|\NeuronAI\Tests\Stubs\StructuredOutput\EmailMode[]
             */
            public array $modes;
        };

        $json = '{
            "modes": [
                {"__classname__": "invalidtype", "mode": "ftp", "account": "user123"}
            ]
        }';

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage('Unknown discriminator value');

        Deserializer::make()->fromJson($json, $class::class);
    }
}
