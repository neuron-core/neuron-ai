# Testing the Symfony integration

The suite this file comes from ran 48 tests through the app's own migrations, on SQLite and, with the server bootstrap below, on MySQL 8.4, MariaDB 11.7 and PostgreSQL 17: controllers through `KernelBrowser`, handlers called directly, the exception listener on its own. `FakeAIProvider` answered every model call except in the Stop tests.

## Schema and base class

```yaml
# config/packages/messenger.yaml
when@test:
    framework:
        messenger:
            transports:
                async: 'in-memory://'
```

```php
// tests/bootstrap.php, after Dotenv. SQLite (var/data_test.db): a fresh schema for the suite, built by the application's own migrations.
@unlink(dirname(__DIR__).'/var/data_test.db');
passthru(sprintf('php %s/bin/console doctrine:migrations:migrate -n -q --env=test', dirname(__DIR__)), $exit);
if ($exit !== 0) {
    exit($exit);
}
```

That bootstrap is for the recipe's SQLite `DATABASE_URL`. On MySQL, MariaDB or PostgreSQL, `when@test` appends `_test` to the database name and nothing creates that database: the SQLite bootstrap stopped with `SQLSTATE[HY000] [1049] Unknown database '…_test'`. There, rebuild the database instead (`doctrine:database:drop --if-exists` is not supported on SQLite):

```php
// tests/bootstrap.php, after Dotenv. MySQL, MariaDB, PostgreSQL: a fresh test database for the suite, built by the application's own migrations.
foreach (['doctrine:database:drop --force --if-exists', 'doctrine:database:create', 'doctrine:migrations:migrate -n'] as $command) {
    passthru(sprintf('php %s/bin/console %s -q --env=test', dirname(__DIR__), $command), $exit);
    if ($exit !== 0) {
        exit($exit);
    }
}
```

```php
namespace App\Tests\Support;

use App\Entity\Order;
use App\Entity\User;
use App\Neuron\Agents\SupportAgent;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\Testing\FakeAIProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ChatTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected User $alice;

    protected User $bob;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // One kernel for the whole test: a reboot would drop the fake set on the agent.
        $this->client->disableReboot();

        $connection = static::getContainer()->get(Connection::class);
        foreach (['chat_messages', 'workflow_store', 'orders', 'app_user'] as $table) {
            $connection->executeStatement("DELETE FROM {$table}");
        }

        $this->alice = $this->user('alice@example.com');
        $this->bob = $this->user('bob@example.com');
        $this->persist(new Order('A-1', $this->alice->getId()), new Order('B-1', $this->bob->getId()));
        $this->client->loginUser($this->alice);
        // What a same-origin fetch() sends: the stateless CSRF check needs both.
        $this->client->setServerParameter('HTTP_SEC_FETCH_SITE', 'same-origin');
        $this->client->setServerParameter('HTTP_X_CSRF_TOKEN', 'csrf-token');
    }

    protected function fakeProvider(FakeAIProvider $fake): FakeAIProvider
    {
        static::getContainer()->get(SupportAgent::class)->setAiProvider($fake);

        return $fake;
    }

    protected function newThread(): string
    {
        $this->client->jsonRequest('POST', '/chat');

        return json_decode($this->client->getResponse()->getContent(), true)['threadId'];
    }

    /** @return list<array<string, mixed>> */
    protected function frames(): array
    {
        $body = $this->client->getInternalResponse()->getContent();
        preg_match_all('/^data: (.+)$/m', $body, $matches);

        return array_map(fn (string $json): array => json_decode($json, true), $matches[1]);
    }

    protected function user(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->persist($user);

        return $user;
    }

    protected function persist(object ...$entities): void
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($entities as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
    }
}
```

## Controllers

