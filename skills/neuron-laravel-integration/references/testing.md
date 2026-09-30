# Feature tests for the Laravel integration

These tests ran green on SQLite `:memory:`, MySQL 8.4, MariaDB 11.7 and PostgreSQL 17 with `RefreshDatabase`. They drive the real routes, container, stores and jobs; only the provider and the job's channel are fakes.

## The seam

```php
namespace Tests;

use App\Neuron\Agents\SupportAgent;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Testing\FakeAIProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * The container's agent answers with $responses: the controller or job that resolves it gets this instance.
     */
    protected function fakeSupportAgent(Message ...$responses): FakeAIProvider
    {
        $provider = new FakeAIProvider(...$responses);

        $this->app->instance(SupportAgent::class, $this->app->make(SupportAgent::class)->setAiProvider($provider));

        return $provider;
    }
}
```

`setAiProvider()` beats the `provider()` hook, and the `for()` copy the controller makes shares it. Script a tool call with `new ToolCallMessage(null, [ToolCall::make('refund_order', 'call_1', ['order_id' => 1])])`; an empty `FakeAIProvider` throws `ProviderException` on the next inference, which is how the tests below inject a provider failure.

## A JSON turn with an approval

```php
use RefreshDatabase;

public function test_a_refund_waits_for_approval_then_runs_once(): void
{
    $user = User::factory()->create();
    $order = Order::create(['user_id' => $user->id, 'status' => 'shipped', 'total' => 40]);
    $provider = $this->fakeSupportAgent(
        new ToolCallMessage(null, [ToolCall::make('refund_order', 'call_1', ['order_id' => $order->id])]),
        new AssistantMessage('Your order was refunded.'),
    );
    $threadId = "user-{$user->id}-t1";

    $this->actingAs($user)->postJson("/chat/threads/{$threadId}/messages", ['message' => 'Refund my order'])
        ->assertOk()
        ->assertJsonPath('status', 'awaiting_approval')
        ->assertJsonPath('approvals.0.id', 'call_1')
        ->assertJsonPath('approvals.0.reason', 'Refunds move money back to the customer.');
    $this->assertSame('shipped', $order->fresh()->status);

    // A new message while the approval is pending is refused before anything runs.
    $this->postJson("/chat/threads/{$threadId}/messages", ['message' => 'Hello?'])
        ->assertStatus(409)
        ->assertJsonPath('status', 'suspended');

    $this->postJson("/chat/threads/{$threadId}/messages", ['decisions' => ['call_1' => 'approve']])
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('message.content.0.content', 'Your order was refunded.');

    $this->assertSame('refunded', $order->fresh()->status);
    $provider->assertCallCount(2);
}

public function test_another_users_thread_is_forbidden(): void
{
    [$owner, $intruder] = User::factory()->count(2)->create();
    $provider = $this->fakeSupportAgent(new AssistantMessage('never'));

    $this->actingAs($intruder)->postJson("/chat/threads/user-{$owner->id}-t4/messages", ['message' => 'Hi'])
        ->assertForbidden();

    $provider->assertNothingSent();
}
```

A serialized message is `{"__id": …, "role": "assistant", "content": [{"type": "text", "content": "…"}]}`: assert on `message.content.0.content`.

## A streamed AG-UI turn

A plain turn asserts `->assertOk()->assertStreamed()->assertHeader('Content-Type', 'text/event-stream; charset=utf-8')` and frames from `RUN_STARTED` to `RUN_FINISHED`. The approval round trip:

```php
public function test_an_approval_suspends_refuses_a_new_turn_and_continues(): void
{
    $user = User::factory()->create();
    $order = Order::create(['user_id' => $user->id, 'status' => 'shipped', 'total' => 40]);
    $provider = $this->fakeSupportAgent(
        new ToolCallMessage(null, [ToolCall::make('refund_order', 'call_1', ['order_id' => $order->id])]),
        new AssistantMessage('Refunded.'),
    );
    $threadId = "user-{$user->id}-t2";

    $frames = $this->frames($this->actingAs($user)->postJson('/chat/agui', $this->input($threadId, 'Refund my order')));
    $finished = end($frames);
    $this->assertSame('interrupt', $finished['outcome']['type']);
    $this->assertSame('call_1', $finished['outcome']['interrupts'][0]['id']);

    // Primed before any header: the pending approval refuses a new turn with a plain 409.
    $this->postJson('/chat/agui', $this->input($threadId, 'Hello?'))
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('status', 'suspended');

    $resume = [...$this->input($threadId, 'Refund my order'), 'resume' => [
        ['interruptId' => 'call_1', 'status' => 'resolved', 'payload' => ['approved' => true]],
    ]];
    $types = array_column($this->frames($this->postJson('/chat/agui', $resume)), 'type');

    $this->assertContains('TOOL_CALL_RESULT', $types);
    $this->assertSame('RUN_FINISHED', end($types));
    $this->assertSame('refunded', $order->fresh()->status);
    $provider->assertCallCount(2);
}

protected function input(string $threadId, string $message): array
{
    return [
        'threadId' => $threadId,
        'runId' => 'client-run-1',
        'messages' => [['id' => 'u1', 'role' => 'user', 'content' => $message]],
        'tools' => [],
        'state' => [],
    ];
}

/** @return list<array<string, mixed>> */
protected function frames(TestResponse $response): array
{
    preg_match_all('/^data: (.+)$/m', $response->streamedContent(), $matches);

    return array_map(fn (string $json): array => json_decode($json, true), $matches[1]);
}
```

