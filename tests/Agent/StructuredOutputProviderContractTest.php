<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use Closure;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\StructuredOutput\Stub\Address;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_keys;
use function json_decode;
use function json_encode;
use function str_starts_with;
use function strpos;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * Structured output crosses three modules: the Agent's retry loop, the
 * StructuredOutput schema and validation, and the provider's wire format. A
 * rejected answer must reach the vendor again as conversation, the schema must
 * constrain every attempt, and none of it may leak into the next plain turn.
 */
class StructuredOutputProviderContractTest extends TestCase
{
    use RecordsHttpRequests;

    protected const INVALID = '{"street":"","city":"Rome","zip":"00100"}';

    protected const VALID = '{"street":"Via Roma 1","city":"Rome","zip":"00100"}';

    /**
     * @return array<string, array{Closure(HttpClientInterface): AIProviderInterface, Closure(string): array<string, mixed>, Closure(array<string, mixed>): list<array{string, string}>, Closure(array<string, mixed>): ?array<string, mixed>}>
     */
    public static function providers(): array
    {
        return [
            'anthropic' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new Anthropic('key', 'claude', httpClient: $client),
                static fn (string $text): array => ['id' => 'msg', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn', 'content' => [
                    ['type' => 'text', 'text' => $text],
                ], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]],
                static fn (array $body): array => self::textConversation($body['messages']),
                static function (array $body): ?array {
                    foreach ($body['system'] ?? [] as $block) {
                        if (str_starts_with($block['text'], '# OUTPUT CONSTRAINTS')) {
                            return json_decode(substr($block['text'], (int) strpos($block['text'], '{')), true, flags: JSON_THROW_ON_ERROR);
                        }
                    }

                    return null;
                },
            ],
            'openai' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new OpenAI('key', 'gpt', httpClient: $client),
                static fn (string $text): array => ['id' => 'chatcmpl', 'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => [
                    'role' => 'assistant',
                    'content' => $text,
                ]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]],
                static fn (array $body): array => self::textConversation($body['messages']),
                static fn (array $body): ?array => $body['response_format']['json_schema']['schema'] ?? null,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array{string, string}> Each message as [role, text], without the instructions.
     */
    protected static function textConversation(array $messages): array
    {
        $conversation = [];
        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                continue;
            }
            $conversation[] = [$message['role'], $message['content'][0]['text']];
        }

        return $conversation;
    }

    /**
     * @param Closure(HttpClientInterface): AIProviderInterface $makeProvider
     * @param Closure(string): array<string, mixed> $answer
     * @param Closure(array<string, mixed>): list<array{string, string}> $conversation
     * @param Closure(array<string, mixed>): ?array<string, mixed> $schema
     */
    #[DataProvider('providers')]
    public function test_a_rejected_answer_is_retried_as_conversation_under_the_same_schema(
        Closure $makeProvider,
        Closure $answer,
        Closure $conversation,
        Closure $schema,
    ): void {
        $client = $this->recordingClient(
            $this->jsonResponse($answer(self::INVALID)),
            $this->jsonResponse($answer(self::VALID)),
            $this->jsonResponse($answer('You are welcome.')),
        );
        $agent = Agent::make(workflowId: 'structured-thread')
            ->setAiProvider($makeProvider($client))
            ->setMessageStore(new InMemoryMessageStore());

        $address = $agent->structured(new UserMessage('Where is the office?'), Address::class, maxRetries: 1);

        $this->assertInstanceOf(Address::class, $address);
        $this->assertSame(['Via Roma 1', 'Rome', '00100'], [$address->street, $address->city, $address->zip]);

        $first = $this->sentBody(0);
        $retry = $this->sentBody(1);
        $this->assertSame([['user', 'Where is the office?']], $conversation($first));
        $retried = $conversation($retry);
        $this->assertSame([['user', 'Where is the office?'], ['assistant', self::INVALID]], [$retried[0], $retried[1]]);
        $this->assertCount(3, $retried);
        $this->assertSame('user', $retried[2][0]);
        $this->assertStringContainsString('street', $retried[2][1], 'The correction names the violated field.');

        $firstSchema = $schema($first);
        $this->assertNotNull($firstSchema, 'The first attempt is constrained by the schema.');
        $this->assertSame(['street', 'city', 'zip'], array_keys($firstSchema['properties']));
        $this->assertSame($firstSchema, $schema($retry), 'The retry is constrained by the same schema.');

        // The next plain turn continues the committed conversation, unconstrained.
        $agent->chat(new UserMessage('Thanks'));
        $followUp = $this->sentBody(2);
        $this->assertNull($schema($followUp), 'The structured constraint must not leak into a later chat turn.');
        $this->assertSame(
            [...$retried, ['assistant', self::VALID], ['user', 'Thanks']],
            $conversation($followUp),
        );
    }

    public function test_a_tool_call_during_structured_output_returns_to_the_constrained_inference(): void
    {
        [$makeProvider, $answer, , $schema] = self::providers()['anthropic'];
        $client = $this->recordingClient(
            $this->jsonResponse(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'tool_use', 'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'search', 'input' => ['query' => 'office address']],
            ], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]),
            $this->jsonResponse($answer(self::VALID)),
        );

        $address = Agent::make(workflowId: 'structured-tools')
            ->setAiProvider($makeProvider($client))
            ->addTool(new SearchTool())
            ->structured(new UserMessage('Where is the office?'), Address::class);

        $this->assertSame('Via Roma 1', $address->street);
        $afterTool = $this->sentBody(1);
        $this->assertSame($schema($this->sentBody(0)), $schema($afterTool), 'The inference after the tool is still constrained.');
        $this->assertSame(
            [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => 'Results for: office address']],
            $afterTool['messages'][2]['content'],
        );
        $this->assertSame(['search'], array_column($afterTool['tools'], 'name'));
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function jsonResponse(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(int $request): array
    {
        return json_decode((string) $this->sentRequests[$request]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