```php
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;

public function test_an_approval_suspends_and_decisions_continue(): void
{
    $fake = $this->fakeProvider(new FakeAIProvider(
        new ToolCallMessage(tools: [ToolCall::make(name: 'refund_order', callId: 'call_1', inputs: ['order_id' => 'A-1'])]),
        new AssistantMessage('Refunded.'),
    ));
    $threadId = $this->newThread();

    $this->client->jsonRequest('POST', "/chat/{$threadId}/messages", ['message' => 'Refund A-1']);
    $body = json_decode($this->client->getResponse()->getContent(), true);
    $this->assertSame('awaiting_approval', $body['status']);
    $this->assertSame('call_1', $body['approvals'][0]['id']);
    $this->assertSame('Refunds move money: a person must confirm them.', $body['approvals'][0]['reason']);

    // A new message while the approval is pending is refused before anything runs.
    $this->client->jsonRequest('POST', "/chat/{$threadId}/messages", ['message' => 'Hello?']);
    $this->assertResponseStatusCodeSame(409);
    $this->assertSame(['error' => 'The conversation is busy.', 'status' => 'suspended'], json_decode($this->client->getResponse()->getContent(), true));

    $this->client->jsonRequest('POST', "/chat/{$threadId}/messages", ['decisions' => ['call_1' => 'approve']]);
    $body = json_decode($this->client->getResponse()->getContent(), true);
    $this->assertSame('completed', $body['status']);
    $fake->assertCallCount(2);
}

public function test_approval_round_trip_and_a_turn_during_it_is_a_409_before_any_frame(): void
{
    $fake = $this->fakeProvider(new FakeAIProvider(
        new ToolCallMessage(tools: [ToolCall::make(name: 'refund_order', callId: 'call_1', inputs: ['order_id' => 'A-1'])]),
        new AssistantMessage('Refunded.'),
    ));
    $threadId = $this->newThread();
    $user = ['id' => 'u1', 'role' => 'user', 'content' => 'Refund A-1'];

    $this->client->jsonRequest('POST', "/chat/{$threadId}/agui", ['threadId' => $threadId, 'messages' => [$user]]);
    $finished = array_values(array_filter($this->frames(), fn (array $f): bool => $f['type'] === 'RUN_FINISHED'))[0];
    $this->assertSame('interrupt', $finished['outcome']['type']);
    $interruptId = $finished['outcome']['interrupts'][0]['id'];

    $this->client->jsonRequest('POST', "/chat/{$threadId}/agui", ['threadId' => $threadId, 'messages' => [$user, ['id' => 'u2', 'role' => 'user', 'content' => 'Hello?']]]);
    $this->assertResponseStatusCodeSame(409);
    $this->assertStringNotContainsString('data:', $this->client->getInternalResponse()->getContent());

    $this->client->jsonRequest('POST', "/chat/{$threadId}/agui", [
        'threadId' => $threadId,
        'messages' => [$user],
        'resume' => [['interruptId' => $interruptId, 'status' => 'resolved', 'payload' => ['approved' => true]]],
    ]);
    $types = array_column($this->frames(), 'type');
    $this->assertContains('TOOL_CALL_RESULT', $types);
    $this->assertSame('RUN_FINISHED', end($types));
    $fake->assertCallCount(2);
}

public function test_a_provider_failure_after_the_first_frame_ends_with_run_error(): void
{
    $this->fakeProvider(new FakeAIProvider()); // an empty queue throws on the first inference
    $threadId = $this->newThread();

    $this->client->jsonRequest('POST', "/chat/{$threadId}/agui", ['threadId' => $threadId, 'messages' => [['id' => 'u1', 'role' => 'user', 'content' => 'Hi']]]);

    $this->assertResponseIsSuccessful();
    $types = array_column($this->frames(), 'type');
    $this->assertSame(['RUN_STARTED', 'RUN_ERROR'], $types);
}

public function test_a_turn_after_one_that_failed_past_a_tool_finishes_it_first(): void
{
    $fake = $this->fakeProvider(new FakeAIProvider(
        new ToolCallMessage(tools: [ToolCall::make(name: 'order_status', callId: 'call_1', inputs: ['order_id' => 'A-1'])]),
    )); // the answer after the tool fails: nothing more is queued
    $threadId = $this->newThread();
    $this->client->jsonRequest('POST', "/chat/{$threadId}/messages", ['message' => 'Where is A-1?']);
    $this->assertResponseStatusCodeSame(500);

    $fake->addResponses(new AssistantMessage('A-1 shipped.'), new AssistantMessage('Hello!'));
    $this->client->jsonRequest('POST', "/chat/{$threadId}/messages", ['message' => 'Hi']);

    $this->assertSame(['status' => 'completed', 'answer' => 'Hello!'], json_decode($this->client->getResponse()->getContent(), true));
    $this->assertSame(
        ['user:Where is A-1?', 'assistant:tool_call', 'user:tool_call_result', 'assistant:A-1 shipped.', 'user:Hi', 'assistant:Hello!'],
        $this->history($threadId),
    );
}

/** @return list<string> role:content of the stored history, tool messages by type */
protected function history(string $threadId): array
{
    return array_map(
        fn ($m): string => $m->getRole().':'.($m instanceof ToolCallMessage || $m instanceof ToolResultMessage ? $m->jsonSerialize()['type'] : $m->getContent()),
        static::getContainer()->get(MessageStoreInterface::class)->loadAll($threadId),
    );
}
```

