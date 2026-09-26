<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use Closure;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function implode;
use function json_encode;
use function serialize;

use const JSON_THROW_ON_ERROR;

/**
 * A provider's API key lives in the segment's resources: it reaches the vendor
 * through its authentication header and nothing the run keeps or publishes,
 * whatever the real response parsing captures (body, headers, metadata).
 */
class ProviderCredentialsTrustBoundarySecurityTest extends TestCase
{
    use RecordsHttpRequests;

    protected const SECRET = 'sk-live-TRUST-BOUNDARY-7f3a9c';

    /** Response headers a vendor really sends back, captured by the providers. */
    protected const RESPONSE_HEADERS = ['Content-Type' => 'application/json', 'x-request-id' => 'req_123'];

    /** @var string[] */
    protected array $publishedEvents = [];

    /**
     * @return array<string, array{Closure(HttpClientInterface): AIProviderInterface, string, string, string}>
     */
    public static function providers(): array
    {
        $completionToolCall = json_encode(['id' => 'chatcmpl-1', 'choices' => [['index' => 0, 'finish_reason' => 'tool_calls', 'message' => [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{"query":"php"}']]],
        ]]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]], JSON_THROW_ON_ERROR);
        $completionAnswer = json_encode(['id' => 'chatcmpl-2', 'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => [
            'role' => 'assistant',
            'content' => 'Done',
        ]]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]], JSON_THROW_ON_ERROR);

        return [
            'anthropic' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new Anthropic(self::SECRET, 'model', httpClient: $client),
                'x-api-key',
                json_encode(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'tool_use', 'content' => [
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'search', 'input' => ['query' => 'php']],
                ], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], JSON_THROW_ON_ERROR),
                json_encode(['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn', 'content' => [
                    ['type' => 'text', 'text' => 'Done'],
                ], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], JSON_THROW_ON_ERROR),
            ],
            'openai' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new OpenAI(self::SECRET, 'model', httpClient: $client),
                'Authorization',
                $completionToolCall,
                $completionAnswer,
            ],
            'mistral' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new Mistral(self::SECRET, 'model', httpClient: $client),
                'Authorization',
                $completionToolCall,
                $completionAnswer,
            ],
            'openai responses' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new OpenAIResponses(self::SECRET, 'model', httpClient: $client),
                'Authorization',
                json_encode(['status' => 'completed', 'output' => [
                    ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'search', 'arguments' => '{"query":"php"}'],
                ], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], JSON_THROW_ON_ERROR),
                json_encode(['status' => 'completed', 'output' => [
                    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Done']]],
                ], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], JSON_THROW_ON_ERROR),
            ],
            'gemini' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new Gemini(self::SECRET, 'model', httpClient: $client),
                'x-goog-api-key',
                json_encode(['candidates' => [['content' => ['role' => 'model', 'parts' => [
                    ['functionCall' => ['name' => 'search', 'args' => ['query' => 'php']]],
                ]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1]], JSON_THROW_ON_ERROR),
                json_encode(['candidates' => [['content' => ['role' => 'model', 'parts' => [
                    ['text' => 'Done'],
                ]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1]], JSON_THROW_ON_ERROR),
            ],
        ];
    }

    protected function agent(AIProviderInterface $provider, InMemoryPersistence $persistence, InMemoryMessageStore $messageStore): Agent
    {
        $agent = Agent::make(workflowId: 'credentials')
            ->setAiProvider($provider)
            ->setPersistence($persistence)
            ->setMessageStore($messageStore)
            ->retainCompletionUntilAcknowledged()
            ->addTool(new SearchTool());

        $agent->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event): void {
            $this->publishedEvents[] = $event->name().' '.json_encode($event->toArray(), JSON_THROW_ON_ERROR);
        });

        return $agent;
    }

    protected function assertKeyWasSentOnlyIn(string $header): void
    {
        $this->assertCount(2, $this->sentRequests);
        foreach ($this->sentRequests as $entry) {
            $request = $entry['request'];
            $this->assertStringContainsString(self::SECRET, $request->getHeaderLine($header), 'The vendor must receive the key.');
            $this->assertStringNotContainsString(self::SECRET, (string) $request->getUri());
            $this->assertStringNotContainsString(self::SECRET, (string) $request->getBody());
        }
    }

    protected function assertKeyIsNowhereIn(string $surface, string $content): void
    {
        $this->assertStringNotContainsString(self::SECRET, $content, "The API key leaks into {$surface}.");
    }

    /**
     * @param Closure(HttpClientInterface): AIProviderInterface $factory
     */
    #[DataProvider('providers')]
    public function test_a_real_tool_loop_keeps_the_key_out_of_state_history_persistence_and_events(
        Closure $factory,
        string $header,
        string $toolCall,
        string $answer,
    ): void {
        $client = $this->recordingClient(
            new Response(200, self::RESPONSE_HEADERS, $toolCall),
            new Response(200, self::RESPONSE_HEADERS, $answer),
        );
        $persistence = new InMemoryPersistence();
        $messageStore = new InMemoryMessageStore();

        $state = $this->agent($factory($client), $persistence, $messageStore)->chat(new UserMessage('Find php'));

        $this->assertSame('Done', $state->getMessage()?->getContent());
        // The stored response keeps what the vendor answered, never what was sent to it.
        $this->assertSame(['Content-Type' => ['application/json'], 'x-request-id' => ['req_123']], $state->getResponse()?->headers());
        $this->assertKeyWasSentOnlyIn($header);
        $this->assertNotSame([], $this->publishedEvents);

        $this->assertKeyIsNowhereIn('the returned state', serialize($state));
        $this->assertKeyIsNowhereIn('the persisted run', serialize($persistence));
        $this->assertKeyIsNowhereIn('the chat history', serialize($messageStore));
        $this->assertKeyIsNowhereIn('the observability payloads', implode("\n", $this->publishedEvents));
    }

    public function test_a_streamed_answer_keeps_the_key_out_of_chunks_state_and_persistence(): void
    {
        $sse = static fn (array ...$events): string => implode('', array_map(
            static fn (array $event): string => 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n",
            $events,
        ))."data: [DONE]\n\n";
        $client = $this->recordingClient(
            new Response(200, ['Content-Type' => 'text/event-stream', 'x-request-id' => 'req_1'], $sse(
                ['id' => 'chatcmpl-1', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{"query":"php"}']]]], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]],
            )),
            new Response(200, ['Content-Type' => 'text/event-stream', 'x-request-id' => 'req_2'], $sse(
                ['id' => 'chatcmpl-2', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Done'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-2', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
            )),
        );
        $persistence = new InMemoryPersistence();
        $messageStore = new InMemoryMessageStore();
        $agent = $this->agent(new OpenAI(self::SECRET, 'model', httpClient: $client), $persistence, $messageStore);

        $frames = [];
        $stream = $agent->stream(new UserMessage('Find php'));
        foreach ($stream as $chunk) {
            $frames[] = serialize($chunk);
        }
        $state = $stream->getReturn();

        $this->assertSame('Done', $state->getMessage()?->getContent());
        $this->assertKeyWasSentOnlyIn('Authorization');
        $this->assertNotSame([], $frames);

        $this->assertKeyIsNowhereIn('the streamed chunks', implode("\n", $frames));
        $this->assertKeyIsNowhereIn('the returned state', serialize($state));
        $this->assertKeyIsNowhereIn('the persisted run', serialize($persistence));
        $this->assertKeyIsNowhereIn('the chat history', serialize($messageStore));
        $this->assertKeyIsNowhereIn('the observability payloads', implode("\n", $this->publishedEvents));
    }
}
