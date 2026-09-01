<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

/**
 * A failed provider call must not leave the inbound user message dangling in
 * the chat history: the next attempt would append another user message and be
 * rejected by the role-alternation rule ("expected role assistant, got user"),
 * permanently wedging the thread. Inbound messages are committed only after
 * the provider call succeeds.
 *
 * Also guards the structured-output retry loop's history writes: all attempts
 * share one node step, so memo names must be attempt-indexed or the retry's
 * correction and corrected response are silently skipped.
 */
class InferenceFailureHistoryTest extends TestCase
{
    public function test_failed_chat_leaves_history_clean_and_retry_succeeds(): void
    {
        // An empty response queue makes the provider throw on the first call.
        $provider = new FakeAIProvider();
        $history = new InMemoryChatHistory();

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->setChatHistory($history);

        try {
            $agent->chat(new UserMessage('Hello'))->getMessage();
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException) {
        }

        $this->assertCount(0, $history->getMessages());

        // The retry — a fresh request reusing the same thread — succeeds.
        $provider->addResponses(new AssistantMessage('Hi there!'));

        $message = Agent::make()
            ->setAiProvider($provider)
            ->setChatHistory($history)
            ->chat(new UserMessage('Hello'))
            ->getMessage();

        $this->assertSame('Hi there!', $message->getContent());

        $messages = $history->getMessages();
        $this->assertCount(2, $messages);
        $this->assertSame('Hello', $messages[0]->getContent());
        $this->assertSame('Hi there!', $messages[1]->getContent());
    }

    public function test_failed_stream_leaves_history_clean_and_retry_succeeds(): void
    {
        $provider = new FakeAIProvider();
        $history = new InMemoryChatHistory();

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->setChatHistory($history);

        try {
            iterator_to_array($agent->stream(new UserMessage('Hello')));
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException) {
        }

        $this->assertCount(0, $history->getMessages());

        $provider->addResponses(new AssistantMessage('Hi there!'));

        $stream = Agent::make()
            ->setAiProvider($provider)
            ->setChatHistory($history)
            ->stream(new UserMessage('Hello'));

        iterator_to_array($stream);

        $this->assertSame('Hi there!', $stream->getReturn()->getMessage()->getContent());
        $this->assertCount(2, $history->getMessages());
    }

    public function test_failed_structured_leaves_history_clean_and_retry_succeeds(): void
    {
        $provider = new FakeAIProvider();
        $history = new InMemoryChatHistory();

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->setChatHistory($history);

        try {
            $agent->structured(new UserMessage('Generate a user'), User::class);
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException) {
        }

        $this->assertCount(0, $history->getMessages());

        $provider->addResponses(new AssistantMessage('{"name": "Alice"}'));

        $user = Agent::make()
            ->setAiProvider($provider)
            ->setChatHistory($history)
            ->structured(new UserMessage('Generate a user'), User::class);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Alice', $user->name);
        $this->assertCount(2, $history->getMessages());
    }

    public function test_structured_retry_persists_correction_and_corrected_response(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('not a json'),
            new AssistantMessage('{"name": "Alice"}'),
        );
        $history = new InMemoryChatHistory();

        $user = Agent::make()
            ->setAiProvider($provider)
            ->setChatHistory($history)
            ->structured(new UserMessage('Generate a user'), User::class);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Alice', $user->name);

        // [user, assistant(invalid), user(correction), assistant(valid)] — the
        // corrected response must be written even though the first attempt
        // already recorded a response within the same node step.
        $messages = $history->getMessages();
        $this->assertCount(4, $messages);
        $this->assertSame('not a json', $messages[1]->getContent());
        $this->assertSame('{"name": "Alice"}', $messages[3]->getContent());
    }
}