Other controller cases the suite covered: another user's thread is a 403, a frontend tool named like a backend tool is a 400 with the collision message, a frontend tool round trip (the call handed to the browser, then its result as a trailing tool message), the reload returns the `confirmation` interrupt and pages back with `?before=`, a cross-site POST is refused before the provider is called (`$fake->assertNothingSent()`), a failure before anything was stored is finished by the next turn too, the tools never reach another customer's order, a tool call without arguments keeps `"inputs":{}`, and the `useChat` variant sends `x-vercel-ai-ui-message-stream: v1` and finishes a failed turn first.

## Message handlers

The relay is the handlers' channel factory and the relay endpoint's reader. The test double replaces both halves: handlers publish to a `FakeChannel`, and the endpoint only dispatches.

```php
namespace App\Tests\Support;

use App\Neuron\RedisRelay;
use Closure;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;

/** The relay without Redis: handlers publish to a FakeChannel, the relay endpoint only dispatches. */
class FakeRelay extends RedisRelay
{
    public function __construct(public readonly FakeChannel $channel = new FakeChannel())
    {
    }

    public function publisher(string $runId): StreamingChannelInterface
    {
        return $this->channel;
    }

    public function relay(string $runId, Closure $dispatch, StreamAdapterInterface $adapter): Closure
    {
        $dispatch();

        return fn () => null;
    }
}
```

