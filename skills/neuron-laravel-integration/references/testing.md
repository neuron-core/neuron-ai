# Feature tests for the Laravel integration

These tests ran green on SQLite `:memory:`, MySQL 8.4, MariaDB 11.7 and PostgreSQL 17 with `RefreshDatabase`. They drive the real routes, container, stores and jobs; only the provider and the job's channel are fakes. Each fence is one of the app's test files, trimmed to the tests worth copying.

Contents: the seam; a JSON turn (approval, another user's thread, a failed turn finished before the next one); a streamed AG-UI turn (approval, JSON errors for event-stream clients); reload and paging; jobs and the Redis relay (queuing, redelivery, a turn another job gave up on, a continuation redelivered after its tools ran, refused and failed jobs); Stop.

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

`setAiProvider()` beats the `provider()` hook, and the `for()` copy the controller makes shares it. Script a tool call with `new ToolCallMessage(null, [ToolCall::make(name: 'refund_order', callId: 'call_1', inputs: ['order_id' => 1])])`; an empty `FakeAIProvider` throws `ProviderException` on the next inference, which is how the tests below inject a provider failure.

## A JSON turn with an approval

```php
namespace Tests\Feature\Neuron;

use App\Models\ChatMessage;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ToolCall;
use Tests\TestCase;

class ChatJsonTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_refund_waits_for_approval_then_runs_once(): void
    {
        $user = User::factory()->create();
        $order = Order::create(['user_id' => $user->id, 'status' => 'shipped', 'total' => 40]);
        $provider = $this->fakeSupportAgent(
            new ToolCallMessage(null, [ToolCall::make(name: 'refund_order', callId: 'call_1', inputs: ['order_id' => $order->id])]),
            new AssistantMessage('Your order was refunded.'),
        );
        $threadId = "user-{$user->id}-t1";

        $this->actingAs($user)->postJson("/chat/threads/{$threadId}/messages", ['message' => 'Refund my order'])
            ->assertOk()
            ->assertJsonPath('status', 'awaiting_approval')
            ->assertJsonPath('approvals.0.id', 'call_1')
            ->assertJsonPath('approvals.0.name', 'refund_order')
            ->assertJsonPath('approvals.0.reason', 'Refunds move money back to the customer.');
        $this->assertSame('shipped', $order->fresh()->status);

        // A new message while the approval is pending is refused before anything runs.
        $this->postJson("/chat/threads/{$threadId}/messages", ['message' => 'Hello?'])
            ->assertStatus(409)
            ->assertJsonPath('status', 'suspended')
            ->assertHeaderMissing('Retry-After');

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

    public function test_a_turn_that_failed_after_its_tool_step_is_finished_before_the_next_one(): void
    {
        $user = User::factory()->create();
        $order = Order::create(['user_id' => $user->id, 'status' => 'shipped', 'total' => 40]);
        $threadId = "user-{$user->id}-t5";
        $provider = $this->fakeSupportAgent(new ToolCallMessage(null, [ToolCall::make(name: 'lookup_order', callId: 'call_1', inputs: ['order_id' => $order->id])]));

        // The tool runs, then the provider fails: the question and the tool step are stored, the answer is not.
        $this->actingAs($user)->postJson("/chat/threads/{$threadId}/messages", ['message' => 'Where is my order?'])->assertServerError();

        $provider->addResponses(new AssistantMessage('It shipped.'), new AssistantMessage('You are welcome.'));
        $this->postJson("/chat/threads/{$threadId}/messages", ['message' => 'Thanks'])
            ->assertOk()
            ->assertJsonPath('message.content.0.content', 'You are welcome.');

        $this->assertSame(['Where is my order?', 'It shipped.', 'Thanks', 'You are welcome.'], $this->texts($threadId));
    }

    /**
     * @return list<string>
     */
    protected function texts(string $threadId): array
    {
        return ChatMessage::where('thread_id', $threadId)->orderBy('id')->get()
            ->map(fn (ChatMessage $message): string => collect($message->content)->where('type', 'text')->pluck('content')->implode(''))
            ->filter()->values()->all();
    }
}
```

A serialized message is `{"__id": …, "role": "assistant", "content": [{"type": "text", "content": "…"}]}`: assert on `message.content.0.content`. The last test fails with a 500 (`ChatHistoryException`) when the controller does not call `recoverFailedTurn()`.

## A streamed AG-UI turn

A plain turn asserts `->assertOk()->assertStreamed()->assertHeader('Content-Type', 'text/event-stream; charset=utf-8')` and frames from `RUN_STARTED` to `RUN_FINISHED`.

```php
namespace Tests\Feature\Neuron;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ToolCall;
use Tests\TestCase;

class AguiStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_approval_suspends_refuses_a_new_turn_and_continues(): void
    {
        $user = User::factory()->create();
        $order = Order::create(['user_id' => $user->id, 'status' => 'shipped', 'total' => 40]);
        $provider = $this->fakeSupportAgent(
            new ToolCallMessage(null, [ToolCall::make(name: 'refund_order', callId: 'call_1', inputs: ['order_id' => $order->id])]),
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

    public function test_an_event_stream_client_gets_json_errors(): void
    {
        [$owner, $intruder] = User::factory()->count(2)->create();
        $this->fakeSupportAgent(new AssistantMessage('never'));
        $threadId = "user-{$owner->id}-t6";
        $sse = ['Accept' => 'text/event-stream'];

        $this->actingAs($owner)->json('POST', '/chat/agui', ['threadId' => $threadId, 'messages' => []], $sse)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('runId');
        $this->actingAs($intruder)->json('POST', '/chat/agui', $this->input($threadId, 'Hi'), $sse)
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/json');
        $this->actingAs($intruder)->json('POST', '/chat/agui', [...$this->input($threadId, 'Hi'), 'threadId' => ['x']], $sse)
            ->assertForbidden();
    }

    public function test_a_guest_event_stream_client_gets_a_json_401(): void
    {
        $this->json('POST', '/chat/agui', $this->input('user-1-t7', 'Hi'), ['Accept' => 'text/event-stream'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
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

    /**
     * @return list<array<string, mixed>>
     */
    protected function frames(TestResponse $response): array
    {
        preg_match_all('/^data: (.+)$/m', $response->streamedContent(), $matches);

        return array_map(fn (string $json): array => json_decode($json, true), $matches[1]);
    }
}
```

`streamedContent()` runs the stream callback inside an output buffer; `getContent()` is `false` on a streamed response, so `assertSee()` does not apply. The same file also pins the input middleware: an AG-UI seed message with `content: ""` and one starting with spaces come back unchanged in `MESSAGES_SNAPSHOT` only while the agent routes are excluded from `TrimStrings` and `ConvertEmptyStringsToNull`. The error tests send `Accept: text/event-stream`, as the AG-UI client does: `postJson()` would ask for JSON and hide a 302.

## Reload

```php
namespace Tests\Feature\Neuron;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ToolCall;
use Tests\TestCase;

class ReloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reload_restores_messages_and_the_pending_approval(): void
    {
        $user = User::factory()->create();
        $order = Order::create(['user_id' => $user->id, 'status' => 'shipped', 'total' => 40]);
        $this->fakeSupportAgent(
            new AssistantMessage('Hi, how can I help?'),
            new ToolCallMessage(null, [ToolCall::make(name: 'refund_order', callId: 'call_1', inputs: ['order_id' => $order->id])]),
            new AssistantMessage('Refunded.'),
        );
        $threadId = "user-{$user->id}-t1";
        $this->actingAs($user)->postJson("/chat/threads/{$threadId}/messages", ['message' => 'Hello']);
        $this->postJson("/chat/threads/{$threadId}/messages", ['message' => 'Refund my order']);

        $page = $this->getJson("/chat/threads/{$threadId}")->assertOk();

        $this->assertSame(['user', 'assistant', 'user'], array_column($page->json('messages'), 'role'));
        $page->assertJsonPath('interrupts.0.id', 'call_1')->assertJsonPath('status', 'suspended');

        // The whole thread fits one page: nothing is older.
        $this->getJson("/chat/threads/{$threadId}?before={$page->json('before')}")
            ->assertJsonCount(0, 'messages')
            ->assertJsonPath('interrupts', [])
            ->assertJsonPath('status', null);

        $this->postJson("/chat/threads/{$threadId}/messages", ['decisions' => ['call_1' => 'approve']]);

        $done = $this->getJson("/chat/threads/{$threadId}")
            ->assertJsonPath('interrupts', [])
            ->assertJsonPath('status', null);
        $this->assertSame('Refunded.', last($done->json('messages'))['content']);
    }

    public function test_older_pages_follow_the_stored_cursor(): void
    {
        $user = User::factory()->create();
        $order = Order::create(['user_id' => $user->id, 'status' => 'shipped', 'total' => 40]);
        $threadId = "user-{$user->id}-t2";
        $responses = [];
        foreach (range(1, 13) as $turn) {
            $responses[] = new ToolCallMessage(null, [ToolCall::make(name: 'lookup_order', callId: "call_{$turn}", inputs: ['order_id' => $order->id])]);
            $responses[] = new AssistantMessage("Answer {$turn}");
        }
        $this->fakeSupportAgent(...$responses);
        foreach (range(1, 13) as $turn) {
            $this->actingAs($user)->postJson("/chat/threads/{$threadId}/messages", ['message' => "Question {$turn}"])->assertOk();
        }

        // 52 stored messages: the latest 50 start at the first turn's tool result, whose AG-UI ID no store knows.
        $page = $this->getJson("/chat/threads/{$threadId}")->assertOk();
        $this->assertSame('result_call_1', $page->json('messages.0.id'));
        $this->getJson("/chat/threads/{$threadId}?before=result_call_1")->assertJsonCount(0, 'messages');

        $older = $this->getJson("/chat/threads/{$threadId}?before={$page->json('before')}")->assertOk();
        $this->assertSame(['user', 'assistant'], array_column($older->json('messages'), 'role'));
        $older->assertJsonPath('messages.0.content', 'Question 1');
    }

    public function test_a_malformed_cursor_is_a_validation_error(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson("/chat/threads/user-{$user->id}-t3?before[]=x")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('before');
    }
}
```

## Jobs and the Redis relay

The job publishes through the container's `ChannelFactory`; bind one that returns a `FakeChannel`. The relay endpoint needs a real queue worker: test it with `Queue::fake()` and never read its `streamedContent()`, because with the sync queue the job runs before the relay subscribes and the relay then waits for its timeout.

```php
namespace Tests\Feature\Neuron;

use App\Jobs\RunSupportAgent;
use App\Models\ChatMessage;
use App\Models\Order;
use App\Models\User;
use App\Neuron\Channels\ChannelFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\WorkflowEngine;
use Tests\TestCase;

class BackgroundRunTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_the_relay_endpoint_queues_the_turn_with_its_ag_ui_input(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $threadId = "user-{$user->id}-t10";
        $input = [
            'threadId' => $threadId,
            'runId' => 'client-run-1',
            'messages' => [['id' => 'u1', 'role' => 'user', 'content' => 'Hi']],
            'tools' => [],
            'state' => [],
        ];

        // Never streamedContent() here: no worker publishes, and the relay would wait for its timeout.
        $this->actingAs($user)->postJson('/chat/agui/background', $input)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');

        Queue::assertPushed(RunSupportAgent::class, fn (RunSupportAgent $job): bool => $job->threadId === $threadId
            && $job->message === 'Hi'
            && $job->input === $input);
    }

    public function test_the_job_publishes_the_ag_ui_run_the_relay_reads(): void
    {
        $user = User::factory()->create();
        $this->fakeSupportAgent(new AssistantMessage('Hello from the worker'));
        $input = [
            'threadId' => "user-{$user->id}-t11",
            'runId' => 'client-run-1',
            'messages' => [['id' => 'u1', 'role' => 'user', 'content' => 'Hi']],
        ];

        RunSupportAgent::dispatchSync($input['threadId'], 'run-11', 'Hi', input: $input);

        // The frames carry the browser's run ID: the relay forwards them as they are.
        $this->assertSame(['type' => 'RUN_STARTED', 'runId' => 'client-run-1', 'threadId' => $input['threadId']], $this->channel->getSent()[0]->jsonSerialize());
        $this->channel->assertCompleted();
    }

    public function test_a_redelivery_after_a_failure_finishes_the_same_turn(): void
    {
        $user = User::factory()->create();
        $order = Order::create(['user_id' => $user->id, 'status' => 'shipped', 'total' => 40]);
        $threadId = "user-{$user->id}-t3";
        // The tool call is answered, then the provider fails: the second inference has no response.
        $provider = $this->fakeSupportAgent(new ToolCallMessage(null, [ToolCall::make(name: 'lookup_order', callId: 'call_1', inputs: ['order_id' => $order->id])]));
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
        $this->assertSame(1, ChatMessage::where('thread_id', $threadId)->where('content', 'like', '%Where is my order?%')->count());
        // The answered tool call was not asked again: one inference before the failure, one after.
        $provider->assertCallCount(2);
    }

    public function test_a_refused_job_publishes_the_failure_itself(): void
    {
        $user = User::factory()->create();
        $threadId = "user-{$user->id}-t4";
        $this->fakeSupportAgent(new ToolCallMessage(null, [ToolCall::make(name: 'refund_order', callId: 'call_1', inputs: ['order_id' => 1])]));
        $this->actingAs($user)->postJson("/chat/threads/{$threadId}/messages", ['message' => 'Refund order 1'])
            ->assertJsonPath('status', 'awaiting_approval');

        RunSupportAgent::dispatchSync($threadId, 'run-3', 'Hello?');

        $this->assertSame(['RUN_ERROR'], array_map(fn ($event): string => $event->type, $this->channel->getSent()));
        $this->assertInstanceOf(RunInFlightException::class, $this->channel->getFailures()[0]->exception);
    }

    public function test_a_turn_whose_job_gave_up_is_finished_before_the_next_one(): void
    {
        $user = User::factory()->create();
        $order = Order::create(['user_id' => $user->id, 'status' => 'shipped', 'total' => 40]);
        $threadId = "user-{$user->id}-t5";
        // The first job's turn fails after its tool step, and that job gives up.
        $provider = $this->fakeSupportAgent(new ToolCallMessage(null, [ToolCall::make(name: 'lookup_order', callId: 'call_1', inputs: ['order_id' => $order->id])]));
        try {
            $this->app->call([new RunSupportAgent($threadId, 'run-a', 'Where is my order?'), 'handle']);
        } catch (ProviderException) {
        }

        $provider->addResponses(new AssistantMessage('It shipped.'), new AssistantMessage('You are welcome.'));
        $next = new RunSupportAgent($threadId, 'run-b', 'Thanks');
        $this->app->call([$next, 'handle']); // finishes run-a, then asks to be delivered again
        $this->app->call([$next, 'handle']);

        $this->assertSame(
            ['Where is my order?', 'It shipped.', 'Thanks', 'You are welcome.'],
            $this->texts($threadId),
        );
        // The finished turn stayed off this job's channel: the browser sees only its own answer.
        $deltas = implode('', array_map(fn ($event): string => $event->data['delta'] ?? '', $this->channel->getSent()));
        $this->assertStringNotContainsString('It shipped.', $deltas);
        $this->assertStringContainsString('You are welcome.', $deltas);
    }

    public function test_a_continuation_redelivered_after_its_tools_ran_finishes_the_run(): void
    {
        $user = User::factory()->create();
        $order = Order::create(['user_id' => $user->id, 'status' => 'shipped', 'total' => 40]);
        $threadId = "user-{$user->id}-t8";
        $provider = $this->fakeSupportAgent(new ToolCallMessage(null, [ToolCall::make(name: 'refund_order', callId: 'call_1', inputs: ['order_id' => $order->id])]));
        RunSupportAgent::dispatchSync($threadId, 'run-8', 'Refund my order');

        // The refund runs, then the provider fails: the approval is settled, the run failed.
        $job = new RunSupportAgent($threadId, 'run-8', null, ['call_1' => 'approve']);
        try {
            $this->app->call([$job, 'handle']);
            $this->fail('The provider should have failed.');
        } catch (ProviderException) {
        }
        $this->assertSame('refunded', $order->fresh()->status);

        $provider->addResponses(new AssistantMessage('Refunded.'));
        $this->app->call([$job, 'handle']);

        $this->assertSame(['Refund my order', 'Refunded.'], $this->texts($threadId));
        // One tool call and one result: the refund was not run again.
        $this->assertSame(['user', 'assistant', 'user', 'assistant'], ChatMessage::where('thread_id', $threadId)->orderBy('id')->pluck('role')->all());
        $this->assertNull($this->app->make(WorkflowEngine::class)->inspect($threadId));
    }

    public function test_a_run_that_failed_in_its_segment_publishes_its_error_once(): void
    {
        $user = User::factory()->create();
        $this->fakeSupportAgent(); // the first inference fails

        try {
            RunSupportAgent::dispatchSync("user-{$user->id}-t9", 'run-9', 'Hi');
        } catch (ProviderException) {
        }

        $this->assertSame(['RUN_STARTED', 'RUN_ERROR'], array_map(fn ($event): string => $event->type, $this->channel->getSent()));
        $this->assertCount(1, $this->channel->getFailures());
    }

    /**
     * @return list<string>
     */
    protected function texts(string $threadId): array
    {
        return ChatMessage::where('thread_id', $threadId)->whereIn('role', ['user', 'assistant'])->orderBy('id')->get()
            ->map(fn (ChatMessage $message): string => collect($message->content)->where('type', 'text')->pluck('content')->implode(''))
            ->filter()->values()->all();
    }
}
```

## Stop

`FakeAIProvider` replaces the provider and, with it, the Stop client: a faked turn never stops. The flag itself is plain cache state (the stop endpoint raises it, a new turn clears it). A real stop needs the provider's own stream: override the agent's `transport()` in a test subclass that replays an Anthropic answer, one SSE event per line read, so the flag is checked between words.

```php
namespace Tests\Support;

use App\Neuron\Agents\SupportAgent;
use Closure;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/**
 * SupportAgent with its real provider and Stop client, over a transport that answers with $words as an Anthropic answer:
 * streamed one word per line read, or at once for a buffered chat(). $afterWord runs after each streamed word: a test presses Stop there.
 */
class ScriptedStreamSupportAgent extends SupportAgent
{
    /** @var list<string> */
    public static array $words = [];

    public static ?Closure $afterWord = null;

    protected function transport(): HttpClientInterface
    {
        return new class(self::$words, self::$afterWord) implements HttpClientInterface, StreamInterface
        {
            protected array $lines = [];

            protected int $read = 0;

            public function __construct(protected array $words, protected ?Closure $afterWord) {}

            public function request(HttpRequest $request): HttpResponse
            {
                return new HttpResponse(200, json_encode([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => implode('', $this->words)]],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 1, 'output_tokens' => count($this->words)],
                ]));
            }

            public function stream(HttpRequest $request): StreamInterface
            {
                $events = [
                    ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]],
                    ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                    ...array_map(fn (string $word): array => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $word]], $this->words),
                    ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => count($this->words)]],
                ];
                // One SSE event per line read: the Stop flag is checked before each one.
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
                if (str_contains($line, 'text_delta') && $this->afterWord !== null) {
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

The tests bind it with `$this->app->bind(SupportAgent::class, ScriptedStreamSupportAgent::class)`. It also answers a buffered `chat()`, so a JSON turn can run through it.

```php
namespace Tests\Feature\Neuron;

use App\Jobs\RunSupportAgent;
use App\Models\ChatMessage;
use App\Models\User;
use App\Neuron\Agents\SupportAgent;
use App\Neuron\Channels\ChannelFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\WorkflowEngine;
use Tests\Support\ScriptedStreamSupportAgent;
use Tests\TestCase;

class StopTest extends TestCase
{
    use RefreshDatabase;

    public function test_stop_raises_the_flag_and_a_new_turn_clears_it(): void
    {
        $user = User::factory()->create();
        $threadId = "user-{$user->id}-t1";
        $this->fakeSupportAgent(new AssistantMessage('Hello'));

        $this->actingAs($user)->postJson("/chat/threads/{$threadId}/stop")->assertNoContent();
        $this->assertTrue(Cache::get(SupportAgent::stopKey($threadId)));

        $this->postJson('/chat/agui', [
            'threadId' => $threadId,
            'runId' => 'r1',
            'messages' => [['id' => 'u1', 'role' => 'user', 'content' => 'Hi']],
        ])->assertOk();

        $this->assertNull(Cache::get(SupportAgent::stopKey($threadId)));
    }

    public function test_another_users_thread_cannot_be_stopped(): void
    {
        [$owner, $intruder] = User::factory()->count(2)->create();

        $this->actingAs($intruder)->postJson("/chat/threads/user-{$owner->id}-t1/stop")->assertForbidden();
    }

    public function test_the_provider_comes_from_config_behind_a_stoppable_client(): void
    {
        config(['services.anthropic.key' => 'sk-test', 'services.anthropic.model' => 'claude-test']);

        $provider = $this->app->make(SupportAgent::class)->for('user-1-t2')->getProvider();

        $this->assertInstanceOf(Anthropic::class, $provider);
        $this->assertSame('claude-test', $provider->getModel());
        $this->assertInstanceOf(StoppableHttpClient::class, (fn () => $this->httpClient)->call($provider));
    }

    public function test_stop_ends_the_streamed_answer_and_keeps_its_text(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        $user = User::factory()->create();
        $threadId = "user-{$user->id}-t3";
        ScriptedStreamSupportAgent::$words = ['Once', ' upon', ' a', ' time'];
        // The user presses Stop while the second word streams.
        ScriptedStreamSupportAgent::$afterWord = fn (int $read) => $read === 2 ? $this->postJson("/chat/threads/{$threadId}/stop")->assertNoContent() : null;
        $this->app->bind(SupportAgent::class, ScriptedStreamSupportAgent::class);

        $frames = $this->frames($this->actingAs($user)->postJson('/chat/agui', $this->input($threadId, 'Tell me a story')));

        $this->assertSame(['TEXT_MESSAGE_END', 'RUN_FINISHED'], array_slice(array_column($frames, 'type'), -2));
        $answer = ChatMessage::where('thread_id', $threadId)->where('role', 'assistant')->sole();
        $this->assertSame('Once upon', $answer->content[0]['content']);
        $this->assertSame('stopped', $answer->meta['__meta']['stop_reason']);
        $this->assertTrue(Cache::get(SupportAgent::stopKey($threadId)), 'The flag stays raised until the next turn.');
    }

    public function test_a_queued_turn_stopped_before_its_first_word_stays_stopped_on_retry(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        $user = User::factory()->create();
        $threadId = "user-{$user->id}-t4";
        ScriptedStreamSupportAgent::$words = ['Once', ' upon', ' a', ' time'];
        ScriptedStreamSupportAgent::$afterWord = null;
        $this->app->bind(SupportAgent::class, ScriptedStreamSupportAgent::class);
        $this->app->instance(ChannelFactory::class, new class implements ChannelFactory
        {
            public function make(string $threadId, string $runId): StreamingChannelInterface
            {
                return new FakeChannel;
            }
        });
        $this->actingAs($user)->postJson("/chat/threads/{$threadId}/stop")->assertNoContent();

        $job = new RunSupportAgent($threadId, 'run-4', 'Tell me a story');
        foreach ([1, 2] as $delivery) {
            try {
                $this->app->call([$job, 'handle']);
                $this->fail("Delivery {$delivery} should have stopped.");
            } catch (ProviderException $e) {
                $this->assertSame('The stream was stopped before the answer started.', $e->getMessage());
            }
        }

        $this->assertSame('failed', $this->app->make(WorkflowEngine::class)->inspect($threadId)->status->value);
        $this->assertSame(0, ChatMessage::where('thread_id', $threadId)->count());

        // The next turn clears the flag and finishes the stopped turn before its own.
        $frames = $this->frames($this->postJson('/chat/agui', $this->input($threadId, 'Hi')));
        $this->assertSame('RUN_FINISHED', end($frames)['type']);
        $this->assertSame(['user', 'assistant', 'user', 'assistant'], ChatMessage::where('thread_id', $threadId)->orderBy('id')->pluck('role')->all());
    }

    public function test_a_json_message_after_a_stopped_turn_clears_the_flag_first(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        $user = User::factory()->create();
        $threadId = "user-{$user->id}-t5";
        ScriptedStreamSupportAgent::$words = ['Once', ' upon', ' a', ' time'];
        ScriptedStreamSupportAgent::$afterWord = null;
        $this->app->bind(SupportAgent::class, ScriptedStreamSupportAgent::class);
        $this->app->instance(ChannelFactory::class, new class implements ChannelFactory
        {
            public function make(string $threadId, string $runId): StreamingChannelInterface
            {
                return new FakeChannel;
            }
        });
        $this->actingAs($user)->postJson("/chat/threads/{$threadId}/stop")->assertNoContent();
        try {
            $this->app->call([new RunSupportAgent($threadId, 'run-5', 'Tell me a story'), 'handle']);
            $this->fail('The queued turn should have stopped.');
        } catch (ProviderException) {
        }

        $this->postJson("/chat/threads/{$threadId}/messages", ['message' => 'Hi'])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertNull(Cache::get(SupportAgent::stopKey($threadId)));
        $this->assertSame(['user', 'assistant', 'user', 'assistant'], ChatMessage::where('thread_id', $threadId)->orderBy('id')->pluck('role')->all());
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

    /**
     * @return list<array<string, mixed>>
     */
    protected function frames(TestResponse $response): array
    {
        preg_match_all('/^data: (.+)$/m', $response->streamedContent(), $matches);

        return array_map(fn (string $json): array => json_decode($json, true), $matches[1]);
    }
}
```

- `$this->app->call([$job, 'handle'])` runs one delivery with method injection and no queue job attached, so `release()` and `fail()` do nothing: use it to replay deliveries. `dispatchSync()` attaches a sync job, so `fail()` reaches `failed()`.
- `Queue::fake()` records dispatches without running the agent.
- `Http::fake()` and `Http::preventStrayRequests()` do not see Neuron's provider calls, which go through Neuron's own HTTP client: always fake the provider.
- `Event::fake([WorkflowEnd::class])` and `Event::assertDispatched()` work through the PSR-14 bridge, before or after `fakeSupportAgent()`, because the bridge resolves the dispatcher for every event; `Event::listen(ObservabilityEvent::class)` never fires.
- Leases and deadlines read `time()`: `travel()` and `Carbon::setTestNow()` do not move them.

Fakes and their assertions: **neuron-test**. Streamed output assertions: **neuron-streaming** ("Testing Streamed Output").
