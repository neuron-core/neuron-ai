<?php

namespace NeuronAI\Observability;

use GuzzleHttp\Exception\RequestException;
use Inspector\Inspector;
use Inspector\Models\Segment;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolPropertyInterface;

/**
 * Trace your AI agent execution flow to detect errors and performance bottlenecks in real-time.
 *
 * Getting started with observability:
 * https://docs.neuron-ai.dev/advanced/observability
 */
class AgentMonitoring implements \SplObserver
{
    use HandleToolEvents;
    use HandleRagEvents;
    use HandleInferenceEvents;
    use HandleStructuredEvents;
    use HandleWorkflowEvents;

    public const SEGMENT_TYPE = 'neuron';
    public const SEGMENT_COLOR = '#506b9b';

    /**
     * @var array<string, Segment>
     */
    protected array $segments = [];

    protected array $methodsMap = [
        'error' => 'reportError',
        'workflow-start' => 'workflowStart',
        'workflow-end' => 'workflowEnd',
        'chat-start' => 'start',
        'chat-stop' => 'stop',
        'stream-start' => 'start',
        'stream-stop' => 'stop',
        'rag-start' => 'start',
        'rag-stop' => 'stop',
        'structured-start' => 'start',
        'structured-stop' => 'stop',
        'message-saving' => 'messageSaving',
        'message-saved' => 'messageSaved',
        'tools-bootstrapping' => 'toolsBootstrapping',
        'tools-bootstrapped' => 'toolsBootstrapped',
        'inference-start' => 'inferenceStart',
        'inference-stop' => 'inferenceStop',
        'tool-calling' => 'toolCalling',
        'tool-called' => 'toolCalled',
        'structured-extracting' => 'extracting',
        'structured-extracted' => 'extracted',
        'structured-deserializing' => 'deserializing',
        'structured-deserialized' => 'deserialized',
        'structured-validating' => 'validating',
        'structured-validated' => 'validated',
        'rag-vectorstore-searching' => 'vectorStoreSearching',
        'rag-vectorstore-result' => 'vectorStoreResult',
        'rag-instructions-changing' => 'instructionsChanging',
        'rag-instructions-changed' => 'instructionsChanged',
        'rag-postprocessing' => 'postProcessing',
        'rag-postprocessed' => 'postProcessed',
    ];

    /**
     * @param Inspector $inspector The monitoring instance
     * @param bool $catch Report internal agent errors
     */
    public function __construct(
        protected Inspector $inspector,
        protected bool $catch = true
    ) {
    }

    public function update(\SplSubject $subject, ?string $event = null, mixed $data = null): void
    {
        if (!\is_null($event) && \array_key_exists($event, $this->methodsMap)) {
            $method = $this->methodsMap[$event];
            $this->$method($subject, $event, $data);
        }
    }

    public function reportError(\NeuronAI\AgentInterface $agent, string $event, AgentError $data)
    {
        if ($this->catch) {
            $error = $this->inspector->reportException($data->exception, !$data->unhandled);
            if ($data->exception instanceof RequestException) {
                // @phpstan-ignore-next-line
                $error->message = $data->exception->getResponse()->getBody()->getContents();
            }
            if ($data->unhandled) {
                $this->inspector->transaction()->setResult('error');
            }
        }
    }

    public function start(\NeuronAI\AgentInterface $agent, string $event, $data = null)
    {
        if (!$this->inspector->isRecording()) {
            return;
        }

        $method = $this->getPrefix($event);
        $class = $agent::class;

        if ($this->inspector->needTransaction()) {
            $this->inspector->startTransaction($class.'::'.$method)->setType('ai-agent');
        } elseif ($this->inspector->canAddSegments()) {
            $key = $class.$method;

            if (\array_key_exists($key, $this->segments)) {
                $key .= '-'.\uniqid();
            }

            $this->segments[$key] = $this->inspector->startSegment(self::SEGMENT_TYPE.'-'.$method, "{$class}::{$method}()")
                ->setColor(self::SEGMENT_COLOR);
        }
    }

    public function stop(\NeuronAI\AgentInterface $agent, string $event, $data = null)
    {
        $method = $this->getPrefix($event);
        $class = $agent::class;

        if (\array_key_exists($class.$method, $this->segments)) {
            // End the last segment for the given method and agent class
            foreach (\array_reverse($this->segments, true) as $key => $value) {
                if ($key === $class.$method) {
                    $value->setContext($this->getContext($agent))->end();
                    unset($this->segments[$key]);
                    break;
                }
            }
        } elseif ($this->inspector->canAddSegments()) {
            $this->inspector->transaction()
                ->setContext($this->getContext($agent))
                ->setResult('success');
        }
    }

    public function getPrefix(string $event): string
    {
        return explode('-', $event)[0];
    }

    protected function getContext(\NeuronAI\AgentInterface $agent): array
    {
        return [
            'Agent' => [
                'instructions' => $agent->instructions(),
                'provider' => $agent->resolveProvider()::class,
            ],
            'Tools' => \array_map(fn (Tool $tool) => [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'properties' => \array_map(fn (ToolPropertyInterface $property) => $property->jsonSerialize(), $tool->getProperties()),
            ], $agent->getTools()),
            //'Messages' => $agent->resolveChatHistory()->getMessages(),
        ];
    }

    public function getMessageId(Message $message): string
    {
        $content = $message->getContent();

        if (!is_string($content)) {
            $content = json_encode($content, JSON_UNESCAPED_UNICODE);
        }

        return \md5($content.$message->getRole());
    }

    protected function getBaseClassName(string $class): string
    {
        return substr(strrchr($class, '\\'), 1);
    }
}
