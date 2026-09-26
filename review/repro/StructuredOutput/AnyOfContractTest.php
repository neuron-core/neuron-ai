<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\AnyOfCollision\Blog {
    class Item
    {
        public string $title;
    }
}

namespace NeuronAI\Tests\StructuredOutput\AnyOfCollision\Shop {
    class Item
    {
        public float $price;
    }
}

namespace NeuronAI\Tests\StructuredOutput\AnyOfCollision {
    use NeuronAI\StructuredOutput\SchemaProperty;

    enum Priority: string
    {
        case Low = 'low';
        case High = 'high';
    }

    class Note
    {
        public string $text;
    }

    class Journal
    {
        /** @var array<Note|Priority> */
        #[SchemaProperty(anyOf: [Note::class, Priority::class])]
        public array $entries;
    }

    class Catalog
    {
        /** @var array<Blog\Item|Shop\Item> */
        #[SchemaProperty(anyOf: [Blog\Item::class, Shop\Item::class])]
        public array $items;
    }

    class WithTypo
    {
        /** @var array<Note> */
        #[SchemaProperty(anyOf: [Note::class, 'NeuronAI\Tests\StructuredOutput\AnyOfCollision\Nope'])]
        public array $entries;
    }
}

namespace NeuronAI\Tests\StructuredOutput {
    use NeuronAI\StructuredOutput\Deserializer\Deserializer;
    use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
    use NeuronAI\StructuredOutput\JsonSchema;
    use NeuronAI\StructuredOutput\StructuredOutputException;
    use NeuronAI\Tests\StructuredOutput\AnyOfCollision\Catalog;
    use NeuronAI\Tests\StructuredOutput\AnyOfCollision\Journal;
    use NeuronAI\Tests\StructuredOutput\AnyOfCollision\WithTypo;
    use PHPUnit\Framework\TestCase;

    class AnyOfContractTest extends TestCase
    {
        public function test_enum_any_of_member_is_rejected_at_schema_generation(): void
        {
            $this->expectException(StructuredOutputException::class);
            $this->expectExceptionMessage("anyOf member 'NeuronAI\\Tests\\StructuredOutput\\AnyOfCollision\\Priority' must be a class");

            (new JsonSchema())->generate(Journal::class);
        }

        public function test_unknown_any_of_member_is_rejected_at_schema_generation(): void
        {
            $this->expectException(StructuredOutputException::class);
            $this->expectExceptionMessage("anyOf member 'NeuronAI\\Tests\\StructuredOutput\\AnyOfCollision\\Nope' must be a class");

            (new JsonSchema())->generate(WithTypo::class);
        }

        public function test_colliding_discriminator_values_are_rejected_at_schema_generation(): void
        {
            $this->expectException(StructuredOutputException::class);
            $this->expectExceptionMessage("anyOf members share the discriminator value 'item'");

            (new JsonSchema())->generate(Catalog::class);
        }

        public function test_non_object_multi_type_item_is_a_deserializer_exception_the_agent_can_retry(): void
        {
            $this->expectException(DeserializerException::class);
            $this->expectExceptionMessage('Multi-type array items must be objects, got string');

            Deserializer::make()->fromJson('{"entries": [{"__classname__": "note", "text": "hi"}, "high"]}', Journal::class);
        }
    }
}
