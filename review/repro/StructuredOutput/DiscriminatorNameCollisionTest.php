<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput;

use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\StructuredOutputException;
use NeuronAI\Tests\StructuredOutput\Stub\FtpMode;
use PHPUnit\Framework\TestCase;

use function json_encode;

class DiscriminatorNameCollisionTest extends TestCase
{
    public function test_a_class_property_named_like_the_discriminator_is_rejected(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [KindedItem::class, FtpMode::class])]
            public array $items;
        };

        $this->expectException(StructuredOutputException::class);
        $this->expectExceptionMessage("The property 'kind' of 'kindeditem' collides with the anyOf discriminator field");

        (new JsonSchema('kind'))->generate($class::class);
    }

    public function test_injected_required_list_encodes_as_a_json_array(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [KindedItem::class, FtpMode::class])]
            public array $items;
        };

        $item = (new JsonSchema('type'))->generate($class::class)['properties']['items']['items']['anyOf'][0];

        $this->assertSame(['kindeditem'], $item['properties']['type']['enum']);
        $this->assertSame('["type","kind","label"]', json_encode($item['required']));
    }
}

class KindedItem
{
    public string $kind;

    public string $label;
}
