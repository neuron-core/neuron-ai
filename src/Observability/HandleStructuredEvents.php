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
    /**
     * Open structured-output segments keyed by "{branchId}:{phase}".
     *
     * @var array<string, Segment>
     */
    protected array $structuredSegments = [];

    protected function schemaGeneration(object $source, string $event, SchemaGeneration $data, ?string $branchId = null): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $key = $branchId.':schema';
        $this->structuredSegments[$key] = $this->resolveScope($branchId)
            ->startSegment(self::SEGMENT_TYPE.'.structured-output', "schema_generate( ".$this->getBaseClassName($data->class)." )")
            ->setColor(self::STANDARD_COLOR);
    }

    protected function schemaGenerated(object $source, string $event, SchemaGenerated $data, ?string $branchId = null): void
    {
        $key = $branchId.':schema';

        if (isset($this->structuredSegments[$key])) {
            $this->structuredSegments[$key]->end();
            $this->structuredSegments[$key]->addContext('Schema', $data->schema);
            unset($this->structuredSegments[$key]);
        }
    }

    protected function extracting(object $source, string $event, Extracting $data, ?string $branchId = null): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $key = $branchId.':extract';
        $this->structuredSegments[$key] = $this->resolveScope($branchId)
            ->startSegment(self::SEGMENT_TYPE.'.structured-output', 'extract_output')
            ->setColor(self::STANDARD_COLOR);
    }

    protected function extracted(object $source, string $event, Extracted $data, ?string $branchId = null): void
    {
        $key = $branchId.':extract';

        if (!isset($this->structuredSegments[$key])) {
            return;
        }

        $this->structuredSegments[$key]->end();
        $this->structuredSegments[$key]->addContext(
            'Data',
            [
                'response' => $data->message->jsonSerialize(),
                'json' => $data->json,
            ]
        )->addContext(
            'Schema',
            $data->schema
        );
        unset($this->structuredSegments[$key]);
    }

    protected function deserializing(object $source, string $event, Deserializing $data, ?string $branchId = null): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $key = $branchId.':deserialize';
        $this->structuredSegments[$key] = $this->resolveScope($branchId)
            ->startSegment(self::SEGMENT_TYPE.'.structured-output', "deserialize( ".$this->getBaseClassName($data->class)." )")
            ->setColor(self::STANDARD_COLOR);
    }

    protected function deserialized(object $source, string $event, Deserialized $data, ?string $branchId = null): void
    {
        $key = $branchId.':deserialize';

        if (isset($this->structuredSegments[$key])) {
            $this->structuredSegments[$key]->addContext('Class', $data->class);
            $this->structuredSegments[$key]->end();
            unset($this->structuredSegments[$key]);
        }
    }

    protected function validating(object $source, string $event, Validating $data, ?string $branchId = null): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $key = $branchId.':validate';
        $this->structuredSegments[$key] = $this->resolveScope($branchId)
            ->startSegment(self::SEGMENT_TYPE.'.structured-output', "validate( ".$this->getBaseClassName($data->class)." )")
            ->setColor(self::STANDARD_COLOR);
    }

    protected function validated(object $source, string $event, Validated $data, ?string $branchId = null): void
    {
        $key = $branchId.':validate';

        if (isset($this->structuredSegments[$key])) {
            $this->structuredSegments[$key]->end();
            $this->structuredSegments[$key]->addContext('Json', json_decode($data->json));
            if ($data->violations !== []) {
                $this->structuredSegments[$key]->addContext('Violations', $data->violations);
            }
            unset($this->structuredSegments[$key]);
        }
    }
}
