<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\RAG\Document;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

class DocumentTest extends TestCase
{
    public function test_add_metadata_accepts_json_safe_types(): void
    {
        $metadata = [
            'string' => 'value',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'array' => ['a', 'b'],
            'null' => null,
        ];

        $document = new Document('Hello!');
        foreach ($metadata as $key => $value) {
            $document->addMetadata($key, $value);
        }

        $this->assertSame($metadata, $document->metadata);
    }

    public function test_metadata_survives_json_round_trip(): void
    {
        $document = new Document('Hello!');
        $document->addMetadata('string', 'value')
            ->addMetadata('int', 42)
            ->addMetadata('float', 3.14)
            ->addMetadata('bool', true)
            ->addMetadata('array', ['a', 'b'])
            ->addMetadata('null', null);

        // Vector stores persist metadata as JSON and re-add each
        // decoded value through addMetadata() on retrieval.
        $decoded = json_decode(json_encode($document->jsonSerialize()), true);

        $hydrated = new Document($decoded['content']);
        foreach ($decoded['metadata'] as $key => $value) {
            $hydrated->addMetadata($key, $value);
        }

        $this->assertSame($document->metadata, $hydrated->metadata);
    }
}
