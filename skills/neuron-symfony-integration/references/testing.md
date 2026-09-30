# Testing the Symfony integration

The suite this file comes from ran 23 tests against SQLite through the app's own migrations: controllers through `KernelBrowser`, handlers called directly, the exception listener on its own. `FakeAIProvider` answered every model call.

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
// tests/bootstrap.php, after Dotenv: a fresh schema for the suite, built by the application's own migrations.
@unlink(dirname(__DIR__).'/var/data_test.db');
passthru(sprintf('php %s/bin/console doctrine:migrations:migrate -n -q --env=test', dirname(__DIR__)), $exit);
if ($exit !== 0) {
    exit($exit);
}
```

```php
namespace App\Tests\Support;

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
        foreach (['chat_messages', 'workflow_store', 'app_user'] as $table) {
            $connection->executeStatement("DELETE FROM {$table}");
        }

        $this->alice = $this->user('alice@example.com');
        $this->bob = $this->user('bob@example.com');
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
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $manager->persist($user);
        $manager->flush();

        return $user;
    }
}
```

## Controllers

```php
public function test_an_approval_suspends_and_decisions_continue(): void
{
    $fake = $this->fakeProvider(new FakeAIProvider(
        new ToolCallMessage(null, [ToolCall::make('refund_order', 'call_1', ['order_id' => 'A-1'])]),
        new AssistantMessage('Refunded.'),
    ));
    $threadId = $this->newThread();

    $this->client->jsonRequest('POST', "/chat/{$threadId}/messages", ['message' => 'Refund A-1']);
    $body = json_decode($this->client->getResponse()->getContent(), true);
    $this->assertSame('awaiting_approval', $body['status']);
    $this->assertSame('call_1', $body['approvals'][0]['id']);

    // A new message while the approval is pending is refused before anything runs.
    $this->client->jsonRequest('POST', "/chat/{$threadId}/messages", ['message' => 'Hello?']);
    $this->assertResponseStatusCodeSame(409);

    $this->client->jsonRequest('POST', "/chat/{$threadId}/messages", ['decisions' => ['call_1' => 'approve']]);
    $this->assertSame('completed', json_decode($this->client->getResponse()->getContent(), true)['status']);
    $fake->assertCallCount(2);
}

public function test_approval_round_trip_and_a_turn_during_it_is_a_409_before_any_frame(): void
{
    $fake = $this->fakeProvider(new FakeAIProvider(
        new ToolCallMessage(null, [ToolCall::make('refund_order', 'call_1', ['order_id' => 'A-1'])]),
        new AssistantMessage('Refunded.'),
    ));
    $threadId = $this->newThread();
    $user = ['id' => 'u1', 'role' => 'user', 'content' => 'Refund A-1'];

    $this->client->jsonRequest('POST', "/chat/{$threadId}/agui", ['threadId' => $threadId, 'messages' => [$user]]);
    $finished = array_values(array_filter($this->frames(), fn (array $f): bool => $f['type'] === 'RUN_FINISHED'))[0];
    $interruptId = $finished['outcome']['interrupts'][0]['id'];

    $this->client->jsonRequest('POST', "/chat/{$threadId}/agui", ['threadId' => $threadId, 'messages' => [$user, ['id' => 'u2', 'role' => 'user', 'content' => 'Hello?']]]);
    $this->assertResponseStatusCodeSame(409);
    $this->assertStringNotContainsString('data:', $this->client->getInternalResponse()->getContent());

    $this->client->jsonRequest('POST', "/chat/{$threadId}/agui", [
        'threadId' => $threadId,
        'messages' => [$user],
        'resume' => [['interruptId' => $interruptId, 'status' => 'resolved', 'payload' => ['approved' => true]]],
    ]);
    $this->assertContains('TOOL_CALL_RESULT', array_column($this->frames(), 'type'));
    $fake->assertCallCount(2);
}

public function test_a_provider_failure_after_the_first_frame_ends_with_run_error(): void
{
    $this->fakeProvider(new FakeAIProvider()); // an empty queue throws on the first inference
    $threadId = $this->newThread();

    $this->client->jsonRequest('POST', "/chat/{$threadId}/agui", ['threadId' => $threadId, 'messages' => [['id' => 'u1', 'role' => 'user', 'content' => 'Hi']]]);

    $this->assertResponseIsSuccessful();
    $this->assertSame(['RUN_STARTED', 'RUN_ERROR'], array_column($this->frames(), 'type'));
}
```

Other controller cases the suite covered: another user's thread is a 403, a frontend tool named like a backend tool is a 400 with the collision message, a frontend tool round trip (the call handed to the browser, then its result as a trailing tool message), the reload returns the `confirmation` interrupt and pages back with `?before=`, a cross-site POST is refused before the provider is called (`$fake->assertNothingSent()`), and the `useChat` variant sends `x-vercel-ai-ui-message-stream: v1`.

## Message handlers

The relay is the channel factory: replace it before anything resolves it, then call the handler.

```php
namespace App\Tests\MessageHandler;

