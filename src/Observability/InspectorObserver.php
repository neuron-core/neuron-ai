<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Exception;
use Inspector\Configuration;
use Inspector\Exceptions\InspectorException;
use Inspector\Inspector;
use Inspector\Models\Segment;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

use function array_key_exists;
use function array_map;
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
    use HandleToolEvents;
    use HandleRagEvents;
    use HandleInferenceEvents;
    use HandleStructuredEvents;
    use HandleWorkflowEvents;

    public const SEGMENT_TYPE = 'agent';
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

        'workflow-start' => 'workflowStart',
        'workflow-resume' => 'workflowStart',
        'workflow-end' => 'workflowEnd',
        'workflow-node-start' => 'nodeStart',
        'workflow-node-end' => 'nodeEnd',
        'middleware-after-start' => 'middlewareStart',
        'middleware-after-end' => 'middlewareEnd',
        'middleware-before-start' => 'middlewareStart',
        'middleware-before-end' => 'middlewareEnd',

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
    public static function instance(
        ?string $key = null,
        ?string $transport = null,
        ?int $maxItems = null,
        bool $autoFlush = false,
        bool $splitMonitoring = false,
    ): InspectorObserver {
        $configuration = new Configuration($key ?? $_ENV['INSPECTOR_INGESTION_KEY'] ?? null);
        $configuration->setTransport($transport ?? $_ENV['INSPECTOR_TRANSPORT'] ?? 'async');
        $configuration->setMaxItems((int) ($maxItems ?? $_ENV['INSPECTOR_MAX_ITEMS'] ?? $configuration->getMaxItems()));

        if (isset($_ENV['INSPECTOR_URL'])) {
            $configuration->setUrl($_ENV['INSPECTOR_URL']);
        }

        /*
         * Split monitoring between agents and workflows.
         * Since the event bus is static, $instance will be shared between agents and workflows.
         * Splitting monitoring, we drop its usage in favor of a dedicated instance for each class.
         */
        if (isset($_ENV['NEURON_SPLIT_MONITORING']) || $splitMonitoring) {
            return new self(new Inspector($configuration), $_ENV['NEURON_AUTOFLUSH'] ?? $autoFlush);
        }

        if (!self::$instance instanceof InspectorObserver) {
            self::$instance = new self(new Inspector($configuration), $_ENV['NEURON_AUTOFLUSH'] ?? $autoFlush);
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
            if ($source instanceof Agent) {
                $this->inspector->transaction()->setContext($this->getAgentContext($source));
            }
        }

        if ($data->exception instanceof WorkflowInterrupt) {
            $this->inspector->transaction()->addContext("Interrupt", $data->exception->getRequest()->jsonSerialize());
        }
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

    /**
     * @return array<string, mixed>
     */
    protected function getAgentContext(Agent $agent): array
    {
        $mapTool = fn (ToolInterface $tool): array => [
            $tool->getName() => [
                'description' => $tool->getDescription(),
                'properties' => array_map(
                    fn (ToolPropertyInterface $property) => $property->jsonSerialize(),
                    $tool->getProperties()
                )
            ]
        ];

        return [
            'Agent' => [
                'provider' => $agent->resolveProvider()::class,
                'instructions' => $agent->resolveInstructions(),
            ],
            'Tools' => array_map(fn (ToolInterface|ToolkitInterface|ProviderToolInterface $tool) => match (true) {
                $tool instanceof ToolInterface => $mapTool($tool),
                $tool instanceof ToolkitInterface => [$tool::class => array_map($mapTool, $tool->tools())],
                default => $tool->jsonSerialize(),
            }, $agent->getTools()),
        ];
    }

    protected function getBaseClassName(string $class): string
    {
        return substr(strrchr($class, '\\'), 1);
    }
}
