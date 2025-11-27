<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Inspector\Models\Segment;
use NeuronAI\Observability\Events\Deserialized;
use NeuronAI\Observability\Events\Deserializing;
use NeuronAI\Observability\Events\Extracted;
use NeuronAI\Observability\Events\Extracting;
use NeuronAI\Observability\Events\SchemaGenerated;
use NeuronAI\Observability\Events\SchemaGeneration;
use NeuronAI\Observability\Events\Validated;
use NeuronAI\Observability\Events\Validating;

use function json_decode;

trait HandleStructuredEvents
{
    protected Segment $schema;
    protected Segment $extract;
    protected Segment $deserialize;
    protected Segment $validate;

    protected function schemaGeneration(object $source, string $event, SchemaGeneration $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $this->schema = $this->inspector->startSegment(self::SEGMENT_TYPE.'.structured-output', "schema_generate( ".$this->getBaseClassName($data->class)." )")
            ->setColor(self::STANDARD_COLOR);
    }

    protected function schemaGenerated(object $source, string $event, SchemaGenerated $data): void
    {
        if (isset($this->schema)) {
            $this->schema->end();
            $this->schema->addContext('Schema', $data->schema);
        }
    }

    protected function extracting(object $source, string $event, Extracting $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $this->extract = $this->inspector->startSegment(self::SEGMENT_TYPE.'.structured-output', 'extract_output')
            ->setColor(self::STANDARD_COLOR);
    }

    protected function extracted(object $source, string $event, Extracted $data): void
    {
        if (!isset($this->extract)) {
            return;
        }

        $this->extract->end();
        $this->extract->addContext(
            'Data',
            [
                'response' => $data->message->jsonSerialize(),
                'json' => $data->json,
            ]
        )->addContext(
            'Schema',
            $data->schema
        );
    }

    protected function deserializing(object $source, string $event, Deserializing $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $this->deserialize = $this->inspector->startSegment(self::SEGMENT_TYPE.'.structured-output', "deserialize( ".$this->getBaseClassName($data->class)." )")
            ->setColor(self::STANDARD_COLOR);
    }

    protected function deserialized(object $source, string $event, Deserialized $data): void
    {
        if (isset($this->deserialize)) {
            $this->deserialize->addContext('Class', $data->class);
            $this->deserialize->end();
        }
    }

    protected function validating(object $source, string $event, Validating $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $this->validate = $this->inspector->startSegment(self::SEGMENT_TYPE.'.structured-output', "validate( ".$this->getBaseClassName($data->class)." )")
            ->setColor(self::STANDARD_COLOR);
    }

    protected function validated(object $source, string $event, Validated $data): void
    {
        if (isset($this->validate)) {
            $this->validate->end();
            $this->validate->addContext('Json', json_decode($data->json));
            if ($data->violations !== []) {
                $this->validate->addContext('Violations', $data->violations);
            }
        }
    }
}
