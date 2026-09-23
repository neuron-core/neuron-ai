<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Observability\Events\AgentError;
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
        InMemoryMessageStore $messageStore,
        InMemoryPersistence $persistence,
    ): Agent {
        $agent = Agent::make(workflowId: 'thread');
        $agent->setAiProvider($provider);
        $agent->setMessageStore($messageStore);
        $agent->setPersistence($persistence);

        return $agent;
    }

    public function test_failed_chat_leaves_history_clean_and_retry_succeeds(): void
    {
        // An empty response queue makes the provider throw on the first call.
        $provider = new FakeAIProvider();
        $messageStore = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        $errors = [];
        $agent = $this->makeAgent($provider, $messageStore, $persistence)
            ->subscribe(AgentError::class, function (AgentError $event) use (&$errors): void {
                $errors[] = $event;
            });

        try {
            $agent->chat(new UserMessage('Hello'))->getMessage();
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException $exception) {
            $this->assertCount(1, $errors);
            $this->assertSame($exception, $errors[0]->exception);
        }

        $this->assertCount(0, $messageStore->loadActive('thread'));

        // The retry — a fresh request reusing the same thread — succeeds.
        $provider->addResponses(new AssistantMessage('Hi there!'));

        $message = $this->makeAgent($provider, $messageStore, $persistence)
            ->chat(new UserMessage('Hello'))
            ->getMessage();

        $this->assertSame('Hi there!', $message->getContent());

        $messages = $messageStore->loadActive('thread');
        $this->assertCount(2, $messages);
        $this->assertSame('Hello', $messages[0]->getContent());
        $this->assertSame('Hi there!', $messages[1]->getContent());
    }

    public function test_failed_durable_turn_is_superseded_by_a_turn_with_a_new_message(): void
    {
        $provider = new FakeAIProvider();
        $messageStore = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();

        try {
            $this->makeAgent($provider, $messageStore, $persistence)->chat(new UserMessage('First try'));
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException) {
        }

        // The failed generation is still recorded under the thread. The user
        // is free to send something else: the next turn sweeps it rather than
        // replaying a message they no longer want to send.
        $threadId = 'thread';
        $this->assertNotNull($persistence->get($threadId, '__control'));

        $provider->addResponses(new AssistantMessage('Second reply'));
        $state = $this->makeAgent($provider, $messageStore, $persistence)->chat(new UserMessage('Second try'));

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
            $messageStore->loadActive('thread'),
        ));
        $this->assertNull($persistence->get($threadId, '__control'));
    }

    public function test_failed_stream_leaves_history_clean_and_retry_succeeds(): void
    {
        $provider = new FakeAIProvider();
        $messageStore = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        $errors = [];
        $agent = $this->makeAgent($provider, $messageStore, $persistence)
            ->subscribe(AgentError::class, function (AgentError $event) use (&$errors): void {
                $errors[] = $event;
            });

        try {
            iterator_to_array($agent->stream(new UserMessage('Hello')));
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException $exception) {
            $this->assertCount(1, $errors);
            $this->assertSame($exception, $errors[0]->exception);
        }

        $this->assertCount(0, $messageStore->loadActive('thread'));

        $provider->addResponses(new AssistantMessage('Hi there!'));

        $stream = $this->makeAgent($provider, $messageStore, $persistence)->stream(new UserMessage('Hello'));

        iterator_to_array($stream);

        $this->assertSame('Hi there!', $stream->getReturn()->getMessage()->getContent());
        $this->assertCount(2, $messageStore->loadActive('thread'));
    }

    public function test_failed_structured_leaves_history_clean_and_retry_succeeds(): void
    {
        $provider = new FakeAIProvider();
        $messageStore = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();

        try {
            $this->makeAgent($provider, $messageStore, $persistence)->structured(new UserMessage('Generate a user'), User::class);
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException) {
        }

        $this->assertCount(0, $messageStore->loadActive('thread'));

        $provider->addResponses(new AssistantMessage('{"name": "Alice"}'));

        $user = $this->makeAgent($provider, $messageStore, $persistence)
            ->structured(new UserMessage('Generate a user'), User::class);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Alice', $user->name);
        $this->assertCount(2, $messageStore->loadActive('thread'));
    }

    public function test_structured_retry_persists_correction_and_corrected_response(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('not a json'),
            new AssistantMessage('{"name": "Alice"}'),
        );
        $messageStore = new InMemoryMessageStore();

        $user = Agent::make(workflowId: 'thread')
            ->setAiProvider($provider)
            ->setMessageStore($messageStore)
            ->structured(new UserMessage('Generate a user'), User::class);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Alice', $user->name);

        // [user, assistant(invalid), user(correction), assistant(valid)] — the
        // corrected response must be written even though the first attempt
        // already recorded a response within the same node step.
        $messages = $messageStore->loadActive('thread');
        $this->assertCount(4, $messages);
        $this->assertSame('not a json', $messages[1]->getContent());
        $this->assertSame('{"name": "Alice"}', $messages[3]->getContent());
    }
}
