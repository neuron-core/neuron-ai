<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use JsonException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\MetadataMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

use const INF;

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
            ->addMetadata('nested', ['published' => true, 'title' => 'Città "quoted"']);

        $storage = MetadataMapper::toStorage($source, $schema);

        $this->assertSame([
            '_neuron_metadata' => '{"tenant":"acme","year":2026,"nested":{"published":true,"title":"Citt\\u00e0 \"quoted\""}}',
            'tenant' => 'acme',
            'year' => 2026,
        ], $storage);

        $hydrated = new Document('Hydrated');
        MetadataMapper::hydrate($hydrated, $storage);

        $this->assertSame($source->getMetadata(), $hydrated->getMetadata());
    }

    public function test_projects_only_filterable_fields_with_a_value(): void
    {
        $schema = DocumentSchema::of(
            DocumentField::string('tenant')->filterable(),
            DocumentField::string('notes'),
            DocumentField::integer('year')->filterable(),
        );
        $document = (new Document('Source'))->setMetadata(['tenant' => null, 'notes' => 'private']);

        $this->assertSame(
            ['_neuron_metadata' => '{"tenant":null,"notes":"private"}'],
            MetadataMapper::toStorage($document, $schema),
        );
    }

    public function test_empty_metadata_is_stored_as_an_empty_json_list_and_hydrates_back_to_empty(): void
    {
        $storage = MetadataMapper::toStorage(new Document('Source'), DocumentSchema::default());
        $this->assertSame(['_neuron_metadata' => '[]'], $storage);

        $document = (new Document('Hydrated'))->addMetadata('stale', 'value');
        MetadataMapper::hydrate($document, $storage);

        $this->assertSame([], $document->getMetadata());
    }

    public function test_metadata_that_cannot_be_encoded_fails_loudly(): void
    {
        $this->expectException(JsonException::class);

        MetadataMapper::toStorage((new Document('Source'))->addMetadata('ratio', INF), DocumentSchema::default());
    }

    public function test_payload_wins_over_projected_fields(): void
    {
        $document = new Document('Hydrated');

        MetadataMapper::hydrate($document, [
            '_neuron_metadata' => '{"tenant":"acme"}',
            'tenant' => 'tampered',
            'injected' => 'value',
        ]);

        $this->assertSame(['tenant' => 'acme'], $document->getMetadata());
    }

    public function test_hydrates_legacy_flat_metadata_without_reserved_fields(): void
    {
        $document = new Document('Legacy');

        MetadataMapper::hydrate($document, [
            'id' => 'x',
            'content' => 'Legacy',
            'embedding' => [1],
            'score' => 0.5,
            'sourceType' => 'manual',
            'sourceName' => 'manual',
            'metadata' => '{}',
            '_vectors' => [],
            '_rankingScore' => 0.9,
            'vector_distance' => 0.1,
            'tenant' => 'acme',
        ]);

        $this->assertSame(['tenant' => 'acme'], $document->getMetadata());
    }

    /**
     * @return array<string, array{mixed, class-string<Throwable>, string}>
     */
    public static function corruptedPayloads(): array
    {
        return [
            'not a string' => [['tenant' => 'acme'], VectorStoreException::class, 'Stored document metadata payload must be a JSON string.'],
            'null' => [null, VectorStoreException::class, 'Stored document metadata payload must be a JSON string.'],
            'invalid json' => ['{"tenant":', VectorStoreException::class, 'Stored document metadata payload contains invalid JSON.'],
            'json scalar' => ['"acme"', VectorStoreException::class, 'Stored document metadata payload must decode to an object.'],
            'json list' => ['["acme"]', DocumentSchemaException::class, 'Document metadata must be an associative array with string keys.'],
            'reserved key' => ['{"score":1}', DocumentSchemaException::class, 'Document metadata field "score" is reserved by the framework.'],
            'empty key' => ['{"":1}', DocumentSchemaException::class, 'Document metadata field name cannot be empty.'],
        ];
    }

    /**
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('corruptedPayloads')]
    public function test_corrupted_or_forged_payloads_are_rejected(mixed $payload, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        MetadataMapper::hydrate(new Document('Hydrated'), ['_neuron_metadata' => $payload]);
    }

    public function test_invalid_json_keeps_the_decoding_error_as_previous(): void
    {
        try {
            MetadataMapper::hydrate(new Document('Hydrated'), ['_neuron_metadata' => '{']);
            $this->fail('Invalid JSON must be rejected.');
        } catch (VectorStoreException $exception) {
            $this->assertInstanceOf(JsonException::class, $exception->getPrevious());
        }
    }
}
