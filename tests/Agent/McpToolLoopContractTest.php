<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use Closure;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Testing\FakeMcpTransport;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * An MCP server's tool travels through three modules before the model sees it
 * and back again when the model calls it: MCP schema to Neuron properties to
 * the vendor's tool definition, then the vendor's call arguments to the MCP
 * tools/call request, then the server's answer into the next inference.
 */
class McpToolLoopContractTest extends TestCase
{
    use RecordsHttpRequests;

    /** The schema the MCP server publishes, using every construct Neuron supports. */
    protected const INPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string', 'description' => 'Full-text search'],
            'limit' => ['type' => 'integer', 'description' => 'Maximum results'],
            'labels' => [
                'type' => 'array',
                'description' => 'Labels to match',
                'items' => ['type' => 'string', 'enum' => ['bug', 'feature']],
                'minItems' => 1,
            ],
            'filter' => [
                'type' => 'object',
                'description' => 'Issue filter',
                'properties' => [
                    'state' => ['type' => 'string', 'description' => 'Issue state', 'enum' => ['open', 'closed']],
                ],
                'required' => ['state'],
            ],
        ],
        'required' => ['query', 'limit', 'labels', 'filter'],
    ];

    protected const ARGUMENTS = ['query' => 'crash', 'limit' => 5, 'labels' => ['bug'], 'filter' => ['state' => 'open']];

    /**
     * @return array<string, array{Closure(HttpClientInterface): AIProviderInterface, array<string, mixed>, array<string, mixed>, Closure(array<string, mixed>): array<string, mixed>, Closure(array<string, mixed>): string}>
     */
    public static function providers(): array
    {
        return [
            'anthropic' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new Anthropic('key', 'claude', httpClient: $client),
                ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'tool_use', 'content' => [
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'search_issues', 'input' => self::ARGUMENTS],
                ], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]],
                ['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn', 'content' => [
                    ['type' => 'text', 'text' => 'Issue #42 matches.'],
                ], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]],
                static fn (array $body): array => ['name' => $body['tools'][0]['name'], 'description' => $body['tools'][0]['description'], 'schema' => $body['tools'][0]['input_schema']],
                static fn (array $body): string => json_encode($body['messages'][2]['content'][0], JSON_THROW_ON_ERROR),
            ],
            'openai' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new OpenAI('key', 'gpt', httpClient: $client),
                ['id' => 'chatcmpl-1', 'choices' => [['index' => 0, 'finish_reason' => 'tool_calls', 'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => [
                        'name' => 'search_issues',
                        'arguments' => json_encode(self::ARGUMENTS, JSON_THROW_ON_ERROR),
                    ]]],
                ]]]],
                ['id' => 'chatcmpl-2', 'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => [
                    'role' => 'assistant',
                    'content' => 'Issue #42 matches.',
                ]]]],
                static fn (array $body): array => [
                    'name' => $body['tools'][0]['function']['name'],
                    'description' => $body['tools'][0]['function']['description'],
                    'schema' => $body['tools'][0]['function']['parameters'],
                ],
                static fn (array $body): string => json_encode($body['messages'][3], JSON_THROW_ON_ERROR),
            ],
        ];
    }

    /**
     * @param Closure(HttpClientInterface): AIProviderInterface $makeProvider
     * @param array<string, mixed> $toolCall
     * @param array<string, mixed> $answer
     * @param Closure(array<string, mixed>): array<string, mixed> $offeredTool
     * @param Closure(array<string, mixed>): string $toolResult
     */
    #[DataProvider('providers')]
    public function test_an_mcp_tool_is_offered_called_and_answered_through_a_real_provider(
        Closure $makeProvider,
        array $toolCall,
        array $answer,
        Closure $offeredTool,
        Closure $toolResult,
    ): void {
        $mcp = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-11-25']],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [[
                'name' => 'search_issues',
                'description' => 'Search the issue tracker',
                'inputSchema' => self::INPUT_SCHEMA,
            ]]]],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['content' => [['type' => 'text', 'text' => 'Issue #42: crash on start']]]],
        );
        $client = $this->recordingClient(
            new Response(200, ['Content-Type' => 'application/json'], json_encode($toolCall, JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode($answer, JSON_THROW_ON_ERROR)),
        );

        $state = Agent::make(workflowId: 'mcp-thread')
            ->setAiProvider($makeProvider($client))
            ->setMessageStore(new InMemoryMessageStore())
            ->addTool(McpConnector::make(['transport' => $mcp])->tools())
            ->chat(new UserMessage('Any open crash bugs?'));

        $this->assertSame('Issue #42 matches.', $state->getMessage()?->getContent());

        // The model is offered the server's schema, not an approximation of it.
        $this->assertEquals(
            ['name' => 'search_issues', 'description' => 'Search the issue tracker', 'schema' => self::INPUT_SCHEMA],
            $offeredTool($this->sentBody(0)),
        );

        // The server receives exactly the model's arguments.
        $mcp->assertToolCalled('search_issues');
        $calls = array_values(array_filter($mcp->getSent(), static fn (array $message): bool => $message['method'] === 'tools/call'));
        $this->assertSame(['name' => 'search_issues', 'arguments' => self::ARGUMENTS], $calls[0]['params']);

        // The server's answer reaches the model's next inference.
        $this->assertStringContainsString('Issue #42: crash on start', $toolResult($this->sentBody(1)));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(int $request): array
    {
        return json_decode((string) $this->sentRequests[$request]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
