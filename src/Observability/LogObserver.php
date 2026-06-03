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

    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
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

        return $this->serializeObject($data);
    }

    /**
     * Override this method in child classes to add or change serialization behaviour.
     */
    protected function serializeObject(object $data): array
    {
        return match ($data::class) {
            AgentError::class             => $this->serializeAgentError($data),
            Deserializing::class,
            Deserialized::class           => $this->serializeDeserializing($data),
            Extracted::class              => $this->serializeExtracted($data),
            Extracting::class,
            InferenceStart::class,
            MessageSaving::class,
            MessageSaved::class           => $this->serializeWithMessage($data),
            InferenceStop::class          => $this->serializeInferenceStop($data),
            InstructionsChanging::class   => $this->serializeInstructionsChanging($data),
            InstructionsChanged::class    => $this->serializeInstructionsChanged($data),
            ToolCalling::class,
            ToolCalled::class             => $this->serializeWithTool($data),
            Validating::class             => $this->serializeValidating($data),
            Events\Validated::class       => $this->serializeValidated($data),
            SchemaGeneration::class       => $this->serializeSchemaGeneration($data),
            SchemaGenerated::class        => $this->serializeSchemaGenerated($data),
            PreProcessing::class          => $this->serializePreProcessing($data),
            PreProcessed::class           => $this->serializePreProcessed($data),
            PostProcessing::class         => $this->serializePostProcessing($data),
            PostProcessed::class          => $this->serializePostProcessed($data),
            Retrieving::class             => $this->serializeRetrieving($data),
            Retrieved::class              => $this->serializeRetrieved($data),
            WorkflowNodeStart::class,
            WorkflowNodeEnd::class        => $this->serializeWorkflowNode($data),
            MiddlewareStart::class        => $this->serializeMiddlewareStart($data),
            MiddlewareEnd::class          => $this->serializeMiddlewareEnd($data),
            WorkflowStart::class          => $this->serializeWorkflowStart($data),
            WorkflowEnd::class            => $this->serializeWorkflowEnd($data),
            default                       => [],
        };
    }

    /** @return array<string, mixed> */
    protected function serializeAgentError(AgentError $data): array
    {
        return ['error' => $data->exception->getMessage()];
    }

    /** @return array<string, mixed> */
    protected function serializeDeserializing(Deserializing|Deserialized $data): array
    {
        return ['class' => $data->class];
    }

    /** @return array<string, mixed> */
    protected function serializeExtracted(Extracted $data): array
    {
        return [
            'message' => $data->message->jsonSerialize(),
            'schema'  => $data->schema,
            'json'    => $data->json,
        ];
    }

    /** @return array<string, mixed> */
    protected function serializeWithMessage(
        Extracting|InferenceStart|MessageSaving|MessageSaved $data
    ): array {
        return ['message' => $data->message->jsonSerialize()];
    }

    /** @return array<string, mixed> */
    protected function serializeInferenceStop(InferenceStop $data): array
    {
        return [
            'message'  => $data->message->jsonSerialize(),
            'response' => $data->response->jsonSerialize(),
        ];
    }

    /** @return array<string, mixed> */
    protected function serializeInstructionsChanging(InstructionsChanging $data): array
    {
        return ['instructions' => $data->instructions];
    }

    /** @return array<string, mixed> */
    protected function serializeInstructionsChanged(InstructionsChanged $data): array
    {
        return [
            'previous' => $data->previous,
            'current'  => $data->current,
        ];
    }

    /** @return array<string, mixed> */
    protected function serializeWithTool(ToolCalling|ToolCalled $data): array
    {
        return ['tool' => $data->tool->jsonSerialize()];
    }

    /** @return array<string, mixed> */
    protected function serializeValidating(Validating $data): array
    {
        return [
            'class' => $data->class,
            'json'  => $data->json,
        ];
    }

    /** @return array<string, mixed> */
    protected function serializeValidated(Events\Validated $data): array
    {
        return [
            'class'      => $data->class,
            'json'       => $data->json,
            'violations' => $data->violations,
        ];
    }

    /** @return array<string, mixed> */
    protected function serializeSchemaGeneration(SchemaGeneration $data): array
    {
        return ['class' => $data->class];
    }

    /** @return array<string, mixed> */
    protected function serializeSchemaGenerated(SchemaGenerated $data): array
    {
        return [
            'class'  => $data->class,
            'schema' => $data->schema,
        ];
    }

    /** @return array<string, mixed> */
    protected function serializePreProcessing(PreProcessing $data): array
    {
        return [
            'processor' => $data->processor,
            'original'  => $data->original->jsonSerialize(),
        ];
    }

    /** @return array<string, mixed> */
    protected function serializePreProcessed(PreProcessed $data): array
    {
        return [
            'processor' => $data->processor,
            'processed' => $data->processed->jsonSerialize(),
        ];
    }

    /** @return array<string, mixed> */
    protected function serializePostProcessing(PostProcessing $data): array
    {
        return [
            'processor' => $data->processor,
            'question'  => $data->question->jsonSerialize(),
            'documents' => $data->documents,
        ];
    }

    /** @return array<string, mixed> */
    protected function serializePostProcessed(PostProcessed $data): array
    {
        return [
            'processor' => $data->processor,
            'question'  => $data->question->jsonSerialize(),
            'documents' => $data->documents,
        ];
    }

    /** @return array<string, mixed> */
    protected function serializeRetrieving(Retrieving $data): array
    {
        return ['question' => $data->question->jsonSerialize()];
    }

    /** @return array<string, mixed> */
    protected function serializeRetrieved(Retrieved $data): array
    {
        return [
            'question'  => $data->question->jsonSerialize(),
            'documents' => $data->documents,
        ];
    }

    /** @return array<string, mixed> */
    protected function serializeWorkflowNode(WorkflowNodeStart|WorkflowNodeEnd $data): array
    {
        return ['node' => $data->node];
    }

    /** @return array<string, mixed> */
    protected function serializeMiddlewareStart(MiddlewareStart $data): array
    {
        return [
            'class'      => $data->middleware::class,
            'node-event' => $data->event::class,
        ];
    }

    /** @return array<string, mixed> */
    protected function serializeMiddlewareEnd(MiddlewareEnd $data): array
    {
        return ['class' => $data->middleware::class];
    }

    /** @return list<array<string, class-string<\NeuronAI\Workflow\NodeInterface>>> */
    protected function serializeWorkflowStart(WorkflowStart $data): array
    {
        return array_map(
            fn (string $eventClass, NodeInterface $node): array => [$eventClass => $node::class],
            array_keys($data->eventNodeMap),
            array_values($data->eventNodeMap),
        );
    }

    /** @return array<string, mixed> */
    protected function serializeWorkflowEnd(WorkflowEnd $data): array
    {
        return ['state' => $data->state->all()];
    }
}
