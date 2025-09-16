<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Inspector\Configuration;
use Inspector\Exceptions\InspectorException;
use Inspector\Inspector;
use Inspector\Models\Segment;
use NeuronAI\Agent;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\RAG\RAG;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Tools\ToolPropertyInterface;

/**
 * Trace your AI agent execution flow to detect errors and performance bottlenecks in real-time.
 *
 * Getting started with observability:
 * https://docs.neuron-ai.dev/components/observability
 */
class AgentMonitoring implements \SplObserver
{
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

    protected static ?AgentMonitoring $instance = null;

    /**
     * @param Inspector $inspector The monitoring instance
     */
    public function __construct(
        protected Inspector $inspector,
        protected bool $autoFlush = false,
    ) {
    }


    /**
     * @throws InspectorException
     */
    public static function instance(?string $key = null): AgentMonitoring
    {
        $configuration = new Configuration($key ?? $_ENV['INSPECTOR_INGESTION_KEY']);
        $configuration->setTransport($_ENV['INSPECTOR_TRANSPORT'] ?? 'async');
        $configuration->setMaxItems((int) ($_ENV['INSPECTOR_MAX_ITEMS'] ?? $configuration->getMaxItems()));

        // Split monitoring between agents and workflows.
        if (isset($_ENV['NEURON_SPLIT_MONITORING'])) {
            return new self(new Inspector($configuration), $_ENV['NEURON_AUTOFLUSH'] ?? false);
        }

        if (!self::$instance instanceof AgentMonitoring) {
            self::$instance = new self(new Inspector($configuration), $_ENV['NEURON_AUTOFLUSH'] ?? false);
        }
        return self::$instance;
    }

    public function update(\SplSubject $subject, ?string $event = null, mixed $data = null): void
    {
        if (!\is_null($event) && \array_key_exists($event, $this->methodsMap)) {
            $method = $this->methodsMap[$event];
            $this->$method($subject, $event, $data);
        }
    }

    /**
     * @throws \Exception
     */
    public function start(Agent $agent, string $event, mixed $data = null): void
    {
        if (!$this->inspector->isRecording()) {
            return;
        }

        $method = $this->getPrefix($event);
        $class = $agent::class;

        if ($this->inspector->needTransaction()) {
            $this->inspector->startTransaction($class.'::'.$method)
                ->setType('ai-agent')
                ->setContext($this->getContext($agent));
        } elseif ($this->inspector->canAddSegments() && !$agent instanceof RAG) { // do not add "parent" agent segments on RAG
            $key = $class.$method;

            if (\array_key_exists($key, $this->segments)) {
                $key .= '-'.\uniqid();
            }

            $segment = $this->inspector->startSegment(self::SEGMENT_TYPE.'.'.$method, "{$class}::{$method}")
                ->setColor(self::STANDARD_COLOR);
            $segment->setContext($this->getContext($agent));
            $this->segments[$key] = $segment;
        }
    }

    /**
     * @throws \Exception
     */
    public function stop(Agent $agent, string $event, mixed $data = null): void
    {
        $method = $this->getPrefix($event);
        $class = $agent::class;

        if (\array_key_exists($class.$method, $this->segments)) {
            // End the last segment for the given method and agent class
            foreach (\array_reverse($this->segments, true) as $key => $segment) {
                if ($key === $class.$method) {
                    $segment->setContext($this->getContext($agent));
                    $segment->end();
                    unset($this->segments[$key]);
                    break;
                }
            }
        } elseif ($this->inspector->canAddSegments()) {
            $transaction = $this->inspector->transaction()->setResult('success');
            $transaction->setContext($this->getContext($agent));

            if ($this->autoFlush) {
                $this->inspector->flush();
            }
        }
    }

    /**
     * @throws \Exception
     */
    public function reportError(\SplSubject $subject, string $event, AgentError $data): void
    {
        $this->inspector->reportException($data->exception, !$data->unhandled);

        if ($data->unhandled) {
            $this->inspector->transaction()->setResult('error');
            if ($subject instanceof Agent) {
                $this->inspector->transaction()->setContext($this->getContext($subject));
            }
        }
    }

    public function getPrefix(string $event): string
    {
        return \explode('-', $event)[0];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getContext(Agent $agent): array
    {
        $mapTool = fn (ToolInterface $tool): array => [
            $tool->getName() => [
                'description' => $tool->getDescription(),
                'properties' => \array_map(
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
            'Tools' => \array_map(fn (ToolInterface|ToolkitInterface|ProviderToolInterface $tool) => match (true) {
                $tool instanceof ToolInterface => $mapTool($tool),
                $tool instanceof ToolkitInterface => [$tool::class => \array_map($mapTool, $tool->tools())],
                default => $tool->jsonSerialize(),
            }, $agent->getTools()),
            //'Messages' => $agent->resolveChatHistory()->getMessages(),
        ];
    }

    protected function getBaseClassName(string $class): string
    {
        return \substr(\strrchr($class, '\\'), 1);
    }
}
