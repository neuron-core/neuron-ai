<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Integration\Frontend\Stub;

use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use PDO;

use function array_map;
use function array_reverse;
use function is_string;
use function json_encode;

/**
 * Deterministic provider: the reply is chosen from the inference input itself,
 * never from a request counter, and every invocation is persisted so tests can
 * detect extra or replayed inference across HTTP requests.
 */
class ScenarioProvider implements AIProviderInterface
{
    use HandleWithTools;

    public const DEFERRED_TITLE = 'deferred-title';

    public function __construct(
        protected PDO $pdo,
        protected string $threadId,
        protected string $scenario,
    ) {
    }

    public function getModel(): string
    {
        return 'scenario';
    }

    public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
    {
        return $this;
    }

    public function chat(Message ...$messages): ProviderResponse
    {
        return new ProviderResponse(message: $this->respond('chat', $messages));
    }

    public function stream(Message ...$messages): Generator
    {
        $response = $this->respond('stream', $messages);
        return $this->streamChunks($response);
    }

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed> $response_schema
     */
    public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
    {
        throw new ProviderException('The frontend fixture does not use structured output.');
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        return $this;
    }

    /** @return Generator<int, TextChunk, mixed, ProviderResponse> */
    protected function streamChunks(Message $response): Generator
    {
        $text = $response->getContent() ?? '';
        if ($text !== '') {
            yield new TextChunk('scenario_msg', $text);
        }
        return new ProviderResponse(message: $response);
    }

    /** @param Message[] $messages */
    protected function respond(string $method, array $messages): Message
    {
        $response = match ($this->scenario) {
            self::DEFERRED_TITLE => $this->deferredTitle($messages),
            default => throw new ProviderException("Unknown scenario '{$this->scenario}'."),
        };
        $this->record($method, $messages, $response);
        return $response;
    }

    /**
     * Phase 1: ask the frontend for the page title, then answer with it.
     * @param Message[] $messages
     */
    protected function deferredTitle(array $messages): Message
    {
        $title = $this->latestResult($messages, 'read_title');
        if ($title === null) {
            return new ToolCallMessage(null, [$this->newToolCall('read_title', 'call_read_title_1', [])]);
        }
        return new AssistantMessage("The page title is: {$title}");
    }

    /** @param Message[] $messages */
    protected function latestResult(array $messages, string $toolName): ?string
    {
        foreach (array_reverse($messages) as $message) {
            if (!$message instanceof ToolResultMessage) {
                continue;
            }
            foreach ($message->getToolCalls() as $call) {
                if ($call->getName() === $toolName && $call->hasResult()) {
                    $result = $call->getResult();
                    return is_string($result) ? $result : json_encode($result, JSON_THROW_ON_ERROR);
                }
            }
        }
        return null;
    }

    /** @param Message[] $messages */
    protected function record(string $method, array $messages, Message $response): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO provider_invocations (thread_id, method, messages, tools, response) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $this->threadId,
            $method,
            json_encode(array_map(fn (Message $message): array => $message->jsonSerialize(), $messages), JSON_THROW_ON_ERROR),
            json_encode(array_map(fn (ToolInterface $tool): string => $tool->getName(), $this->tools), JSON_THROW_ON_ERROR),
            json_encode($response->jsonSerialize(), JSON_THROW_ON_ERROR),
        ]);
    }
}
