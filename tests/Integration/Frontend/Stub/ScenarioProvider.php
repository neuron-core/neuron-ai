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
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use PDO;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_slice;
use function count;
use function json_encode;

/**
 * Deterministic provider driven by a per-scenario plan of tool-call batches.
 * The reply is chosen from the inference input itself, never from a request
 * counter: the first batch whose results are missing is requested, and once
 * every batch is answered the final message echoes what the model received.
 * Every invocation is persisted so tests can detect extra or replayed inference.
 */
class ScenarioProvider implements AIProviderInterface
{
    use HandleWithTools;

    /** @var array<string, list<list<array{string, string, array<string, mixed>}>>> scenario => batches of [tool, callId, inputs] */
    public const PLANS = [
        'deferred-title' => [[['read_title', 'call_read_title_1', []]]],
        'approval-title' => [[['read_title', 'call_read_title_1', []]]],
        'mixed' => [[['server_clock', 'call_clock_1', []], ['read_title', 'call_read_title_1', []]]],
        'same-name' => [[['read_text', 'call_text_1', ['selector' => '#first']], ['read_text', 'call_text_2', ['selector' => '#second']]]],
        'structured' => [[
            ['probe', 'call_object', ['kind' => 'object']],
            ['probe', 'call_array', ['kind' => 'array']],
            ['probe', 'call_false', ['kind' => 'false']],
            ['probe', 'call_zero', ['kind' => 'zero']],
            ['probe', 'call_null', ['kind' => 'null']],
        ]],
        'handler-error' => [[['probe', 'call_throw', ['kind' => 'throw']]]],
        'backend-error' => [[['server_fail', 'call_fail_1', []]]],
        'two-steps' => [[['read_title', 'call_read_title_1', []]], [['read_text', 'call_text_1', ['selector' => '#first']]]],
    ];

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
        $results = $this->receivedResults($this->currentTurn($messages));

        foreach ($this->turnPlan($messages) as $batch) {
            $unanswered = array_filter($batch, fn (array $call): bool => !array_key_exists($call[1], $results));
            if ($unanswered !== []) {
                $response = new ToolCallMessage(null, array_map(
                    fn (array $call) => $this->newToolCall($call[0], $call[1], $call[2]),
                    $batch,
                ));
                $this->record($method, $messages, $response);
                return $response;
            }
        }

        $response = new AssistantMessage('Done: ' . json_encode($results, JSON_THROW_ON_ERROR));
        $this->record($method, $messages, $response);
        return $response;
    }

    /**
     * The plan replays on every user turn; later turns get distinct call ids so
     * identity never depends on the turn a call belongs to.
     * @param Message[] $messages
     * @return list<list<array{string, string, array<string, mixed>}>>
     */
    protected function turnPlan(array $messages): array
    {
        $plan = self::PLANS[$this->scenario] ?? throw new ProviderException("Unknown scenario '{$this->scenario}'.");
        $turn = count(array_filter($messages, $this->isUserTurn(...)));
        if ($turn <= 1) {
            return $plan;
        }
        return array_map(
            fn (array $batch): array => array_map(fn (array $call): array => [$call[0], "{$call[1]}_t{$turn}", $call[2]], $batch),
            $plan,
        );
    }

    /**
     * Messages after the latest user message: the only ones this turn answers.
     * @param Message[] $messages
     * @return Message[]
     */
    protected function currentTurn(array $messages): array
    {
        $start = 0;
        foreach ($messages as $index => $message) {
            if ($this->isUserTurn($message)) {
                $start = $index;
            }
        }
        return array_slice($messages, $start);
    }

    /** Tool results travel with the user role too; only a real user message opens a turn. */
    protected function isUserTurn(Message $message): bool
    {
        return $message instanceof UserMessage && !$message instanceof ToolResultMessage;
    }

    /**
     * Results by call id, in the order the model received them.
     * @param Message[] $messages
     * @return array<string, mixed>
     */
    protected function receivedResults(array $messages): array
    {
        $results = [];
        foreach ($messages as $message) {
            if (!$message instanceof ToolResultMessage) {
                continue;
            }
            foreach ($message->getToolCalls() as $call) {
                if (!$call->hasResult()) {
                    continue;
                }
                $result = $call->getResult();
                $results[(string) $call->getCallId()] = $result instanceof ToolOutput
                    ? ($result->isError() ? ['error' => $result->getText()] : $result->getText())
                    : $result;
            }
        }
        return $results;
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
