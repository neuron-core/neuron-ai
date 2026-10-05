<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\GetWeatherTool;
use NeuronAI\Tests\Support\ReadsTurnContext;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_map;
use function serialize;
use function unserialize;

/**
 * The context of a turn: content sent with its question on every request of
 * the turn, and never written to the chat history.
 */
class AgentContextTest extends TestCase
{
    use ReadsTurnContext;

    public function test_the_context_is_sent_after_the_question_and_never_stored(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Monday.'));
        $store = new InMemoryMessageStore();
        $agent = Agent::make()->setThreadId('thread')->setAiProvider($provider)->setMessageStore($store)
            ->setContext([new TextContent('Today is Monday.')]);

        $agent->chat(new UserMessage('What day is it?'));

        $this->assertSame([['What day is it?', 'Today is Monday.']], $this->sent($provider, 0));
        $this->assertSame([['What day is it?'], ['Monday.']], $this->texts($store->loadActive('thread')));
    }

    public function test_every_request_of_a_turn_carries_the_context_on_its_question(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('get_weather', 'call_1', ['location' => 'Rome'])]),
            new AssistantMessage('Sunny.'),
        );
        $agent = Agent::make()->setThreadId('thread')->setAiProvider($provider)->addTool(new GetWeatherTool())
            ->setContext([new TextContent('The user is in Rome.')]);

        $agent->chat(new UserMessage('How is the weather?'));

        // The second request follows the tool call: the context stays where it was, on the question
        $this->assertSame(['How is the weather?', 'The user is in Rome.'], $this->sent($provider, 0)[0]);
        $this->assertSame(['How is the weather?', 'The user is in Rome.'], $this->sent($provider, 1)[0]);
        $this->assertCount(3, $provider->getRecorded()[1]->messages);
    }

    public function test_the_next_turn_sends_the_earlier_question_without_its_context(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Monday.'), new AssistantMessage('Tuesday.'));
        $agent = Agent::make()->setThreadId('thread')->setAiProvider($provider);

        $agent->setContext([new TextContent('Today is Monday.')])->chat(new UserMessage('What day is it?'));
        $agent->setContext([new TextContent('Today is Tuesday.')])->chat(new UserMessage('And now?'));

        $this->assertSame(
            [['What day is it?'], ['Monday.'], ['And now?', 'Today is Tuesday.']],
            $this->sent($provider, 1),
        );
    }

    public function test_the_context_hook_supplies_the_context_and_the_setter_wins_over_it(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('One'), new AssistantMessage('Two'));
        $agent = new class () extends Agent {
            protected function context(): array
            {
                return [new TextContent('From the hook')];
            }
        };
        $agent->setThreadId('thread')->setAiProvider($provider);

        $agent->chat(new UserMessage('First'));
        $agent->setContext([new TextContent('From the setter')])->chat(new UserMessage('Second'));

        $this->assertSame(['First', 'From the hook'], $this->sent($provider, 0)[0]);
        $this->assertSame(['Second', 'From the setter'], $this->sent($provider, 1)[2]);
    }

    public function test_a_run_started_with_its_own_start_event_carries_the_context_too(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Monday.'));
        $agent = Agent::make()->setThreadId('thread')->setAiProvider($provider)->setContext([new TextContent('Today is Monday.')]);

        // The way a queued job starts a turn, without going through chat()
        $agent->run(ExecutionRequest::start(new AgentStartEvent([new UserMessage('What day is it?')])));

        $this->assertSame([['What day is it?', 'Today is Monday.']], $this->sent($provider, 0));
    }

    public function test_a_structured_output_request_carries_the_context_too(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('{}'));
        $agent = Agent::make()->setThreadId('thread')->setAiProvider($provider)->setContext([new TextContent('Context')]);

        $agent->structured(new UserMessage('Extract'), stdClass::class);

        $this->assertSame([['Extract', 'Context']], $this->sent($provider, 0));
    }

    public function test_a_middleware_adds_to_the_context_of_the_turn(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('get_weather', 'call_1', ['location' => 'Rome'])]),
            new AssistantMessage('Sunny.'),
        );
        $middleware = new class () extends AgentMiddleware {
            protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state, AgentResources $resources): void
            {
                // A key: the middleware runs before every request of the turn
                $state->request->context['page'] = new TextContent('The user views order 42.');
            }
        };
        $agent = Agent::make()->setThreadId('thread')->setAiProvider($provider)->addTool(new GetWeatherTool())
            ->setContext([new TextContent('Today is Monday.')])
            ->addMiddleware(InferenceNode::class, $middleware);

        $agent->chat(new UserMessage('Hello'));

        $this->assertSame(['Hello', 'Today is Monday.', 'The user views order 42.'], $this->sent($provider, 1)[0]);
    }

    public function test_a_run_edits_copies_of_the_configured_context(): void
    {
        $configured = new TextContent('Today is Monday.');
        $middleware = new class () extends AgentMiddleware {
            protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state, AgentResources $resources): void
            {
                foreach ($state->request->context as $block) {
                    $block->accumulateContent(' Edited by the run.');
                }
            }
        };
        $agent = Agent::make()->setThreadId('thread')->setAiProvider(new FakeAIProvider(new AssistantMessage('Ok')))
            ->setContext([$configured])
            ->addMiddleware(InferenceNode::class, $middleware);

        $agent->chat(new UserMessage('Hello'));

        $this->assertSame('Today is Monday.', $configured->content);
    }

    public function test_a_cloned_request_owns_its_context(): void
    {
        $request = new InferenceRequest('Instructions', context: [new TextContent('Original')]);

        $copy = clone $request;
        $copy->context[0]->accumulateContent(' edited on the copy');

        $this->assertSame('Original', $request->context[0]->getContent());
    }

    public function test_a_request_saved_before_the_context_existed_restores_without_one(): void
    {
        $request = new InferenceRequest('Instructions');
        unset($request->context);

        $restored = unserialize(serialize($request));

        $this->assertSame([], $restored->context);
    }

    /**
     * @return array<int, string[]> the text of each block, message by message
     */
    protected function sent(FakeAIProvider $provider, int $request): array
    {
        return $this->texts($provider->getRecorded()[$request]->messages);
    }

    /**
     * @param Message[] $messages
     * @return array<int, string[]>
     */
    protected function texts(array $messages): array
    {
        return array_map($this->blocks(...), $messages);
    }
}
