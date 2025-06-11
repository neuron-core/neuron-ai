<?php

namespace NeuronAI\Tests;

use NeuronAI\Tests\stubs\Color;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

class ToolPropertyTest extends TestCase
{
    private ToolProperty $integerProperty;
    private ArrayProperty $arrayProperty;
    private ObjectProperty $mappedObjProperty;
    protected function setUp(): void
    {
        parent::setUp();
        $this->integerProperty = new ToolProperty(
            name: 'tool prop',
            type: PropertyType::INTEGER,
            description: 'desc tool prop',
            required: true,
            enum: ["a", "b", "c"],
        );

        $this->arrayProperty = new ArrayProperty(
            name: 'array prop',
            description: 'desc array prop',
            required: true,
            items: ToolProperty::asNumberItem()
        );

        $this->mappedObjProperty = new ObjectProperty(
            name: 'obj mapped prop',
            description: 'desc obj mapped prop',
            required: true,
            class: Color::class
        );
    }

    public function test_property_type()
    {
        $this->assertInstanceOf(\BackedEnum::class, PropertyType::STRING);

        $this->assertEquals([
            PropertyType::INTEGER,
            PropertyType::STRING,
            PropertyType::NUMBER,
            PropertyType::BOOLEAN,
        ], PropertyType::primitives());
    }

    public function test_tool_property_constructor()
    {
        $tp = new ToolProperty(
            name: 'tool prop',
            type: PropertyType::STRING,
            description: 'desc tool prop',
            required: true,
            enum: ["a", "b", "c"],
        );

        $this->assertInstanceOf(ToolPropertyInterface::class, $tp);
        $this->assertInstanceOf(ToolProperty::class, $tp);
    }

    public function test_tool_property_instance()
    {
        $this->assertInstanceOf(ToolPropertyInterface::class, $this->integerProperty);
        $this->assertInstanceOf(ToolProperty::class, $this->integerProperty);
    }

    public function test_array_property_instance()
    {
        $this->assertInstanceOf(ToolPropertyInterface::class, $this->arrayProperty);
        $this->assertInstanceOf(ArrayProperty::class, $this->arrayProperty);
    }

    public function test_object_property_instance()
    {
        $this->assertInstanceOf(ToolPropertyInterface::class, $this->mappedObjProperty);
        $this->assertInstanceOf(ObjectProperty::class, $this->mappedObjProperty);
    }

    public function test_tool_property_methods()
    {
        $jsonSerialization = [
            'name' => 'tool prop',
            'description' => 'desc tool prop',
            'type' => 'integer',
            'enum' => ["a", "b", "c"],
            'required' => true
        ];

        $jsonSchema = [
            'type' => 'integer',
            'description' => 'desc tool prop',
            'enum' => ["a", "b", "c"],
        ];

        $this->assertEquals('tool prop', $this->integerProperty->getName());
        $this->assertEquals(PropertyType::INTEGER, $this->integerProperty->getType());
        $this->assertEquals('desc tool prop', $this->integerProperty->getDescription());
        $this->assertEquals(["a", "b", "c"], $this->integerProperty->getEnum());
        $this->assertTrue($this->integerProperty->isRequired());
        $this->assertEquals($jsonSerialization, $this->integerProperty->jsonSerialize());
        $this->assertEquals($jsonSchema, $this->integerProperty->getJsonSchema());
    }

    public function test_array_property_methods()
    {
        $this->assertEquals('array prop', $this->arrayProperty->getName());
        $this->assertEquals(PropertyType::ARRAY, $this->arrayProperty->getType());
        $this->assertEquals('desc array prop', $this->arrayProperty->getDescription());
        $this->assertTrue($this->arrayProperty->isRequired());
    }

    public function test_obj_property_methods()
    {
        $this->assertEquals('obj mapped prop', $this->mappedObjProperty->getName());
        $this->assertEquals(PropertyType::OBJECT, $this->mappedObjProperty->getType());
        $this->assertEquals('desc obj mapped prop', $this->mappedObjProperty->getDescription());
        $this->assertTrue($this->mappedObjProperty->isRequired());
    }

    public function test_tool_property_static_methods()
    {
        $arrayItemsMap = [
            'number' => ToolProperty::asNumberItem(),
            'boolean' => ToolProperty::asBooleanItem(),
            'string' => ToolProperty::asStringItem(),
            'integer' => ToolProperty::asIntegerItem()
        ];

        foreach ($arrayItemsMap as $type => $item) {
            $other = ToolProperty::asItem(PropertyType::tryFrom($type));
            $schema = [
                'type' => $type
            ];
            $this->assertInstanceOf(ToolProperty::class, $item);
            $this->assertInstanceOf(ToolProperty::class, $item);
            $this->assertEquals($schema, $item->getJsonSchema());
            $this->assertInstanceOf(ToolProperty::class, $other);
            $this->assertInstanceOf(ToolProperty::class, $other);
            $this->assertEquals($schema, $other->getJsonSchema());
        }
    }

    public function test_tool_property_exception()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid property type: array');

        $fail = ToolProperty::asItem(PropertyType::tryFrom('array'));
    }
}
