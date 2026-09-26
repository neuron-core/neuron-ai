<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\MessageDeserializer;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class CitationTest extends TestCase
{
    public function test_to_array_exposes_every_field(): void
    {
        $citation = new Citation('c1', 'https://example.com', 'Example', 3, 9, 'quoted', ['page' => 2]);

        $this->assertSame([
            'id' => 'c1',
            'source' => 'https://example.com',
            'title' => 'Example',
            'start_index' => 3,
            'end_index' => 9,
            'cited_text' => 'quoted',
            'metadata' => ['page' => 2],
        ], $citation->toArray());
        $this->assertSame($citation->toArray(), $citation->jsonSerialize());
    }

    public function test_from_array_restores_what_to_array_produced(): void
    {
        $citation = new Citation('c1', 'doc.pdf', 'Doc', 0, 12, 'Neuron is a…', ['score' => 0.9]);

        $this->assertEquals($citation, Citation::fromArray($citation->toArray()));
    }

    public function test_from_array_defaults_the_optional_fields(): void
    {
        $citation = Citation::fromArray(['id' => 'c1', 'source' => 'doc.pdf']);

        $this->assertNull($citation->title);
        $this->assertNull($citation->startIndex);
        $this->assertNull($citation->endIndex);
        $this->assertNull($citation->citedText);
        $this->assertSame([], $citation->metadata);
    }

    public function test_citations_in_the_message_metadata_come_back_as_citations(): void
    {
        $citations = [new Citation('c1', 'doc.pdf', startIndex: 0, endIndex: 4), new Citation('c2', 'https://example.com')];
        $message = (new AssistantMessage('Neuron'))->addMetadata('citations', $citations);

        $stored = json_decode(json_encode($message, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $restored = (new MessageDeserializer())->deserialize($stored);

        $this->assertEquals($citations, $restored->getMetadata('citations'));
    }
}
