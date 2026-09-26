<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\McpTool;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeMcpTransport;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\Tavily\TavilySearchTool;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

use function array_map;
use function end;
use function implode;
use function json_encode;
use function serialize;

use const JSON_THROW_ON_ERROR;

/**
 * Tools holding credentials (an API-key toolkit, an MCP connection configured
 * with a token and headers) are capabilities on the live registry: across an
 * approval pause and its resume, their secrets reach only their own service and
 * never the approval request, the history, the persisted run or the events.
 */
class ToolCredentialsTrustBoundarySecurityTest extends TestCase
{
    use RecordsHttpRequests;

    protected const TAVILY_KEY = 'tvly-SECRET-9a8b7c';
    protected const MCP_TOKEN = 'mcp-token-SECRET-6d5e';
    protected const MCP_HEADER = 'mcp-header-SECRET-4f3a';

    protected InMemoryPersistence $persistence;

    protected InMemoryMessageStore $messages;

    protected FakeAIProvider $provider;

    protected FakeMcpTransport $mcp;

    /** @var ToolInterface[] */
    protected array $tools;

    /** @var string[] */
    protected array $publishedEvents = [];

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
        $this->messages = new InMemoryMessageStore();
        $this->provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                new ToolCall('web_search', 'call_1', ['search_query' => 'php']),
                new ToolCall('lookup', 'call_2', ['query' => 'php']),
            ]),
            new AssistantMessage('Done'),
        );
        $this->mcp = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-11-25']],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [[
                'name' => 'lookup',
                'description' => 'Look a term up',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
            ]]]],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['content' => [['type' => 'text', 'text' => 'Found in the manual']]]],
        );

        $mcpTools = McpConnector::make([
            'transport' => $this->mcp,
            'token' => self::MCP_TOKEN,
            'headers' => ['X-Api-Key' => self::MCP_HEADER],
        ])->with('lookup', static fn (McpTool $tool): ToolInterface => $tool->requireApproval())->tools();
        $tavily = new TavilySearchTool(self::TAVILY_KEY, httpClient: $this->recordingClient(new Response(200, body: json_encode([
            'answer' => 'PHP is a language',
            'results' => [['title' => 'PHP', 'url' => 'https://www.php.net', 'content' => 'Manual']],
        ], JSON_THROW_ON_ERROR))));

        $this->tools = [$tavily->requireApproval(), ...$mcpTools];
    }

    /**
     * A fresh instance per request, as separate HTTP requests would build it.
     */
    protected function agent(): Agent
    {
        $agent = Agent::make(workflowId: 'tool-credentials')
            ->setPersistence($this->persistence)
            ->setMessageStore($this->messages)
            ->setAiProvider($this->provider)
            ->retainCompletionUntilAcknowledged()
            ->addTool($this->tools);

        $agent->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event): void {
            $this->publishedEvents[] = $event->name().' '.json_encode($event->toArray(), JSON_THROW_ON_ERROR);
        });

        return $agent;
    }

    /**
     * @param array<string, string> $surfaces
     */
    protected function assertNoSecretIn(array $surfaces): void
    {
        foreach ($surfaces as $surface => $content) {
            foreach ([self::TAVILY_KEY, self::MCP_TOKEN, self::MCP_HEADER] as $secret) {
                $this->assertStringNotContainsString($secret, $content, "A tool credential leaks into {$surface}.");
            }
        }
    }

    public function test_tool_credentials_stay_out_of_the_approval_pause_and_the_resumed_run(): void
    {
        $suspended = $this->agent()->chat(new UserMessage('Search php'));

        $request = $suspended->getInterruptRequest();
        $this->assertInstanceOf(ApprovalRequest::class, $request);
        $this->assertSame(['call_1', 'call_2'], array_map(static fn (Action $action): string => $action->id, $request->getActions()));
        $this->assertSame([], $this->sentRequests, 'Nothing runs before approval.');
        $this->assertNoSecretIn([
            'the approval request' => json_encode($request, JSON_THROW_ON_ERROR),
            'the suspended state' => serialize($suspended),
            'the persisted pause' => serialize($this->persistence),
            'the chat history' => serialize($this->messages),
            'the observability payloads' => implode("\n", $this->publishedEvents),
        ]);

        $completed = $this->agent()->submitApprovalDecisions(['call_1' => 'approve', 'call_2' => 'approve'])->run();

        $this->assertSame('Done', $completed->getMessage()?->getContent());
        $this->assertCount(1, $this->sentRequests);
        $this->assertSame('Bearer '.self::TAVILY_KEY, $this->sentRequests[0]['request']->getHeaderLine('Authorization'));
        $sent = $this->mcp->getSent();
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'lookup', 'arguments' => ['query' => 'php']]],
            end($sent),
        );

        $results = [];
        foreach ($completed->getSteps() as $step) {
            $results[] = json_encode($step, JSON_THROW_ON_ERROR);
        }
        $this->assertStringContainsString('PHP is a language', implode("\n", $results));
        $this->assertStringContainsString('Found in the manual', implode("\n", $results));

        $this->assertNoSecretIn([
            'the tool results' => implode("\n", $results),
            'the completed state' => serialize($completed),
            'the persisted run' => serialize($this->persistence),
            'the chat history' => serialize($this->messages),
            'the observability payloads' => implode("\n", $this->publishedEvents),
        ]);
    }
}
