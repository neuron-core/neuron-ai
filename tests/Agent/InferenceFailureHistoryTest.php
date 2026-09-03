<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

use function array_map;
use function iterator_to_array;

/**
 * A failed provider call must not leave the inbound user message dangling in
 * the chat history: the next attempt would append another user message and be
 * rejected by the role-alternation rule ("expected role assistant, got user"),
 * permanently wedging the thread. Inbound messages are committed only after
 * the provider call succeeds.
 *
 * The same failure must not wedge the thread at the workflow layer either:
 * every retry here shares one persistence with the failed turn, so the next
 * chat() supersedes the failed generation instead of being refused.
 *
 * Also guards the structured-output retry loop's history writes: all attempts
 * share one node step, so memo names must be attempt-indexed or the retry's
 * correction and corrected response are silently skipped.
 */
class InferenceFailureHistoryTest extends TestCase
{
    protected function makeAgent(
        FakeAIProvider $provider,
        InMemoryChatHistory $history,
        InMemoryPersistence $persistence,
    ): Agent {
        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->setChatHistory($history);
        $agent->setPersistence($persistence);

        return $agent;
    }

    public function test_failed_chat_leaves_history_clean_and_retry_succeeds(): void
    {
        // An empty response queue makes the provider throw on the first call.
        $provider = new FakeAIProvider();
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();

        try {
            $this->makeAgent($provider, $history, $persistence)->chat(new UserMessage('Hello'))->getMessage();
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException) {
        }

        $this->assertCount(0, $history->getMessages());

        // The retry — a fresh request reusing the same thread — succeeds.
        $provider->addResponses(new AssistantMessage('Hi there!'));

        $message = $this->makeAgent($provider, $history, $persistence)
            ->chat(new UserMessage('Hello'))
            ->getMessage();

        $this->assertSame('Hi there!', $message->getContent());

        $messages = $history->getMessages();
        $this->assertCount(2, $messages);
        $this->assertSame('Hello', $messages[0]->getContent());
        $this->assertSame('Hi there!', $messages[1]->getContent());
    }

    public function test_failed_durable_turn_is_superseded_by_a_turn_with_a_new_message(): void
    {
        $provider = new FakeAIProvider();
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();

        try {
            $this->makeAgent($provider, $history, $persistence)->chat(new UserMessage('First try'));
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException) {
        }

        // The failed generation is still recorded under the thread. The user
        // is free to send something else: the next turn sweeps it rather than
        // replaying a message they no longer want to send.
        $threadId = (string) $history->getThreadId();
        $this->assertNotNull($persistence->get($threadId, '__control'));

        $provider->addResponses(new AssistantMessage('Second reply'));
        $state = $this->makeAgent($provider, $history, $persistence)->chat(new UserMessage('Second try'));

        $this->assertSame('Second reply', $state->getMessage()->getContent());

        // The failed call was never recorded; the only request carries the new message alone.
        $requests = $provider->getRecorded();
        $this->assertCount(1, $requests);
        $this->assertSame(['Second try'], array_map(
            fn (Message $message): string => (string) $message->getContent(),
            $requests[0]->messages,
        ));
        $this->assertSame(['Second try', 'Second reply'], array_map(
            fn (Message $message): string => (string) $message->getContent(),
            $history->getMessages(),
        ));
        $this->assertNull($persistence->get($threadId, '__control'));
    }

    public function test_failed_stream_leaves_history_clean_and_retry_succeeds(): void
    {
        $provider = new FakeAIProvider();
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();

        try {
            iterator_to_array($this->makeAgent($provider, $history, $persistence)->stream(new UserMessage('Hello')));
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException) {
        }

        $this->assertCount(0, $history->getMessages());

        $provider->addResponses(new AssistantMessage('Hi there!'));

        $stream = $this->makeAgent($provider, $history, $persistence)->stream(new UserMessage('Hello'));

        iterator_to_array($stream);

        $this->assertSame('Hi there!', $stream->getReturn()->getMessage()->getContent());
        $this->assertCount(2, $history->getMessages());
    }

    public function test_failed_structured_leaves_history_clean_and_retry_succeeds(): void
    {
        $provider = new FakeAIProvider();
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();

        try {
            $this->makeAgent($provider, $history, $persistence)->structured(new UserMessage('Generate a user'), User::class);
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException) {
        }

        $this->assertCount(0, $history->getMessages());

        $provider->addResponses(new AssistantMessage('{"name": "Alice"}'));

        $user = $this->makeAgent($provider, $history, $persistence)
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
