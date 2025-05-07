<?php

namespace NeuronAI\Observability;

use GuzzleHttp\Exception\RequestException;
use Inspector\Inspector;
use Inspector\Models\Segment;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

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

    const SEGMENT_TYPE = 'neuron';
    const SEGMENT_COLOR = '#506b9b';

    /**
     * @var array<string, Segment>
     */
    protected $segments = [];

    /**
     * @param Inspector $inspector The monitoring instance
     * @param bool $catch Report internal agent errors
     */
    public function __construct(
        protected Inspector $inspector,
        protected bool $catch = true
    ) {}

    public function update(\SplSubject $subject, string $event = null, $data = null): void
    {
        $methods = [
            'error' => 'reportError',
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

        if (!\is_null($event) && \array_key_exists($event, $methods)) {
            $method = $methods[$event];
            $this->$method($subject, $event, $data);
        }
    }

    public function reportError(\NeuronAI\AgentInterface $agent, string $event, AgentError $data)
    {
        if ($this->catch) {
            $error = $this->inspector->reportException($data->exception, !$data->unhandled);
            if ($data->exception instanceof RequestException) {
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

        $entity = $this->getEventEntity($event);
        $class = get_class($agent);

        if ($this->inspector->needTransaction()) {
            $this->inspector->startTransaction($class);
        } elseif ($this->inspector->canAddSegments() && $entity !== 'chat') {
            $this->segments[
                $entity.$class
            ] = $this->inspector->startSegment(self::SEGMENT_TYPE.'-'.$entity, $entity.':'.$class)
                ->setColor(self::SEGMENT_COLOR);
        }
    }

    public function stop(\NeuronAI\AgentInterface $agent, string $event, $data = null)
    {
        $entity = $this->getEventEntity($event);
        $class = get_class($agent);

        if (\array_key_exists($entity.$class, $this->segments)) {
            $this->segments[$entity.$class]
                ->setContext($this->getContext($agent))
                ->end();
        } elseif ($this->inspector->canAddSegments()) {
            $this->inspector->transaction()->setContext($this->getContext($agent));
        }
    }

    public function getEventEntity(string $event): string
    {
        return explode('-', $event)[0];
    }

    protected function getContext(\NeuronAI\AgentInterface $agent): array
    {
        return [
            'Agent' => [
                'instructions' => $agent->instructions(),
                'provider' => get_class($agent->resolveProvider()),
            ],
            'Tools' => \array_map(function (Tool $tool) {
                return [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'properties' => \array_map(function (ToolProperty $property) {
                        return $property->jsonSerialize();
                    }, $tool->getProperties()),
                ];
            }, $agent->getTools()??[]),
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
