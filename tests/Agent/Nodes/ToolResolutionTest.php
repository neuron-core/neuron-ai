<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Middleware\ToolSearchMiddleware;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Tools\SearchTool;
use NeuronAI\Tests\Workflow\Executor\ExecutorTestHelpers;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use PHPUnit\Framework\TestCase;

use function str_starts_with;

/**
 * The inference event's tool list is the SINGLE source calls resolve
 * against — the cycle's effective set (agent base + middleware additions, minus
 * middleware removals). Capability is transient in persistence, and the
 * restoration seam is Workflow::restoreEvent(), called by the executor on every
 * event recalled from persistence (never on a live result): the Agent re-seeds
 * its base registry there, and each middleware re-supplies its own additions
 * in before().
 */
class ToolResolutionTest extends TestCase
{
    use ExecutorTestHelpers;

    private function executableTool(string $name, ?string $description = null): Tool
    {
        $tool = new class () extends Tool {
            public function __invoke(mixed ...$arguments): string
            {
                return 'executed';
            }
        };

        $tool->setName($name)->setDescription($description ?? "The {$name} tool");

        return $tool;
    }

    private function drain(ToolNode $node, ToolCallEvent $event, AgentState $state): void
    {
        foreach ($node($event, $state) as $_) {
            $_ = null; // consume the generator
        }
    }

    public function test_a_tool_absent_from_the_event_is_not_executable(): void
    {
        // The cycle's effective set (the event) does not carry 'removed_tool' —
        // e.g. a middleware removed it from the offering. The node holds no
        // registry of its own, so the removal is honored at execution too.
        $offered = $this->executableTool('offered_tool');

        $node = new ToolNode(new InMemoryChatHistory());
        $state = new AgentState();

        $event = new ToolCallEvent(
            new ToolCallMessage(null, [ToolCall::make('removed_tool', 'call_1')]),
            new AIInferenceEvent('instructions', [$offered]) // effective set WITHOUT removed_tool
        );
        $node->setWorkflowContext(new NodeContext($state, $event));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/removed_tool/');

        $this->drain($node, $event, $state);
    }

    public function test_restore_event_reseeds_the_base_registry_on_recalled_events(): void
    {
        // Persistence strips the inference event's tools (they are capability).
        // Agent::restoreEvent() is the symmetric seam: the executor calls it
        // only on events recalled from persistence — always stripped — so the
        // base registry is re-seeded unconditionally. Live events never pass
        // through restore (that invariant lives in the executor), so a
        // middleware removal on a live effective set is never undone.
        $agent = Agent::make();
        $agent->addTool(new SearchTool());

        $recalledInference = new AIInferenceEvent('instructions', []);
        $agent->restoreEvent($recalledInference);
        $this->assertCount(1, $recalledInference->tools);
        $this->assertSame('search', $recalledInference->tools[0]->getName());

        $recalledToolCall = new ToolCallEvent(
            new ToolCallMessage(null, [ToolCall::make('search', 'call_1')]),
            new AIInferenceEvent('instructions', [])
        );
        $agent->restoreEvent($recalledToolCall);
        $this->assertSame('search', $recalledToolCall->inferenceEvent->tools[0]->getName());

        // Unrelated events pass through unchanged.
        $stop = new StopEvent();
        $this->assertSame($stop, $agent->restoreEvent($stop));
    }

    public function test_live_inference_after_a_cached_tool_node_gets_the_agent_tools_back(): void
    {
        // Regression for the silent-degradation window: crash after ToolNode #1
        // completed but before ChatNode #2's inference committed. On recovery,
        // ChatNode #1 and ToolNode #1 replay from cache — nothing executes, so
        // no node-level repair could ever run — and the recalled AIInferenceEvent
        // reaches the LIVE ChatNode #2 with its tool list stripped. Without the
        // restoreEvent() seam, the provider would be called with NO tools.
        $workflowId = 'stripped_inference_recovery_test';

        // Step store that survives run completion and can forget single steps.
        $persistence = new class () implements PersistenceInterface {
            /** @var array<string, array<string, string>> */
            public array $storage = [];

            public function get(string $partition, string $key): ?string
            {
                return $this->storage[$partition][$key] ?? null;
            }

            public function initializeIfAbsent(
                string $partition,
                string $conditionKey,
                string $initialValue,
                array $records = [],
            ): bool {
                if (isset($this->storage[$partition][$conditionKey])) {
                    return false;
                }

                $records[$conditionKey] = $initialValue;
                foreach ($records as $key => $value) {
                    $this->storage[$partition][$key] = $value;
                }

                return true;
            }

            public function writeIfUnchanged(
                string $partition,
                string $conditionKey,
                string $expectedValue,
                array $records,
            ): bool {
                if (($this->storage[$partition][$conditionKey] ?? null) !== $expectedValue) {
                    return false;
                }

                foreach ($records as $key => $value) {
                    $this->storage[$partition][$key] = $value;
                }

                return true;
            }

            public function deleteIfUnchanged(
                string $partition,
                string $conditionKey,
                string $expectedValue,
            ): bool {
                // Keep the records: this test replays a "crashed" run.
                return ($this->storage[$partition][$conditionKey] ?? null) === $expectedValue;
            }

            public function forgetByPrefix(string $partition, string $prefix): void
            {
                foreach ($this->storage[$partition] ?? [] as $key => $value) {
                    if (str_starts_with($key, $prefix)) {
                        unset($this->storage[$partition][$key]);
                    }
                }
            }
        };

        $searchTool = new SearchTool();

        // Run 1: a full turn — inference #1 calls the tool, inference #2 answers.
        $provider1 = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('First answer.'),
        );