`streamedContent()` runs the stream callback inside an output buffer; `getContent()` is `false` on a streamed response, so `assertSee()` does not apply. The same file also pins the input middleware: an AG-UI seed message with `content: ""` and one starting with spaces come back unchanged in `MESSAGES_SNAPSHOT` only while the agent routes are excluded from `TrimStrings` and `ConvertEmptyStringsToNull`.

## Reload

```php
$page = $this->getJson("/chat/threads/{$threadId}")->assertOk();
$this->assertSame(['user', 'assistant', 'user'], array_column($page->json('messages'), 'role'));
$page->assertJsonPath('interrupts.0.id', 'call_1')->assertJsonPath('status', 'suspended');

// Older pages: the stored ID of the first loaded message is the cursor, and no run is attached.
$this->getJson("/chat/threads/{$threadId}?before={$page->json('messages.1.id')}")
    ->assertJsonCount(1, 'messages')
    ->assertJsonPath('interrupts', [])
    ->assertJsonPath('status', null);
```

## Jobs

The job publishes through the container's `ChannelFactory`; bind one that returns a `FakeChannel`:

```php
protected FakeChannel $channel;

protected function setUp(): void
{
    parent::setUp();

    // Every segment, and the job's failure hook, publish on this fake.
    $this->channel = new FakeChannel;
    $this->app->instance(ChannelFactory::class, new class($this->channel) implements ChannelFactory
    {
        public function __construct(protected FakeChannel $channel) {}

        public function make(string $threadId, string $runId): StreamingChannelInterface
        {
            return $this->channel;
        }
    });
}

public function test_the_endpoint_queues_the_turn_with_a_reserved_run_id(): void
{
    Queue::fake();
    $user = User::factory()->create();
    $threadId = "user-{$user->id}-t1";

    $runId = $this->actingAs($user)->postJson("/chat/threads/{$threadId}/runs", ['message' => 'Hi'])
        ->assertAccepted()
        ->json('runId');

    Queue::assertPushed(RunSupportAgent::class, fn (RunSupportAgent $job): bool => $job->threadId === $threadId
        && $job->runId === $runId
        && $job->message === 'Hi');
}

public function test_the_job_streams_the_turn_to_its_channel(): void
{
    $user = User::factory()->create();
    $this->fakeSupportAgent(new AssistantMessage('Hello from the worker'));

    RunSupportAgent::dispatchSync("user-{$user->id}-t2", 'run-1', 'Hi');

    $types = array_map(fn ($event): string => $event->type, $this->channel->getSent());
    $this->assertSame('RUN_STARTED', $types[0]);
    $this->channel->assertCompleted();
}

public function test_a_redelivery_after_a_failure_finishes_the_same_turn(): void
{
    $user = User::factory()->create();
    $order = Order::create(['user_id' => $user->id, 'status' => 'shipped', 'total' => 40]);
    $threadId = "user-{$user->id}-t3";
    // The tool call is answered, then the provider fails: the second inference has no response.
    $provider = $this->fakeSupportAgent(new ToolCallMessage(null, [ToolCall::make('lookup_order', 'call_1', ['order_id' => $order->id])]));
    $job = new RunSupportAgent($threadId, 'run-2', 'Where is my order?');

    try {
        $this->app->call([$job, 'handle']);
        $this->fail('The provider should have failed.');
    } catch (ProviderException) {
    }

    $provider->addResponses(new AssistantMessage('It shipped.'));
    $this->app->call([$job, 'handle']);

    $this->assertSame(
        ['user', 'assistant', 'user', 'assistant'],
        ChatMessage::where('thread_id', $threadId)->orderBy('id')->pluck('role')->all(),
    );
    // The answered tool call was not asked again: one inference before the failure, one after.
    $provider->assertCallCount(2);
}

public function test_a_refused_job_publishes_the_failure_itself(): void
{
    $user = User::factory()->create();
    $threadId = "user-{$user->id}-t4";
    $this->fakeSupportAgent(new ToolCallMessage(null, [ToolCall::make('refund_order', 'call_1', ['order_id' => 1])]));
    $this->actingAs($user)->postJson("/chat/threads/{$threadId}/messages", ['message' => 'Refund order 1']);

    RunSupportAgent::dispatchSync($threadId, 'run-3', 'Hello?');

    $this->assertSame(['RUN_ERROR'], array_map(fn ($event): string => $event->type, $this->channel->getSent()));
    $this->assertInstanceOf(RunInFlightException::class, $this->channel->getFailures()[0]->exception);
}
```

- `$this->app->call([$job, 'handle'])` runs one delivery with method injection and no queue job attached, so `release()` and `fail()` do nothing: use it to replay deliveries. `dispatchSync()` attaches a sync job, so `fail()` reaches `failed()`.
- `Queue::fake()` records dispatches without running the agent.
- `Http::fake()` and `Http::preventStrayRequests()` do not see Neuron's provider calls, which go through Neuron's own HTTP client: always fake the provider.
- `Event::fake([WorkflowEnd::class])` and `Event::assertDispatched()` work through the PSR-14 bridge; `Event::listen(ObservabilityEvent::class)` never fires.
- Leases and deadlines read `time()`: `travel()` and `Carbon::setTestNow()` do not move them.

Fakes and their assertions: **neuron-test**. Streamed output assertions: **neuron-streaming** ("Testing Streamed Output").
