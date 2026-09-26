<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Tests\Agent\Stub\AgentFailingTool;
use NeuronAI\Tests\Agent\Stub\AgentSearchTool;
use NeuronAI\Tests\Agent\Stub\AgentSecretTool;
use Generator;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function array_map;
use function iterator_to_array;

class AgentTest extends TestCase
{
    public function test_chat(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Hello! How can I help you?')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);

        $message = $agent->chat(new UserMessage('Hi'))->getMessage();

        $this->assertSame('Hello! How can I help you?', $message->getContent());
        $provider->assertCallCount(1);
    }

    public function test_chat_with_system_prompt(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Bonjour!')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->setInstructions('Always respond in French.');

        $agent->chat(new UserMessage('Hello'))->getMessage();

        $provider->assertSystemPrompt('Always respond in French.');
    }

    public function test_chat_with_tools(): void
    {
        $searchTool = new AgentSearchTool();

        // First response: the model calls the tool
        // Second response: the model uses the tool result to answer
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Based on my search, here are the top PHP frameworks...')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addTool($searchTool);

        $message = $agent->chat(new UserMessage('What are the best PHP frameworks?'))->getMessage();

        $this->assertSame('Based on my search, here are the top PHP frameworks...', $message->getContent());
        $provider->assertCallCount(2);
        $provider->assertToolsConfigured(['search']);

        // The follow-up inference sees the call and the tool's real output.
        $followUp = $provider->getRecorded()[1]->messages;
        $this->assertCount(3, $followUp);
        $this->assertSame('What are the best PHP frameworks?', $followUp[0]->getContent());
        $this->assertInstanceOf(ToolCallMessage::class, $followUp[1]);
        $this->assertInstanceOf(ToolResultMessage::class, $followUp[2]);
        $this->assertSame('call_1', $followUp[2]->getToolCalls()[0]->getCallId());
        $this->assertSame('Results for: PHP frameworks', $followUp[2]->getToolCalls()[0]->getResult());
    }

    public function test_streaming(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Hello world')
        );
        $provider->setStreamChunkSize(5);

        $agent = Agent::make();
        $agent->setAiProvider($provider);

        $gen = $agent->stream(new UserMessage('Hi'));
        iterator_to_array($gen);

