<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use NeuronAI\Observability\Events\PostProcessed;
use NeuronAI\Observability\Events\PostProcessing;
use NeuronAI\Observability\Events\SchemaGenerated;
use NeuronAI\Observability\Events\SchemaGeneration;
use NeuronAI\Workflow\NodeInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Credits: https://github.com/sixty-nine
 */
class LogObserver implements \SplObserver
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function update(\SplSubject $subject, ?string $event = null, mixed $data = null): void
    {
        if ($event !== null) {
            $this->logger->log(LogLevel::INFO, $event, $this->serializeData($data));
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeData(mixed $data): array
    {
        if ($data === null) {
            return [];
        }

        if (\is_array($data)) {
            return $data;
        }

        if (!\is_object($data)) {
            return ['data' => $data];
        }

        return match ($data::class) {
            Events\AgentError::class => [
                'error' => $data->exception->getMessage(),
            ],
            Events\Deserializing::class,
            Events\Deserialized::class => [
                'class' => $data->class
            ],
            Events\Extracted::class => [
                'message' => $data->message->jsonSerialize(),
                'schema' => $data->schema,
                'json' => $data->json,
            ],
            Events\Extracting::class,
            Events\InferenceStart::class,
            Events\MessageSaving::class,
            Events\MessageSaved::class => [
                'message' => $data->message->jsonSerialize(),
            ],
            Events\InferenceStop::class => [
                'message' => $data->message->jsonSerialize(),
                'response' => $data->response->jsonSerialize(),
            ],
            Events\InstructionsChanging::class => [
                'instructions' => $data->instructions,
            ],
            Events\InstructionsChanged::class => [
                'previous' => $data->previous,
                'current' => $data->current,
            ],
            Events\ToolCalling::class,
            Events\ToolCalled::class => [
                'tool' => $data->tool->jsonSerialize(),
            ],
            Events\Validating::class => [
                'class' => $data->class,
                'json' => $data->class,
            ],
            Events\Validated::class => [
                'class' => $data->class,
                'json' => $data->class,
                'violations' => $data->violations,
            ],
            Events\Retrieving::class => [
                'question' => $data->question->jsonSerialize(),
            ],
            Events\Retrieved::class => [
                'question' => $data->question->jsonSerialize(),
                'documents' => $data->documents,
            ],
            SchemaGeneration::class => [
                'class' => $data->class,
            ],
            SchemaGenerated::class => [
                'class' => $data->class,
                'schema' => $data->schema,
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
            Events\WorkflowStart::class => \array_map(fn (string $eventClass, NodeInterface $node): array => [
                $eventClass => $node::class,
            ], \array_keys($data->eventNodeMap), \array_values($data->eventNodeMap)),
            Events\WorkflowNodeStart::class => [
                'node' => $data->node,
            ],
            Events\WorkflowNodeEnd::class => [
                'node' => $data->node,
            ],
            Events\WorkflowEnd::class => [
                'state' => $data->state->all(),
            ],
            default => [],
        };
    }
}