        $agent1 = Agent::make(workflowId: $workflowId);
        $agent1->setChatHistory(new InMemoryChatHistory($workflowId));
        $agent1->setAiProvider($provider1);
        $agent1->addTool($searchTool);
        $agent1->setPersistence($persistence);

        $agent1->chat(new UserMessage('Search for PHP frameworks'))->getMessage();

        // Simulate the crash window: ChatNode #2 (step index 3) never committed.
        $persistence->forgetByPrefix($workflowId, $this->stepKey($agent1, ChatNode::class . '-3'));

        // Recovery: ChatNode #1 and ToolNode #1 replay from cache; ChatNode #2
        // runs live and must see the agent's tools on its inference request.
        // The durable history at crash time carries what the completed steps
        // wrote: the user turn and the tool call message.
        $provider2 = new FakeAIProvider(new AssistantMessage('Recovered answer.'));

        $history2 = new InMemoryChatHistory($workflowId);
        $history2->addMessage(new UserMessage('Search for PHP frameworks'));
        $history2->addMessage(new ToolCallMessage(null, [
            ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'PHP frameworks']),
        ]));

        $agent2 = Agent::make(workflowId: $workflowId);
        $agent2->setChatHistory($history2);
        $agent2->setAiProvider($provider2);
        $agent2->addTool($searchTool);
        $agent2->setPersistence($persistence);

        $message = $agent2->resume()->getMessage();

        $this->assertSame('Recovered answer.', $message->getContent());

        $recorded = $provider2->getRecorded();
        $this->assertCount(1, $recorded, 'Only the second inference runs live on recovery');
        $this->assertCount(1, $recorded[0]->tools, 'The recovered inference must carry the agent tools');
        $this->assertSame('search', $recorded[0]->tools[0]->getName());
    }

    public function test_tool_search_middleware_resupplies_its_tools_on_a_stripped_event(): void
    {
        // Cycle 1 discovered 'query_database' via tool_search; the model now
        // calls it and the process replays with a stripped event. The agent
        // seam re-seeds the base; the middleware re-derives its contribution
        // from chat history (deterministic for the pool) and puts it back on
        // the event before the node executes.
        $discovered = $this->executableTool('query_database', 'Execute SQL queries on the database');
        $middleware = new ToolSearchMiddleware([$discovered]);

        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('Query the database'));
        $history->addMessage(new ToolCallMessage(null, [
            ToolCall::make('tool_search', 'call_1', ['query' => 'database']),
        ]));
        $history->addMessage(new ToolResultMessage([
            ToolCall::make('tool_search', 'call_1', ['query' => 'database'])->setResult('found'),
        ]));

        $node = new ToolNode($history); // 'query_database' is NOT in the agent base
        $state = new AgentState();

        $call = ToolCall::make('query_database', 'call_2', ['sql' => 'SELECT 1']);
        $event = new ToolCallEvent(
            new ToolCallMessage(null, [$call]),
            new AIInferenceEvent('instructions', []) // stripped by persistence
        );

        // The executor's order: context, then middleware before(), then the node.
        $node->setWorkflowContext(new NodeContext($state, $event));
        $middleware->before($node, $event, $state);

        $names = [];
        foreach ($event->inferenceEvent->tools as $tool) {
            $names[] = $tool->getName();
        }
        $this->assertContains('tool_search', $names, 'The middleware re-supplies its own tool');
        $this->assertContains('query_database', $names, 'Discoveries are re-derived from chat history');

        $this->drain($node, $event, $state);

        $this->assertSame('executed', $call->getResult());
    }
}