use App\Message\ResumeSupportAgent;
use App\Message\RunSupportAgent;
use App\MessageHandler\SupportAgentHandler;
use App\Neuron\RedisRelay;
use App\Tests\Support\ChatTestCase;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\WorkflowEngine;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

class SupportAgentHandlerTest extends ChatTestCase
{
    protected FakeChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = new FakeChannel();
        static::getContainer()->set(RedisRelay::class, new class ($this->channel) extends RedisRelay {
            public function __construct(protected FakeChannel $fake)
            {
            }

            public function publisher(string $runId): StreamingChannelInterface
            {
                return $this->fake;
            }
        });
    }

    protected function handler(): SupportAgentHandler
    {
        return static::getContainer()->get(SupportAgentHandler::class);
    }

    public function test_a_queued_turn_suspends_and_its_resume_finishes_it(): void
    {
        $fake = $this->fakeProvider(new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('refund_order', 'call_1', ['order_id' => 'A-1'])]),
            new AssistantMessage('Refunded.'),
        ));
        $threadId = $this->newThread();

        $this->handler()->start(new RunSupportAgent($threadId, 'run-1', 'Refund A-1'));

        $this->channel->assertSuspended();
        $this->assertSame('run-1', static::getContainer()->get(WorkflowEngine::class)->inspect($threadId)->runId);

        // Delivered again while it waits for the approval: nothing happens.
        $this->handler()->start(new RunSupportAgent($threadId, 'run-1', 'Refund A-1'));
        $fake->assertCallCount(1);

        $this->handler()->resume(new ResumeSupportAgent($threadId, 'run-2', [
            'resume' => [['interruptId' => 'call_1', 'status' => 'resolved', 'payload' => ['approved' => true]]],
        ]));

        $this->channel->assertCompleted();
        $fake->assertCallCount(2);
    }

    public function test_a_turn_queued_while_another_run_waits_is_given_up(): void
    {
        $this->fakeProvider(new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('refund_order', 'call_1', ['order_id' => 'A-1'])]),
        ));
        $threadId = $this->newThread();
        $this->handler()->start(new RunSupportAgent($threadId, 'run-1', 'Refund A-1'));

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->handler()->start(new RunSupportAgent($threadId, 'run-2', 'Hello?'));
    }

    public function test_a_redelivery_after_success_runs_the_turn_again(): void
    {
        $this->fakeProvider(new FakeAIProvider(new AssistantMessage('One.'), new AssistantMessage('Two.')));
        $threadId = $this->newThread();
        $message = new RunSupportAgent($threadId, 'run-1', 'Hi');

        $this->handler()->start($message);
        $this->handler()->start($message);

        $messages = static::getContainer()->get(MessageStoreInterface::class)->loadAll($threadId);
        $this->assertSame(['Hi', 'One.', 'Hi', 'Two.'], array_map(fn ($m) => $m->getContent(), $messages));
    }
}
```

- `TestContainer::set()` works on a private service only before it is instantiated: set the relay in `setUp()`, before the handler is fetched.
- The suite also proved the failed-turn branch: after a first turn failed (empty fake queue, run `failed`), the next queued turn abandoned it and answered, leaving only the new question and answer in history.
- Dispatching controllers are tested on the `in-memory://` transport: `static::getContainer()->get('messenger.transport.async')->getSent()`. The relay endpoint itself subscribes to Redis inside the response, so it belongs to an end-to-end check with a worker, not to `KernelBrowser`.

## Observability

```php
public function test_symfony_listeners_receive_concrete_neuron_events_only(): void
{
    $this->fakeProvider(new FakeAIProvider(new AssistantMessage('Hi')));
    $seen = ['concrete' => 0, 'parent' => 0];
    $events = static::getContainer()->get(EventDispatcherInterface::class);
    $events->addListener(InferenceStop::class, function () use (&$seen): void { $seen['concrete']++; });
    $events->addListener(ObservabilityEvent::class, function () use (&$seen): void { $seen['parent']++; });

    $this->client->jsonRequest('POST', "/chat/{$this->newThread()}/messages", ['message' => 'Hi']);

    $this->assertSame(['concrete' => 1, 'parent' => 0], $seen);
}
```
