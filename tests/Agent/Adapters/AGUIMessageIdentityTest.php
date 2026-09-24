<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Adapters;

use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_values;

/**
 * The IDs a client receives live are the stored messages' IDs, or derived from
 * them, so a reload rebuilds the conversation the client already shows.
 */
class AGUIMessageIdentityTest extends TestCase
{
    protected InMemoryMessageStore $store;

    protected InMemoryPersistence $persistence;

    protected function setUp(): void
    {
        $this->store = new InMemoryMessageStore();
        $this->persistence = new InMemoryPersistence();
    }

    public function test_live_ids_are_the_stored_message_ids(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage('Let me check', [new ToolCall('clock', 'call_clock')]),
            new AssistantMessage([new ReasoningContent('Reading the clock'), new TextContent('It is ten')]),
        );

        $events = $this->events($this->agent($provider, new ToolStub('clock'))->stream(new UserMessage('What time is it?')));

        [, $toolCall, , $answer] = $this->store->loadAll('thread');
        $this->assertSame([$toolCall->getId(), $answer->getId()], $this->ids($events, 'TEXT_MESSAGE_START', 'messageId'));
        $this->assertSame(['reasoning_'.$answer->getId()], $this->ids($events, 'REASONING_MESSAGE_START', 'messageId'));
        $this->assertSame([$toolCall->getId()], $this->ids($events, 'TOOL_CALL_START', 'parentMessageId'));
        $this->assertSame(['result_call_clock'], $this->ids($events, 'TOOL_CALL_RESULT', 'messageId'));
    }

    public function test_an_approved_call_is_published_under_its_stored_message(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('delete', 'call_delete')]),
            new AssistantMessage('Deleted'),
        );
        $tool = (new ToolStub('delete'))->requireApproval();
        $this->events($this->agent($provider, $tool)->stream(new UserMessage('Delete it')));

        // The continuation is a new request: it streams nothing before the approved call.
        $events = $this->events($this->agent($provider, $tool)->submitApprovalDecisions(['call_delete' => 'approve'])->events());

        [, $toolCall] = $this->store->loadAll('thread');
        $this->assertSame([$toolCall->getId()], $this->ids($events, 'TOOL_CALL_START', 'parentMessageId'));
        $this->assertSame(['result_call_delete'], $this->ids($events, 'TOOL_CALL_RESULT', 'messageId'));
    }

    public function test_a_frontend_call_is_published_under_its_stored_message(): void
    {
        $provider = new FakeAIProvider(new ToolCallMessage(null, [new ToolCall('browser', 'call_browser', deferred: true)]));

        $events = $this->events($this->agent($provider, new FrontendTool('browser'))->stream(new UserMessage('Read the page')));

        [, $toolCall] = $this->store->loadAll('thread');
        $this->assertSame([$toolCall->getId()], $this->ids($events, 'TOOL_CALL_START', 'parentMessageId'));
    }

    protected function agent(FakeAIProvider $provider, ToolInterface $tool): Agent
    {
        $agent = Agent::make(workflowId: 'thread');
        $agent->setMessageStore($this->store)->setPersistence($this->persistence)->setAiProvider($provider);
        $agent->addTool($tool);
        $agent->setStreamAdapter(new AGUIAdapter('thread'));

        return $agent;
    }

    /**
     * @param iterable<ProtocolEvent> $frames
     * @return list<array<string, mixed>>
     */
    protected function events(iterable $frames): array
    {
        $events = [];
        foreach ($frames as $frame) {
            $events[] = ['type' => $frame->type, ...$frame->data];
        }

        return $events;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<string>
     */
    protected function ids(array $events, string $type, string $key): array
    {
        return array_values(array_map(
            fn (array $event): string => $event[$key],
            array_filter($events, fn (array $event): bool => $event['type'] === $type),
        ));
    }
}
