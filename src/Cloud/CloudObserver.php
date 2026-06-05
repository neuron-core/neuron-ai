<?php

declare(strict_types=1);

namespace NeuronAI\Cloud;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Observability\Events\WorkflowNodeEnd;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\StaticConstructor;
use NeuronAI\UniqueIdGenerator;
use Throwable;
use TypeError;
use InvalidArgumentException;

use function array_key_exists;
use function hrtime;
use function strrchr;
use function substr;
use function array_unshift;

/**
 * Send agent execution traces to the Neuron Cloud platform.
 *
 * Collects OTEL-inspired spans during agent execution and flushes
 * the complete trace as a single POST request on workflow-end.
 */
class CloudObserver implements ObserverInterface
{
    use StaticConstructor;

    /**
     * @var array<string, string>
     */
    protected array $methodsMap = [
        'error'               => 'handleError',
        'workflow-start'      => 'handleWorkflowStart',
        'workflow-end'        => 'handleWorkflowEnd',
        'inference-start'     => 'handleInferenceStart',
        'inference-stop'      => 'handleInferenceStop',
        'tool-calling'        => 'handleToolCalling',
        'tool-called'         => 'handleToolCalled',
        'workflow-node-start' => 'handleNodeStart',
        'workflow-node-end'   => 'handleNodeEnd',
    ];

    /**
     * In-flight spans keyed by scope identifier.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $spans = [];

    /**
     * Completed spans ready to be flushed.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $completedSpans = [];

    protected string $traceId;

    protected string $rootSpanId;

    protected ?string $workflowClass = null;

    public function __construct(
        protected CloudClient $client,
    ) {
        $this->traceId = UniqueIdGenerator::generateId('trace_');
    }

    public function onEvent(
        string $event,
        object $source,
        mixed $data = null,
        ?string $branchId = null,
    ): void {
        if (!array_key_exists($event, $this->methodsMap)) {
            return;
        }

        $method = $this->methodsMap[$event];
        $this->$method($source, $event, $data, $branchId);
    }

    /**
     * Create a CloudObserver reading the API key from environment.
     *
     * @throws InvalidArgumentException if no API key is provided or found in env
     */
    public static function makeWithKey(?string $apiKey = null): static
    {
        $apiKey ??= $_ENV['NEURON_CLOUD_API_KEY'] ?? null;

        if ($apiKey === null || $apiKey === '') {
            throw new InvalidArgumentException(
                'Neuron Cloud API key is required. Pass it to makeWithKey() or set NEURON_CLOUD_API_KEY env var.'
            );
        }

        return static::make(CloudClient::make($apiKey));
    }

    // -- Workflow events --

    protected function handleWorkflowStart(
        object $source,
        string $event,
        WorkflowStart $data,
        ?string $branchId = null,
    ): void {
        $this->workflowClass = $source::class;
        $this->traceId = UniqueIdGenerator::generateId('trace_');
        $this->rootSpanId = UniqueIdGenerator::generateId('span_');

        $this->spans['workflow'] = [
            'span_id' => $this->rootSpanId,
            'parent_span_id' => null,
            'name' => $source::class,
            'kind' => 'INTERNAL',
            'start_time_unix_nano' => $this->nowNano(),
            'end_time_unix_nano' => null,
            'status' => 'ok',
            'attributes' => [
                'neuron.workflow.class' => $source::class,
            ],
        ];
    }

    protected function handleWorkflowEnd(
        object $source,
        string $event,
        WorkflowEnd $data,
        ?string $branchId = null,
    ): void {
        if (isset($this->spans['workflow'])) {
            $this->spans['workflow']['end_time_unix_nano'] = $this->nowNano();
            // Prepend root span so it's always first in the list
            array_unshift($this->completedSpans, $this->spans['workflow']);
            unset($this->spans['workflow']);
        }

        $this->flush();
    }

    // -- Inference events --

    protected function handleInferenceStart(
        object $source,
        string $event,
        InferenceStart $data,
        ?string $branchId = null,
    ): void {
        $key = 'inference';

        $this->spans[$key] = [
            'span_id' => UniqueIdGenerator::generateId('span_'),
            'parent_span_id' => $this->rootSpanId,
            'name' => 'inference(' . $this->getBaseClassName($data->message::class) . ')',
            'kind' => 'CLIENT',
            'start_time_unix_nano' => $this->nowNano(),
            'end_time_unix_nano' => null,
            'status' => 'ok',
            'attributes' => [
                'neuron.inference.input_role' => $data->message->getRole(),
            ],
        ];
    }

