<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\MetadataMapper;
use PHPUnit\Framework\TestCase;

class MetadataMapperTest extends TestCase
{
    public function test_round_trips_opaque_metadata_and_projects_declared_fields(): void
    {
        $schema = DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::integer('year')->filterable(),
        );
        $source = (new Document('Source'))
            ->addMetadata('tenant', 'acme')
            ->addMetadata('year', 2026)
            ->addMetadata('nested', ['published' => true]);

        $storage = MetadataMapper::toStorage($source, $schema);

        $this->assertSame('acme', $storage['tenant']);
        $this->assertSame(2026, $storage['year']);
        $this->assertArrayNotHasKey('nested', $storage);

        $hydrated = new Document('Hydrated');
        MetadataMapper::hydrate($hydrated, $storage);

        $this->assertSame($source->getMetadata(), $hydrated->getMetadata());
    }

    public function test_hydrates_legacy_flat_metadata(): void
    {
        $document = new Document('Legacy');

        MetadataMapper::hydrate($document, [
            'content' => 'Legacy',
            'sourceType' => 'manual',
            'tenant' => 'acme',
            '_rankingScore' => 0.9,
        ]);

        $this->assertSame(['tenant' => 'acme'], $document->getMetadata());
    }
}
