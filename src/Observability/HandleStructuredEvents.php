<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use NeuronAI\AgentInterface;
use NeuronAI\Observability\Events\Deserialized;
use NeuronAI\Observability\Events\Deserializing;
use NeuronAI\Observability\Events\Extracted;
use NeuronAI\Observability\Events\Extracting;
use NeuronAI\Observability\Events\SchemaGenerated;
use NeuronAI\Observability\Events\SchemaGeneration;
use NeuronAI\Observability\Events\Validated;
use NeuronAI\Observability\Events\Validating;

trait HandleStructuredEvents
{
    protected function schemaGeneration(AgentInterface $agent, string $event, SchemaGeneration $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $this->segments[$data->class.'-schema'] = $this->inspector->startSegment(self::SEGMENT_TYPE.'.schema', "schema_generate( {$data->class} )")
            ->setColor(self::SEGMENT_COLOR);
    }

    protected function schemaGenerated(AgentInterface $agent, string $event, SchemaGenerated $data): void
    {
        if (\array_key_exists($data->class.'-schema', $this->segments)) {
            $segment = $this->segments[$data->class.'-schema']->end();
            $segment->addContext('Schema', $data->schema);
        }
    }

    protected function extracting(AgentInterface $agent, string $event, Extracting $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $id = $this->getMessageId($data->message).'-extract';

        $this->segments[$id] = $this->inspector->startSegment(self::SEGMENT_TYPE.'.output-extract', 'extract_output')
            ->setColor(self::SEGMENT_COLOR);
    }

    protected function extracted(AgentInterface $agent, string $event, Extracted $data): void
    {
        $id = $this->getMessageId($data->message).'-extract';

        if (\array_key_exists($id, $this->segments)) {
            $segment = $this->segments[$id]->end();
            $segment->addContext(
                'Data',
                [
                    'response' => $data->message->jsonSerialize(),
                    'json' => $data->json,
                ]
            )->addContext(
                'Schema',
                $data->schema
            );
            unset($this->segments[$id]);
        }
    }

    protected function deserializing(AgentInterface $agent, string $event, Deserializing $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $this->segments[$data->class.'-deserialize'] = $this->inspector->startSegment(self::SEGMENT_TYPE.'.output-deserialize', "deserialize( {$data->class} )")
            ->setColor(self::SEGMENT_COLOR);
    }

    protected function deserialized(AgentInterface $agent, string $event, Deserialized $data): void
    {
        $id = $data->class.'-deserialize';

        if (\array_key_exists($id, $this->segments)) {
            $this->segments[$id]->end();
        }
    }

    protected function validating(AgentInterface $agent, string $event, Validating $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $this->segments[$data->class.'-validate'] = $this->inspector->startSegment(self::SEGMENT_TYPE.'.output-validate', "validate( {$data->class} )")
            ->setColor(self::SEGMENT_COLOR);
    }

    protected function validated(AgentInterface $agent, string $event, Validated $data): void
    {
        $id = $data->class.'-validate';

        if (\array_key_exists($id, $this->segments)) {
            $segment = $this->segments[$id]->end();
            $segment->addContext('Json', \json_decode($data->json));
            if ($data->violations !== []) {
                $segment->addContext('Violations', $data->violations);
            }
        }
    }
}
