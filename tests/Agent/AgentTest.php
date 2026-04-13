<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tests\Stubs\StructuredOutput\User;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

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
        $searchTool = Tool::make('search', 'Search the web')
            ->addProperty(new ToolProperty('query', PropertyType::STRING, 'Search query', true))
            ->setCallable(fn (string $query): string => "Results for: {$query}");

        // First response: the model calls the tool
        // Second response: the model uses the tool result to answer
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $searchTool)->setCallId('call_1')->setInputs(['query' => 'PHP frameworks']),
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
    }

    public function test_streaming(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Hello world')
        );
        $provider->setStreamChunkSize(5);

        $agent = Agent::make();
        $agent->setAiProvider($provider);

        $handler = $agent->stream(new UserMessage('Hi'));

        $chunks = [];
        foreach ($handler->events() as $event) {
            if ($event instanceof TextChunk) {
                $chunks[] = $event->content;
            }
        }

        $this->assertSame(['Hello', ' worl', 'd'], $chunks);

        /** @var AgentState $state */
        $state = $handler->run();
        $this->assertSame('Hello world', $state->getMessage()->getContent());
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
    }

    public function test_hidden_tool_is_not_sent_to_provider(): void
    {
        $visibleTool = Tool::make('search', 'Search the web')
            ->addProperty(new ToolProperty('query', PropertyType::STRING, 'Search query', true))
            ->setCallable(fn (string $query): string => "Results for: {$query}");

        $hiddenTool = Tool::make('secret', 'Secret tool')
            ->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input', true))
            ->setCallable(fn (string $input): string => "Secret: {$input}")
            ->visible(false);

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
        $failingTool = Tool::make('failing_tool', 'A tool that fails')
            ->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input', true))
            ->setCallable(fn (string $input): string => throw new RuntimeException('Tool failed!'));

        // First response: model calls the failing tool
        // Second response: model uses the error message from handler
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $failingTool)->setCallId('call_1')->setInputs(['input' => 'test']),
            ]),
            new AssistantMessage('I see the tool failed. Let me try something else.')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addTool($failingTool);
        $agent->toolErrorHandler(fn (Throwable $e, \NeuronAI\Tools\ToolInterface $tool): string => "Custom error: {$e->getMessage()}");

        // Should NOT throw - error handler should catch it
        $message = $agent->chat(new UserMessage('Test'))->getMessage();

        $this->assertSame('I see the tool failed. Let me try something else.', $message->getContent());
    }

    public function test_tool_exception_thrown_without_error_handler(): void
    {
        $failingTool = Tool::make('failing_tool', 'A tool that fails')
            ->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input', true))
            ->setCallable(fn (string $input): string => throw new RuntimeException('Tool failed!'));

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $failingTool)->setCallId('call_1')->setInputs(['input' => 'test']),
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