```php
namespace App\Tests\MessageHandler;

use App\Message\ResumeSupportAgent;
use App\Message\RunSupportAgent;
use App\MessageHandler\SupportAgentHandler;
use App\Neuron\PublishRunFailure;
use App\Neuron\RedisRelay;
use App\Repository\OrderRepository;
use App\Tests\Support\ChatTestCase;
use App\Tests\Support\FakeRelay;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\WorkflowEngine;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Throwable;

class SupportAgentHandlerTest extends ChatTestCase
{
    protected FakeRelay $relay;

    protected function setUp(): void
    {
        parent::setUp();
        // The relay is the channel factory: replace it before anything resolves it.
        static::getContainer()->set(RedisRelay::class, $this->relay = new FakeRelay());
    }

    protected function handler(): SupportAgentHandler
    {
        return static::getContainer()->get(SupportAgentHandler::class);
    }

    /** @return array<string, mixed> the AG-UI input a browser sends with one new user message */
    protected function input(string $text, array $extra = []): array
    {
        return ['runId' => 'client-run', 'messages' => [['id' => 'u1', 'role' => 'user', 'content' => $text]], ...$extra];
    }

    protected function approve(string $threadId, string $runId): ResumeSupportAgent
    {
        return new ResumeSupportAgent($threadId, $runId, [
            'resume' => [['interruptId' => 'call_1', 'status' => 'resolved', 'payload' => ['approved' => true]]],
        ]);
    }

    /** @return list<string> */
    protected function history(string $threadId): array
    {
        return array_map(
            fn ($m): string => $m->getRole().':'.($m instanceof ToolCallMessage || $m instanceof ToolResultMessage ? $m->jsonSerialize()['type'] : $m->getContent()),
            static::getContainer()->get(MessageStoreInterface::class)->loadAll($threadId),
        );
    }

    protected function runStatus(string $threadId): ?string
    {
        return static::getContainer()->get(WorkflowEngine::class)->inspect($threadId)?->status->value;
    }

    public function test_a_queued_turn_suspends_and_its_resume_finishes_it(): void
    {
        $fake = $this->fakeProvider(new FakeAIProvider(
            new ToolCallMessage(tools: [ToolCall::make(name: 'refund_order', callId: 'call_1', inputs: ['order_id' => 'A-1'])]),
            new AssistantMessage('Refunded.'),
        ));
        $threadId = $this->newThread();
        $message = new RunSupportAgent($threadId, 'run-1', 'Refund A-1', $this->input('Refund A-1'));

        $this->handler()->start($message);

        $this->relay->channel->assertSuspended();
        $this->assertSame('run-1', static::getContainer()->get(WorkflowEngine::class)->inspect($threadId)->runId);

        // Delivered again while it waits for the approval: nothing happens.
        $this->handler()->start($message);
        $fake->assertCallCount(1);

        $this->handler()->resume($this->approve($threadId, 'run-1'));

        $this->relay->channel->assertCompleted();
        $fake->assertCallCount(2);
        $this->assertSame('refunded', static::getContainer()->get(OrderRepository::class)->findForCustomer('A-1', $this->alice->getId())->getStatus());
    }

    public function test_a_turn_after_one_that_failed_past_a_tool_finishes_it_first(): void
    {
        $fake = $this->fakeProvider(new FakeAIProvider(
            new ToolCallMessage(tools: [ToolCall::make(name: 'order_status', callId: 'call_1', inputs: ['order_id' => 'A-1'])]),
        )); // the answer after the tool fails: nothing more is queued
        $threadId = $this->newThread();
        try {
            $this->handler()->start(new RunSupportAgent($threadId, 'run-1', 'Where is A-1?', $this->input('Where is A-1?')));
            $this->fail('The first turn should fail.');
        } catch (Throwable) {
        }
        $this->assertSame('failed', $this->runStatus($threadId));

        $fake->addResponses(new AssistantMessage('A-1 shipped.'), new AssistantMessage('Hello!'));
        $this->handler()->start(new RunSupportAgent($threadId, 'run-2', 'Hi', $this->input('Hi')));

        $this->assertNull($this->runStatus($threadId));
        $this->assertSame(['user:Where is A-1?', 'assistant:tool_call', 'user:tool_call_result', 'assistant:A-1 shipped.', 'user:Hi', 'assistant:Hello!'], $this->history($threadId));
        // The dead run finished on a copy without a channel: only run-2 reached the relay.
        $this->assertCount(1, $this->relay->channel->getCompletions());
    }

    public function test_a_run_that_failed_in_its_segment_is_not_published_twice(): void
    {
        $this->fakeProvider(new FakeAIProvider()); // the inference fails
        $threadId = $this->newThread();
        $message = new RunSupportAgent($threadId, 'run-1', 'Hi', $this->input('Hi'));
        try {
            $this->handler()->start($message);
        } catch (Throwable $failure) {
        }
        $this->assertSame(['RUN_STARTED', 'RUN_ERROR'], array_map(fn ($e): string => $e->type, $this->relay->channel->getSent()));

        static::getContainer()->get(PublishRunFailure::class)(new WorkerMessageFailedEvent(new Envelope($message), 'async', $failure));

        $this->assertSame(['RUN_STARTED', 'RUN_ERROR'], array_map(fn ($e): string => $e->type, $this->relay->channel->getSent()));
        $this->assertCount(1, $this->relay->channel->getFailures());
    }
}
```

