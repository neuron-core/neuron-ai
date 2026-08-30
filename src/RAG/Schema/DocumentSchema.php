<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Schema;

use NeuronAI\RAG\Document;
use JsonException;

use function array_key_exists;
use function array_is_list;
use function array_values;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function in_array;

use const JSON_THROW_ON_ERROR;

final class DocumentSchema
{
    public const RESERVED_FIELDS = [
        'id',
        'content',
        'embedding',
        'score',
        'sourceType',
        'sourceName',
        'metadata',
        '_neuron_metadata',
        '_vectors',
        '_rankingScore',
        'vector_distance',
    ];

    /**
     * @var array<string, DocumentField>
     */
    protected array $fields = [];

    protected function __construct(DocumentField ...$fields)
    {
        foreach ($fields as $field) {
            $name = $field->getName();

            if (in_array($name, self::RESERVED_FIELDS, true)) {
                throw new DocumentSchemaException("Document metadata field \"{$name}\" is reserved by the framework.");
            }

            if (isset($this->fields[$name])) {
                throw new DocumentSchemaException("Document metadata field \"{$name}\" is declared more than once.");
            }

            $this->fields[$name] = $field;
        }
    }

    public static function of(DocumentField ...$fields): self
    {
        return new self(...$fields);
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * @return DocumentField[]
     */
    public function fields(): array
    {
        return array_values($this->fields);
    }

    public function getField(string $name): ?DocumentField
    {
        return $this->fields[$name] ?? null;
    }

    public function requireFilterableField(string $name): DocumentField
    {
        if ($name === 'sourceType' || $name === 'sourceName') {
            return DocumentField::string($name)->required()->filterable();
        }

        $field = $this->getField($name);

        if (!$field instanceof DocumentField) {
            throw new DocumentSchemaException(
                "Filter field \"{$name}\" is not declared in the vector store document schema."
            );
        }

        if (!$field->isFilterable()) {
            throw new DocumentSchemaException("Document field \"{$name}\" is not filterable.");
        }

        return $field;
    }

    public function validate(Document $document): void
    {
        $metadata = $document->getMetadata();

        try {
            json_encode($metadata, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DocumentSchemaException("Document {$document->getId()} metadata is not JSON serializable: {$exception->getMessage()}", $exception->getCode(), previous: $exception);
        }

        foreach ($metadata as $name => $value) {
            if (!$this->isJsonSafe($value)) {
                throw new DocumentSchemaException(
                    "Document {$document->getId()} metadata field \"{$name}\" must contain JSON-safe scalar, array, or null values; ".
                    get_debug_type($value).' given.'
                );
            }
        }

        foreach ($this->fields as $field) {
            $name = $field->getName();
            $exists = array_key_exists($name, $metadata) && $metadata[$name] !== null;

            if (!$exists) {
                if ($field->isRequired()) {
                    throw new DocumentSchemaException(
                        "Document {$document->getId()} is missing required metadata field \"{$name}\"."
                    );
                }

                continue;
            }

            if (!$this->valueMatches($field->getType(), $metadata[$name])) {
                throw new DocumentSchemaException(
                    "Document {$document->getId()} metadata field \"{$name}\" expects {$field->getType()->value}; ".
                    get_debug_type($metadata[$name]).' given.'
                );
            }
        }
    }

    public function valueMatches(DocumentFieldType $type, mixed $value): bool
    {
        return match ($type) {
            DocumentFieldType::String => is_string($value),
            DocumentFieldType::Integer => is_int($value),
            DocumentFieldType::Float => is_float($value) || is_int($value),
            DocumentFieldType::Boolean => is_bool($value),
            DocumentFieldType::StringArray => $this->arrayContains($value, is_string(...)),
            DocumentFieldType::IntegerArray => $this->arrayContains($value, is_int(...)),
            DocumentFieldType::FloatArray => $this->arrayContains($value, static fn (mixed $item): bool => is_float($item) || is_int($item)),
            DocumentFieldType::BooleanArray => $this->arrayContains($value, is_bool(...)),
        };
    }

    protected function arrayContains(mixed $value, callable $validator): bool
    {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!$validator($item)) {
                return false;
            }
        }

        return true;
    }

    protected function isJsonSafe(mixed $value): bool
    {
        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!$this->isJsonSafe($item)) {
                return false;
            }
        }

        return true;
    }
}