    protected function handleInferenceStop(
        object $source,
        string $event,
        InferenceStop $data,
        ?string $branchId = null,
    ): void {
        $key = 'inference';

        if (!isset($this->spans[$key])) {
            return;
        }

        $this->spans[$key]['end_time_unix_nano'] = $this->nowNano();

        $attrs = &$this->spans[$key]['attributes'];

        if ($data->message instanceof Message) {
            $attrs['neuron.inference.input_role'] = $data->message->getRole();
        }

        $attrs['neuron.inference.output_role'] = $data->response->getRole();
        $attrs['neuron.inference.output_content'] = $data->response->getContent();

        $usage = $data->response->getUsage();
        if ($usage instanceof Usage) {
            $attrs['neuron.inference.usage.input_tokens'] = $usage->inputTokens;
            $attrs['neuron.inference.usage.output_tokens'] = $usage->outputTokens;
        }

        $this->completedSpans[] = $this->spans[$key];
        unset($this->spans[$key]);
    }

    // -- Tool events --

    protected function handleToolCalling(
        object $source,
        string $event,
        ToolCalling $data,
        ?string $branchId = null,
    ): void {
        // Use callId as key to handle parallel tool calls; fall back to tool name.
        $key = 'tool::' . ($data->tool->getCallId() ?? $data->tool->getName());

        $this->spans[$key] = [
            'span_id' => UniqueIdGenerator::generateId('span_'),
            'parent_span_id' => $this->rootSpanId,
            'name' => 'tool_call(' . $data->tool->getName() . ')',
            'kind' => 'INTERNAL',
            'start_time_unix_nano' => $this->nowNano(),
            'end_time_unix_nano' => null,
            'status' => 'ok',
            'attributes' => [
                'neuron.tool.name' => $data->tool->getName(),
            ],
        ];
    }

    protected function handleToolCalled(
        object $source,
        string $event,
        ToolCalled $data,
        ?string $branchId = null,
    ): void {
        $key = 'tool::' . ($data->tool->getCallId() ?? $data->tool->getName());

        if (!isset($this->spans[$key])) {
            return;
        }

        $this->spans[$key]['end_time_unix_nano'] = $this->nowNano();
        $this->spans[$key]['attributes']['neuron.tool.inputs'] = $data->tool->getInputs();

        try {
            $this->spans[$key]['attributes']['neuron.tool.result'] = $data->tool->getResult();
        } catch (TypeError) {
            // Tool may not have executed due to error (e.g. ToolMaxRuns).
        }

        $this->completedSpans[] = $this->spans[$key];
        unset($this->spans[$key]);
    }

    // -- Node events --

    protected function handleNodeStart(
        object $source,
        string $event,
        WorkflowNodeStart $data,
        ?string $branchId = null,
    ): void {
        $key = 'node::' . $data->node;

        $this->spans[$key] = [
            'span_id' => UniqueIdGenerator::generateId('span_'),
            'parent_span_id' => $this->rootSpanId,
            'name' => 'node(' . $this->getBaseClassName($data->node) . ')',
            'kind' => 'INTERNAL',
            'start_time_unix_nano' => $this->nowNano(),
            'end_time_unix_nano' => null,
            'status' => 'ok',
            'attributes' => [
                'neuron.node.class' => $data->node,
            ],
        ];
    }

    protected function handleNodeEnd(
        object $source,
        string $event,
        WorkflowNodeEnd $data,
        ?string $branchId = null,
    ): void {
        $key = 'node::' . $data->node;

        if (!isset($this->spans[$key])) {
            return;
        }

        $this->spans[$key]['end_time_unix_nano'] = $this->nowNano();
        $this->completedSpans[] = $this->spans[$key];
        unset($this->spans[$key]);
    }

    // -- Error --

    protected function handleError(
        object $source,
        string $event,
        AgentError $data,
        ?string $branchId = null,
    ): void {
        // Mark the root span as errored
        if (isset($this->spans['workflow'])) {
            $this->spans['workflow']['status'] = 'error';
        }

        $now = $this->nowNano();

        $this->completedSpans[] = [
            'span_id' => UniqueIdGenerator::generateId('span_'),
            'parent_span_id' => $this->rootSpanId,
            'name' => 'error',
            'kind' => 'INTERNAL',
            'start_time_unix_nano' => $now,
            'end_time_unix_nano' => $now,
            'status' => 'error',
            'attributes' => [
                'neuron.error.message' => $data->exception->getMessage(),
                'neuron.error.class' => $data->exception::class,
            ],
        ];
    }

    // -- Helpers --

    protected function flush(): void
    {
        if ($this->completedSpans === []) {
            return;
        }

        try {
            $this->client->sendTrace([
                'trace_id' => $this->traceId,
                'workflow' => $this->workflowClass,
                'spans' => $this->completedSpans,
            ]);
        } catch (Throwable) {
            // Silently ignore — tracing must never crash the agent.
        }

        $this->completedSpans = [];
    }

    protected function nowNano(): int
    {
        return hrtime(true);
    }

    protected function getBaseClassName(string $class): string
    {
        $portion = strrchr($class, '\\');

        return $portion === false ? $class : substr($portion, 1);
    }
}