- `TestContainer::set()` works on a private service only before it is instantiated: set the relay in `setUp()`, before the handler is fetched.
- The suite also covered: a snapshot equal to the synchronous endpoint's, a browser tool handed over and continued in a queued turn, a turn given up while another run waits, a refund approved while the payment API threw and finished by the next turn, a continuation retried after it failed past the approved refund, a stale continuation that never finishes another run, a redelivery after success that runs the turn again, a delivery failure reported as `ChannelError` without failing the run, a channel factory that throws and fails it, and a refused turn published once.

## The relay endpoint

With the double's `relay()`, the endpoint dispatches onto the `in-memory://` transport and streams nothing:

```php
namespace App\Tests\Controller;

use App\Message\RunSupportAgent;
use App\Neuron\RedisRelay;
use App\Tests\Support\ChatTestCase;
use App\Tests\Support\FakeRelay;

class BackgroundChatControllerTest extends ChatTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->set(RedisRelay::class, new FakeRelay());
    }

    /** @return list<object> the messages queued on the in-memory transport */
    protected function queued(): array
    {
        return array_map(fn ($envelope) => $envelope->getMessage(), static::getContainer()->get('messenger.transport.async')->getSent());
    }

    public function test_a_turn_is_queued_with_the_browsers_input(): void
    {
        $threadId = $this->newThread();
        $input = ['threadId' => $threadId, 'runId' => 'client-run', 'messages' => [['id' => 'u1', 'role' => 'user', 'content' => 'Hi']]];

        $this->client->jsonRequest('POST', "/chat/{$threadId}/agui-background", $input);

        $this->assertResponseHeaderSame('content-type', 'text/event-stream; charset=UTF-8');
        [$message] = $this->queued();
        $this->assertInstanceOf(RunSupportAgent::class, $message);
        $this->assertSame([$threadId, 'Hi', $input], [$message->threadId, $message->message, $message->input]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $message->runId);
    }

    public function test_a_bad_resume_is_a_400_and_nothing_is_queued(): void
    {
        $threadId = $this->newThread();

        $this->client->jsonRequest('POST', "/chat/{$threadId}/agui-background", [
            'threadId' => $threadId,
            'messages' => [],
            'resume' => [['interruptId' => 'call_9', 'status' => 'resolved', 'payload' => ['approved' => true]]],
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame([], $this->queued());
    }
}
```

A trailing tool message is queued as a `ResumeSupportAgent` carrying the suspended run's ID, and a frontend tool named like a backend tool is a 400 with nothing queued. The relay itself, with Redis and a worker, belongs to an end-to-end check.

## Stop

