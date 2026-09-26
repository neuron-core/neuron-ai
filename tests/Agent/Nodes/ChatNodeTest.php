<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\Support\AgentResourcesFactory;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\NodeContext;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

use function array_map;
use function iterator_to_array;

class ChatNodeTest extends TestCase
{
    protected ChatHistory $history;

    protected function setUp(): void
    {
        $this->history = new ChatHistory(new InMemoryMessageStore(), 'thread');
    }

    /**
     * @param Message[] $inbound
     */
    protected function infer(FakeAIProvider $provider, array $inbound, bool $stream = false, ?AgentState $state = null): Event
    {
        $state ??= new AgentState();
        $state->request = new InferenceRequest('Working prompt', $inbound);
        $state->request->options->stream = $stream;
        $node = new ChatNode();
        $node->setWorkflowContext(new NodeContext());

        $result = $node(new AIInferenceEvent(), $state, AgentResourcesFactory::make([new SearchTool()], $this->history, $provider, 'Segment base'));
        $this->assertInstanceOf(Generator::class, $result);
        iterator_to_array($result, false);

        return $result->getReturn();
    }

    /**
     * @return array<int, class-string<Message>>
     */
    protected function historyClasses(): array
    {
        return array_map(static fn (Message $message): string => $message::class, $this->history->getMessages());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_an_empty_conversation_is_refused_before_calling_the_provider(bool $stream): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Never produced'));

        try {
            $this->infer($provider, [], $stream);
            $this->fail('Inference needs at least one message.');
        } catch (ChatHistoryException $exception) {
            $this->assertSame('Cannot run inference on an empty conversation.', $exception->getMessage());
        }

        $provider->assertNothingSent();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_tool_call_response_routes_to_the_tool_node_without_writing_it(bool $stream): void
    {
        $toolCall = new ToolCallMessage(null, [ToolCall::make('search', 'call_1', ['query' => 'php'])]);
        $state = new AgentState();

        $event = $this->infer(new FakeAIProvider($toolCall), [new UserMessage('Find php')], $stream, $state);

        $this->assertInstanceOf(ToolCallEvent::class, $event);
        $this->assertSame('call_1', $event->toolCallMessage->getToolCalls()[0]->getCallId());
        $this->assertSame([UserMessage::class], $this->historyClasses(), 'The tool node owns writing the tool call');
        $this->assertSame($event->toolCallMessage, $state->getMessage());
    }

    public function test_a_final_answer_commits_the_inbound_and_the_answer(): void
    {
        $state = new AgentState();

        $event = $this->infer(new FakeAIProvider(new AssistantMessage('Hello')), [new UserMessage('Hi')], state: $state);

        $this->assertInstanceOf(AgentOutputEvent::class, $event);
        $this->assertSame([UserMessage::class, AssistantMessage::class], $this->historyClasses());
        $this->assertSame([UserMessage::class, AssistantMessage::class], array_map(
            static fn (Message $message): string => $message::class,
            $state->getSteps()
        ));
    }

    public function test_the_provider_receives_the_stored_history_before_the_inbound(): void
    {
        $this->history->addMessage(new UserMessage('Earlier question'));
        $this->history->addMessage(new AssistantMessage('Earlier answer'));
        $provider = new FakeAIProvider(new AssistantMessage('Hello'));

        $this->infer($provider, [new UserMessage('New question')]);

        $record = $provider->getRecorded()[0];
        $this->assertSame(
            ['Earlier question', 'Earlier answer', 'New question'],
            array_map(static fn (Message $message): ?string => $message->getContent(), $record->messages)
        );
        $this->assertSame('Working prompt', $record->systemPrompt?->getContent(), 'The working prompt wins over the segment base');
        $this->assertSame(['search'], array_map(static fn (ToolInterface|ProviderToolInterface $tool): string => $tool->getName(), $record->tools));
    }
}
