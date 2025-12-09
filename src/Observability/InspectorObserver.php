<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Inspector\Configuration;
use Inspector\Exceptions\InspectorException;
use Inspector\Inspector;
use Inspector\Models\Segment;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\Events\AgentError;
use Exception;

use NeuronAI\Workflow\WorkflowInterrupt;
use function array_key_exists;
use function array_map;
use function explode;
use function strrchr;
use function substr;

/**
 * Trace your AI agent execution flow to detect errors and performance bottlenecks in real-time.
 *
 * Getting started with monitoring:
 * https://docs.neuron-ai.dev/the-basics/observability
 */
class InspectorObserver implements ObserverInterface
{
    use HandleAgentEvents;
    use HandleToolEvents;
    use HandleRagEvents;
    use HandleInferenceEvents;
    use HandleStructuredEvents;
    use HandleWorkflowEvents;

    public const SEGMENT_TYPE = 'neuron';
    public const STANDARD_COLOR = '#FF800C';

    /**
     * @var array<string, Segment>
     */
    protected array $segments = [];

    /**
     * @var array<string, string>
     */
    protected array $methodsMap = [
        'error' => 'reportError',
        'chat-start' => 'start',
        'chat-stop' => 'stop',
        'stream-start' => 'start',
        'stream-stop' => 'stop',
        'structured-start' => 'start',
        'structured-stop' => 'stop',
        'chat-rag-start' => 'start',
        'chat-rag-stop' => 'stop',
        'stream-rag-start' => 'start',
        'stream-rag-stop' => 'stop',
        'structured-rag-start' => 'start',
        'structured-rag-stop' => 'stop',

        'message-saving' => 'messageSaving',
        'message-saved' => 'messageSaved',
        'tools-bootstrapping' => 'toolsBootstrapping',
        'tools-bootstrapped' => 'toolsBootstrapped',
        'inference-start' => 'inferenceStart',
        'inference-stop' => 'inferenceStop',
        'tool-calling' => 'toolCalling',
        'tool-called' => 'toolCalled',
        'schema-generation' => 'schemaGeneration',
        'schema-generated' => 'schemaGenerated',
        'structured-extracting' => 'extracting',
        'structured-extracted' => 'extracted',
        'structured-deserializing' => 'deserializing',
        'structured-deserialized' => 'deserialized',
        'structured-validating' => 'validating',
        'structured-validated' => 'validated',
        'rag-retrieving' => 'ragRetrieving',
        'rag-retrieved' => 'ragRetrieved',
        'rag-preprocessing' => 'preProcessing',
        'rag-preprocessed' => 'preProcessed',
        'rag-postprocessing' => 'postProcessing',
        'rag-postprocessed' => 'postProcessed',

        'workflow-start' => 'workflowStart',
        'workflow-resume' => 'workflowStart',
        'workflow-end' => 'workflowEnd',
        'workflow-node-start' => 'workflowNodeStart',
        'workflow-node-end' => 'workflowNodeEnd',
    ];

    protected static ?InspectorObserver $instance = null;

    public function __construct(
        protected Inspector $inspector,
        protected bool $autoFlush = false,
    ) {
    }

    /**
     * @throws InspectorException
     */
    public static function instance(?string $key = null): InspectorObserver
    {
        $configuration = new Configuration($key ?? $_ENV['INSPECTOR_INGESTION_KEY'] ?? '');
        $configuration->setTransport($_ENV['INSPECTOR_TRANSPORT'] ?? 'async');
        $configuration->setMaxItems((int) ($_ENV['INSPECTOR_MAX_ITEMS'] ?? $configuration->getMaxItems()));

        // Split monitoring between agents and workflows.
        if (isset($_ENV['NEURON_SPLIT_MONITORING'])) {
            return new self(new Inspector($configuration), $_ENV['NEURON_AUTOFLUSH'] ?? false);
        }

        if (!self::$instance instanceof InspectorObserver) {
            self::$instance = new self(new Inspector($configuration), $_ENV['NEURON_AUTOFLUSH'] ?? false);
        }

        return self::$instance;
    }

    public function onEvent(string $event, object $source, mixed $data = null): void
    {
        if (array_key_exists($event, $this->methodsMap)) {
            $method = $this->methodsMap[$event];
            $this->$method($source, $event, $data);
        }
    }

    /**
     * @throws Exception
     */
    public function reportError(object $source, string $event, AgentError $data): void
    {
        $this->inspector->reportException($data->exception, !$data->unhandled);

        if ($data->unhandled) {
            $this->inspector->transaction()->setResult('error');
        }

        if ($source instanceof Agent) {
            $this->inspector->transaction()->setContext($this->getAgentContext($source));
        }

        if ($data->exception instanceof WorkflowInterrupt) {
            $this->inspector->transaction()->addContext("Interrupt", $data->exception->getRequest()->jsonSerialize());
        }
    }

    public function getEventPrefix(string $event): string
    {
        return explode('-', $event)[0];
    }

    protected function getBaseClassName(string $class): string
    {
        return substr(strrchr($class, '\\'), 1);
    }

    protected function prepareMessageItem(Message $item): array
    {
        $item = $item->jsonSerialize();
        if (isset($item['content'])) {
            $item['content'] = array_map(function (array $block): array {
                if (isset($block['source_type']) && $block['source_type'] === SourceType::BASE64->value) {
                    unset($block['source']);
                }
                return $block;
            }, $item['content']);
        }

        return $item;
    }
}