        $this->assertSame('Hello world', $gen->getReturn()->getMessage()->getContent());
    }

    public function test_mid_stream_failure_leaves_chat_history_consistent(): void
    {
        // A provider that yields a few chunks, then dies mid-stream.
        $provider = new class (new AssistantMessage('Hello world')) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                yield new TextChunk('fake_msg', 'Hel');
                yield new TextChunk('fake_msg', 'lo w');
                throw new RuntimeException('connection lost mid-stream');
            }
        };

        $agent = Agent::make();
        $agent->setAiProvider($provider);

        $agent->setThreadId('stream-failure');
        // The default history starts empty for a fresh turn.
        $this->assertSame([], $agent->getChatHistory()->getMessages());

        $stream = $agent->stream(new UserMessage('Hi'));

        try {
            iterator_to_array($stream);
            $this->fail('Expected the stream to fail mid-stream.');
        } catch (RuntimeException $e) {
            $this->assertSame('connection lost mid-stream', $e->getMessage());
        }

        // Neither the failed-turn inbound user message nor a partial assistant
        // response is persisted: history stays at its pre-turn state, so the
        // next attempt doesn't break role alternation with a dangling message.
        $this->assertSame([], $agent->getChatHistory()->getMessages());
    }

    public function test_structured_output(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('{"name": "Alice"}')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);

        $user = $agent->structured(
            new UserMessage('Generate a user'),
            User::class
        );

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Alice', $user->name);
        $provider->assertMethodCallCount('structured', 1);
    }

    public function test_structured_output_retries_as_many_times_as_asked(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('not json'),
            new AssistantMessage('still not json'),
            new AssistantMessage('{"name": "Alice"}'),
        );

        $user = Agent::make()->setAiProvider($provider)->structured(new UserMessage('Generate a user'), User::class, maxRetries: 2);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Alice', $user->name);
        $provider->assertMethodCallCount('structured', 3);
    }

    public function test_structured_output_without_retries_fails_on_the_first_invalid_answer(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('not json'), new AssistantMessage('{"name": "Alice"}'));

        try {
            Agent::make()->setAiProvider($provider)->structured(new UserMessage('Generate a user'), User::class, maxRetries: 0);
            $this->fail('An invalid answer with no retry budget must fail the call.');
        } catch (AgentException $exception) {
            $this->assertSame('The response does not contains a valid JSON Object.', $exception->getMessage());
        }

        $provider->assertMethodCallCount('structured', 1);
    }

    public function test_multiple_turns(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Hi! I can help with that.'),
            new AssistantMessage('The capital of France is Paris.'),
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);

        $first = $agent->chat(new UserMessage('Hello'))->getMessage();
        $second = $agent->chat(new UserMessage('What is the capital of France?'))->getMessage();

        $this->assertSame('Hi! I can help with that.', $first->getContent());
        $this->assertSame('The capital of France is Paris.', $second->getContent());
        $provider->assertCallCount(2);

        // The second turn carries the first one as context.
        $this->assertSame(
            ['Hello', 'Hi! I can help with that.', 'What is the capital of France?'],
            array_map(static fn (Message $message): ?string => $message->getContent(), $provider->getRecorded()[1]->messages)
        );
    }

    public function test_hidden_tool_is_not_sent_to_provider(): void
    {
        $visibleTool = new AgentSearchTool();

        $hiddenTool = (new AgentSecretTool())->visible(false);

        $provider = new FakeAIProvider(
            new AssistantMessage('Here is my answer.')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addTool($visibleTool);
        $agent->addTool($hiddenTool);

        $agent->chat(new UserMessage('Hello'))->getMessage();

        // Only the visible tool should be configured on the provider
        $provider->assertToolsConfigured(['search']);
    }

    public function test_assert_sent(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->chat(new UserMessage('Hello'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool => $record->method === 'chat'
            && $record->messages[0]->getContent() === 'Hello');
    }

    public function test_tool_error_handler_catches_exception(): void
    {
        $failingTool = new AgentFailingTool();

        // First response: model calls the failing tool
        // Second response: model uses the error message from handler
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($failingTool->getName(), 'call_1', ['input' => 'test']),
            ]),
            new AssistantMessage('I see the tool failed. Let me try something else.')
        );

        $handled = [];
        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addTool($failingTool);
        $agent->toolErrorHandler(function (Throwable $e, ToolCall $call) use (&$handled): string {
            $handled[] = [$e::class, $call->getCallId(), $call->getInputs()];
            return "Custom error: {$e->getMessage()}";
        });

        $message = $agent->chat(new UserMessage('Test'))->getMessage();

        $this->assertSame('I see the tool failed. Let me try something else.', $message->getContent());
        $this->assertSame([[RuntimeException::class, 'call_1', ['input' => 'test']]], $handled);

        // The handler's output is what the model sees as the tool result.
        $followUp = $provider->getRecorded()[1]->messages;
        $result = $followUp[2];
        $this->assertInstanceOf(ToolResultMessage::class, $result);
        $this->assertSame('Custom error: Tool failed!', $result->getToolCalls()[0]->getResult());
    }

    public function test_a_declining_error_handler_lets_the_exception_propagate(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('failing_tool', 'call_1', ['input' => 'test'])]),
            new AssistantMessage('This should not be reached.')
        );
        $agent = Agent::make()->setAiProvider($provider)->addTool(new AgentFailingTool());
        $agent->toolErrorHandler(fn (Throwable $e, ToolCall $call): ?string => null);

        try {
            $agent->chat(new UserMessage('Test'));
            $this->fail('A declined error must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Tool failed!', $exception->getMessage());
        }

        $provider->assertCallCount(1);
        // The history tail stays at the last committed message, never at a dangling tool call.
        $this->assertSame(
            [UserMessage::class],
            array_map(static fn (Message $message): string => $message::class, $agent->getChatHistory()->getMessages())
        );
    }

    public function test_a_call_to_an_unknown_tool_fails_loudly(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('drop_database', 'call_1')]),
            new AssistantMessage('This should not be reached.')
        );
        $agent = Agent::make()->setAiProvider($provider)->addTool(new AgentSearchTool());

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('The tool drop_database is not registered on this agent: the call cannot be executed.');

        $agent->chat(new UserMessage('Test'));
    }

    public function test_a_hidden_tool_cannot_be_called_by_the_model(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('secret', 'call_1')]),
            new AssistantMessage('This should not be reached.')
        );
        $agent = Agent::make()->setAiProvider($provider)->addTool((new AgentSecretTool())->visible(false));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('The tool secret is not registered on this agent');

        $agent->chat(new UserMessage('Test'));
    }

    public function test_a_missing_provider_is_reported(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('No AI provider configured: override the provider() method in your agent, or call setAiProvider().');

        Agent::make()->chat(new UserMessage('Hi'));
    }

    public function test_structured_output_without_a_class_is_refused(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('{"name": "Alice"}'));

        try {
            Agent::make()->setAiProvider($provider)->structured(new UserMessage('Generate a user'));
            $this->fail('A structured call needs an output class.');
        } catch (AgentException $exception) {
            $this->assertSame('You need to set a structured output class.', $exception->getMessage());
        }

        $provider->assertNothingSent();
    }

    public function test_tool_exception_thrown_without_error_handler(): void
    {
        $failingTool = new AgentFailingTool();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($failingTool->getName(), 'call_1', ['input' => 'test']),
            ]),
            new AssistantMessage('This should not be reached.')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addTool($failingTool);
        // No error handler set - default behavior

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tool failed!');

        $agent->chat(new UserMessage('Test'))->getMessage();
    }
}
