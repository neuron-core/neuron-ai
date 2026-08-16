<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use JsonException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchema;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Stores arbitrary metadata as opaque JSON and projects declared fields into
 * native backend fields for portable filtering.
 */
final class MetadataMapper
{
    public const PAYLOAD_FIELD = '_neuron_metadata';

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    public static function toStorage(Document $document, DocumentSchema $schema): array
    {
        $metadata = $document->getMetadata();
        $storage = [
            self::PAYLOAD_FIELD => json_encode($metadata, JSON_THROW_ON_ERROR),
        ];

        foreach ($schema->fields() as $field) {
            if (!$field->isFilterable()) {
                continue;
            }

            $name = $field->getName();
            if (array_key_exists($name, $metadata) && $metadata[$name] !== null) {
                $storage[$name] = $metadata[$name];
            }
        }

        return $storage;
    }

    /**
     * @param array<string, mixed> $data
     * @throws VectorStoreException
     */
    public static function hydrate(Document $document, array $data): void
    {
        if (array_key_exists(self::PAYLOAD_FIELD, $data)) {
            $payload = $data[self::PAYLOAD_FIELD];

            if (!is_string($payload)) {
                throw new VectorStoreException('Stored document metadata payload must be a JSON string.');
            }

            try {
                $metadata = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new VectorStoreException('Stored document metadata payload contains invalid JSON.', previous: $exception);
            }

            if (!is_array($metadata)) {
                throw new VectorStoreException('Stored document metadata payload must decode to an object.');
            }

            $document->setMetadata($metadata);

            return;
        }

        foreach ($data as $name => $value) {
            if (!in_array($name, DocumentSchema::RESERVED_FIELDS, true)) {
                $document->addMetadata($name, $value);
            }
        }
    }
}
