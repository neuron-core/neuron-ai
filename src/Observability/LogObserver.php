<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\Deserialized;
use NeuronAI\Observability\Events\Deserializing;
use NeuronAI\Observability\Events\Extracted;
use NeuronAI\Observability\Events\Extracting;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\InstructionsChanged;
use NeuronAI\Observability\Events\InstructionsChanging;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;
use NeuronAI\Observability\Events\MiddlewareEnd;
use NeuronAI\Observability\Events\MiddlewareStart;
use NeuronAI\Observability\Events\MiddlewareBeforeEnd;
use NeuronAI\Observability\Events\MiddlewareBeforeStart;
use NeuronAI\Observability\Events\PostProcessed;
use NeuronAI\Observability\Events\PostProcessing;
use NeuronAI\Observability\Events\PreProcessed;
use NeuronAI\Observability\Events\PreProcessing;
use NeuronAI\Observability\Events\Retrieved;
use NeuronAI\Observability\Events\Retrieving;
use NeuronAI\Observability\Events\SchemaGenerated;
use NeuronAI\Observability\Events\SchemaGeneration;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Observability\Events\Validating;
use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Observability\Events\WorkflowNodeEnd;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Workflow\NodeInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

use function array_keys;
use function array_map;
use function array_values;
use function is_array;
use function is_object;

/**
 * Credits: https://github.com/sixty-nine
 */
class LogObserver implements ObserverInterface
{
    public function __construct(
        protected readonly LoggerInterface $logger,
        protected string $level = LogLevel::INFO
    ) {
    }

    public function onEvent(string $event, object $source, mixed $data = null): void
    {
        $this->logger->log($this->level, $event, $this->serializeData($data));
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeData(mixed $data): array
    {
        if ($data === null) {
            return [];
        }

        if (is_array($data)) {
            return $data;
        }

        if (!is_object($data)) {
            return ['data' => $data];
        }

        return match ($data::class) {
            AgentError::class => [
                'error' => $data->exception->getMessage(),
            ],
            Deserializing::class,
            Deserialized::class => [
                'class' => $data->class
            ],
            Extracted::class => [
                'message' => $data->message->jsonSerialize(),
                'schema' => $data->schema,
                'json' => $data->json,
            ],
            Extracting::class,
            InferenceStart::class,
            MessageSaving::class,
            MessageSaved::class => [
                'message' => $data->message->jsonSerialize(),
            ],
            InferenceStop::class => [
                'message' => $data->message->jsonSerialize(),
                'response' => $data->response->jsonSerialize(),
            ],
            InstructionsChanging::class => [
                'instructions' => $data->instructions,
            ],
            InstructionsChanged::class => [
                'previous' => $data->previous,
                'current' => $data->current,
            ],
            ToolCalling::class,
            ToolCalled::class => [
                'tool' => $data->tool->jsonSerialize(),
            ],
            Validating::class => [
                'class' => $data->class,
                'json' => $data->class,
            ],
            Events\Validated::class => [
                'class' => $data->class,
                'json' => $data->class,
                'violations' => $data->violations,
            ],
            SchemaGeneration::class => [
                'class' => $data->class,
            ],
            SchemaGenerated::class => [
                'class' => $data->class,
                'schema' => $data->schema,
            ],
            PreProcessing::class => [
                'processor' => $data->processor,
                'original' => $data->original->jsonSerialize(),
            ],
            PreProcessed::class => [
                'processor' => $data->processor,
                'processed' => $data->processed->jsonSerialize(),
            ],
            PostProcessing::class => [
                'processor' => $data->processor,
                'question' => $data->question->jsonSerialize(),
                'documents' => $data->documents,
            ],
            PostProcessed::class => [
                'processor' => $data->processor,
                'question' => $data->question->jsonSerialize(),
                'documents' => $data->documents,
            ],
            Retrieving::class => [
                'question' => $data->question->jsonSerialize(),
            ],
            Retrieved::class => [
                'question' => $data->question->jsonSerialize(),
                'documents' => $data->documents,
            ],
            WorkflowNodeStart::class => [
                'node' => $data->node,
            ],
            WorkflowNodeEnd::class => [
                'node' => $data->node,
            ],
            MiddlewareStart::class => [
                'class' => $data->middleware::class,
                'node-event' => $data->event::class,
            ],
            MiddlewareEnd::class => [
                'class' => $data->middleware::class,
            ],
            WorkflowStart::class => array_map(fn (string $eventClass, NodeInterface $node): array => [
                $eventClass => $node::class,
            ], array_keys($data->eventNodeMap), array_values($data->eventNodeMap)),
            WorkflowEnd::class => [
                'state' => $data->state->all(),
            ],
            default => [],
        };
    }
}