`FakeAIProvider` replaces the provider together with its Stop client, so a faked turn never stops. The flag is testable through the endpoints (the Stop endpoint's 204, `raised()`, the next turn clearing it); a real stop needs the real provider over a scripted transport. The agent's `transport()` hook is the seam:

```php
namespace App\Tests\Support;

use App\Neuron\Agents\SupportAgent;
use Closure;
use LogicException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/**
 * The real provider and Stop client over a transport that streams $words as an Anthropic answer,
 * one SSE event per line. $afterWord runs after each word is read: a test presses Stop there.
 */
class ScriptedStreamSupportAgent extends SupportAgent
{
    /** @var list<string> */
    public array $words = [];

    public ?Closure $afterWord = null;

    protected function transport(): HttpClientInterface
    {
        return new class ($this->words, $this->afterWord) implements HttpClientInterface, StreamInterface {
            /** @var list<string> */
            protected array $lines = [];

            protected int $read = 0;

            public function __construct(protected array $words, protected ?Closure $afterWord)
            {
            }

            public function request(HttpRequest $request): HttpResponse
            {
                throw new LogicException('This transport only streams.');
            }

            public function stream(HttpRequest $request): StreamInterface
            {
                $events = [
                    ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]],
                    ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                    ...array_map(fn (string $word): array => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $word]], $this->words),
                    ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => count($this->words)]],
                ];
                $this->lines = array_map(fn (array $event): string => 'data: '.json_encode($event)."\n", $events);

                return $this;
            }

            public function eof(): bool
            {
                return $this->lines === [];
            }

            public function readLine(): string
            {
                $line = array_shift($this->lines) ?? '';
                if ($this->afterWord !== null && str_contains($line, 'text_delta')) {
                    ($this->afterWord)(++$this->read);
                }

                return $line;
            }

            public function read(int $length): string
            {
                return $this->readLine();
            }

            public function close(): void
            {
                $this->lines = [];
            }
        };
    }
}
```

```yaml
# config/services.yaml
when@test:
    services:
        # The agent over a scripted provider stream, for the Stop tests.
        App\Tests\Support\ScriptedStreamSupportAgent:
            autowire: true
            public: true
```

```php
namespace App\Tests\Controller;

use App\Neuron\Agents\SupportAgent;
use App\Neuron\StopSignal;
use App\Tests\Support\ChatTestCase;
use App\Tests\Support\ScriptedStreamSupportAgent;
use NeuronAI\Chat\History\MessageStoreInterface;

class StopTest extends ChatTestCase
{
    public function test_stop_ends_the_streamed_answer_and_keeps_its_text(): void
    {
        // Before the first request builds a controller: the scripted agent becomes the app's agent.
        $agent = static::getContainer()->get(ScriptedStreamSupportAgent::class);
        static::getContainer()->set(SupportAgent::class, $agent);
        $threadId = $this->newThread();
        $agent->words = ['Once', ' upon', ' a', ' time'];
        // The user presses Stop while the second word streams.
        $agent->afterWord = fn (int $read) => $read === 2 ? static::getContainer()->get(StopSignal::class)->raise($threadId) : null;

        $this->client->jsonRequest('POST', "/chat/{$threadId}/agui", ['threadId' => $threadId, 'messages' => [['id' => 'u1', 'role' => 'user', 'content' => 'Tell me a story']]]);

        $this->assertSame(['TEXT_MESSAGE_END', 'RUN_FINISHED'], array_slice(array_column($this->frames(), 'type'), -2));
        $answer = static::getContainer()->get(MessageStoreInterface::class)->loadAll($threadId)[1];
        $this->assertSame('Once upon', $answer->getContent());
        $this->assertSame('stopped', $answer->stopReason());
        $this->assertTrue(static::getContainer()->get(StopSignal::class)->raised($threadId), 'The flag stays raised until the next turn.');
    }
}
```

A queued turn with the flag raised failed on two deliveries in a row (`The stream was stopped before the answer started.`) with nothing stored, and the next turn, the flag cleared, finished it before its own answer.

## Observability

```php
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Testing\FakeAIProvider;
// Symfony's dispatcher interface: PSR-14's has no addListener().
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

public function test_symfony_listeners_receive_concrete_neuron_events_only(): void
{
    $this->fakeProvider(new FakeAIProvider(new AssistantMessage('Hi')));
    $seen = ['concrete' => 0, 'parent' => 0];
    $events = static::getContainer()->get(EventDispatcherInterface::class);
    $events->addListener(InferenceStop::class, function () use (&$seen): void { $seen['concrete']++; });
    $events->addListener(ObservabilityEvent::class, function () use (&$seen): void { $seen['parent']++; });

    $this->client->jsonRequest('POST', "/chat/{$this->newThread()}/messages", ['message' => 'Hi']);

    $this->assertResponseIsSuccessful();
    $this->assertSame(['concrete' => 1, 'parent' => 0], $seen);
}
```

The same suite showed a listener that throws on `InferenceStop` failing the turn (a 500), and listeners that throw on `WorkflowStart`, `WorkflowNodeEnd` and `WorkflowEnd` reported as `WorkflowError` while the turn completed.
