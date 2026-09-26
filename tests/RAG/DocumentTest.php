<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_unique;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class DocumentTest extends TestCase
{
    public function test_a_new_document_has_manual_source_and_no_runtime_fields(): void
    {
        $document = new Document();

        $this->assertSame('', $document->getContent());
        $this->assertSame('manual', $document->getSourceType());
        $this->assertSame('manual', $document->getSourceName());
        $this->assertSame([], $document->getMetadata());
        $this->assertNull($document->getEmbedding());
        $this->assertNull($document->getScore());
    }

    public function test_each_document_gets_its_own_generated_id(): void
    {
        $ids = array_map(static fn (int $index): string|int => (new Document("Document {$index}"))->getId(), [1, 2, 3]);

        $this->assertIsString($ids[0]);
        $this->assertNotSame('', $ids[0]);
        $this->assertSame($ids, array_unique($ids));
    }

    public function test_an_explicit_id_replaces_the_generated_one(): void
    {
        $this->assertSame(42, (new Document())->setId(42)->getId());
        $this->assertSame('doc-1', (new Document())->setId('doc-1')->getId());
    }

    public function test_runtime_fields_are_absent_until_produced(): void
    {
        $document = new Document('Hello!');

        $document->setEmbedding([1, 2.5])->setScore(0.0);

        $this->assertSame([1.0, 2.5], $document->getEmbedding());
        $this->assertSame(0.0, $document->getScore(), 'A zero score is a produced score, not an absent one.');
    }

    public function test_runtime_fields_can_be_cleared(): void
    {
        $document = (new Document('Hello!'))->setEmbedding([0.5])->setScore(0.7);

        $document->setEmbedding(null)->setScore(null);

        $this->assertNull($document->getEmbedding());
        $this->assertNull($document->getScore());
    }

    public function test_embedding_values_are_normalized_to_floats_in_order(): void
    {
        $document = (new Document())->setEmbedding([3, -1, 0, 0.25]);

        $this->assertSame([3.0, -1.0, 0.0, 0.25], $document->getEmbedding());
    }

    public function test_an_empty_embedding_is_rejected(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Document embedding cannot be empty.');

        (new Document())->setEmbedding([]);
    }

    /** @return iterable<string, array{array<int, mixed>}> */
    public static function nonNumericEmbeddings(): iterable
    {
        yield 'numeric string' => [[0.1, '0.2']];
        yield 'null component' => [[0.1, null]];
        yield 'boolean component' => [[true, 0.2]];
        yield 'nested array' => [[[0.1], 0.2]];
    }

    /** @param array<int, mixed> $embedding */
    #[DataProvider('nonNumericEmbeddings')]
    public function test_non_numeric_embedding_values_are_rejected(array $embedding): void
    {
        $document = (new Document())->setEmbedding([1.0]);

        try {
            $document->setEmbedding($embedding);
            $this->fail('A non-numeric embedding must be rejected.');
        } catch (DocumentSchemaException $exception) {
            $this->assertSame('Document embedding accepts numeric values only.', $exception->getMessage());
        }

        $this->assertSame([1.0], $document->getEmbedding(), 'A rejected embedding must not replace the previous one.');
    }

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

        $this->assertSame($metadata, $document->getMetadata());
    }

    public function test_add_metadata_overwrites_an_existing_key(): void
    {
        $document = (new Document())->addMetadata('lang', 'en')->addMetadata('lang', 'it');

        $this->assertSame(['lang' => 'it'], $document->getMetadata());
    }

    public function test_set_metadata_replaces_the_whole_map(): void
    {
        $document = (new Document())->addMetadata('old', 1)->setMetadata(['new' => 2]);

        $this->assertSame(['new' => 2], $document->getMetadata());
    }

    public function test_metadata_keys_keep_unicode(): void
    {
        $document = (new Document('Città'))->addMetadata('città', 'Roma')->addMetadata('名前', '東京');

        $this->assertSame(['città' => 'Roma', '名前' => '東京'], $document->getMetadata());
    }

    /** @return iterable<string, array{string}> */
    public static function reservedFields(): iterable
    {
        foreach (DocumentSchema::RESERVED_FIELDS as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('reservedFields')]
    public function test_add_metadata_rejects_reserved_fields(string $field): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage("Document metadata field \"{$field}\" is reserved by the framework.");

        (new Document())->addMetadata($field, 'forged');
    }

    #[DataProvider('reservedFields')]
    public function test_set_metadata_rejects_reserved_fields_without_partial_writes(string $field): void
    {
        $document = (new Document())->setMetadata(['tenant' => 'acme']);

        try {
            $document->setMetadata(['lang' => 'en', $field => 'forged']);
            $this->fail("The reserved field {$field} must be rejected.");
        } catch (DocumentSchemaException $exception) {
            $this->assertSame("Document metadata field \"{$field}\" is reserved by the framework.", $exception->getMessage());
        }

        $this->assertSame(['tenant' => 'acme'], $document->getMetadata());
    }

    public function test_reserved_field_check_is_case_sensitive(): void
    {
        $document = (new Document())->addMetadata('Content', 'allowed');

        $this->assertSame(['Content' => 'allowed'], $document->getMetadata());
    }

    public function test_an_empty_metadata_key_is_rejected(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Document metadata field name cannot be empty.');

        (new Document())->addMetadata('', 'value');
    }

    public function test_set_metadata_rejects_an_empty_key(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Document metadata field name cannot be empty.');

        (new Document())->setMetadata(['' => 'value']);
    }

    public function test_set_metadata_rejects_a_list(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Document metadata must be an associative array with string keys.');

        /** @phpstan-ignore-next-line deliberately wrong type */
        (new Document())->setMetadata(['first', 'second']);
    }

    public function test_json_serialization_exposes_every_field(): void
    {
        $document = (new Document('Paris is the capital of France.'))
            ->setId('doc-1')
            ->setSourceType('file')
            ->setSourceName('europe.md')
            ->setEmbedding([0.5, 1])
            ->setScore(0.75)
            ->addMetadata('lang', 'en');

        $this->assertSame([
            'id' => 'doc-1',
            'content' => 'Paris is the capital of France.',
            'embedding' => [0.5, 1.0],
            'sourceType' => 'file',
            'sourceName' => 'europe.md',
            'score' => 0.75,
            'metadata' => ['lang' => 'en'],
        ], $document->jsonSerialize());
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
        $decoded = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        $hydrated = new Document($decoded['content']);
        foreach ($decoded['metadata'] as $key => $value) {
            $hydrated->addMetadata($key, $value);
        }

        $this->assertSame($document->getMetadata(), $hydrated->getMetadata());
    }

    public function test_multibyte_content_survives_json_round_trip(): void
    {
        $content = "Ciao, mondo! こんにちは 🌍\n\t<b>&amp;</b>";
        $document = new Document($content);

        $decoded = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($content, $decoded['content']);
    }
}
