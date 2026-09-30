# Redis relay: a queued run behind one SSE response

Use this when the client needs a single streamed HTTP response (the AG-UI `HttpAgent` or CopilotKit in the browser, Vercel `useChat`) but the turn must run in a queue worker. The controller dispatches `RunSupportAgent` with the AG-UI input, subscribes to a Redis Pub/Sub channel named after the run, and writes each protocol event as an SSE frame until the run's stream ends. The worker publishes through Neuron's `RedisChannel` and the endpoint reads through Neuron's `RedisChannelReader`. Everything here ran against Redis 7.4 with `php artisan serve` and `php artisan queue:work`.

## The relay

It is also the job's `ChannelFactory`: bind `ChannelFactory::class` to `RedisRelay::class` in `NeuronServiceProvider`.

```php
namespace App\Neuron\Channels;

use Illuminate\Support\Facades\Redis as RedisManager;
use NeuronAI\Workflow\Streaming\Channel\RedisChannel;
use NeuronAI\Workflow\Streaming\Channel\RedisChannelReader;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use Redis;

class RedisRelay implements ChannelFactory
{
    public function make(string $threadId, string $runId): StreamingChannelInterface
    {
        // Pub/Sub keeps nothing: the first publish waits for the HTTP request to listen, 5 seconds at most.
        return new RedisChannel($this->client(), $this->name($runId), awaitListener: 5);
    }

    /**
     * Echo the run's protocol events as SSE frames until its stream ends.
     */
    public function relay(string $runId): void
    {
        (new RedisChannelReader($this->client(), $this->name($runId)))->listen(function (ProtocolEvent $event): void {
            echo SSEEncoder::frame($event);
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        });
    }

    protected function client(): Redis
    {
        return RedisManager::connection()->client();
    }

    protected function name(string $runId): string
    {
        return "agent-run.{$runId}";
    }
}
```

- The channel is named after the server-minted run ID, which the browser never chooses.
- Both halves use the app's Redis connection, so they see the same key prefix. It must be phpredis (`REDIS_CLIENT=phpredis`). The reader sets its own read timeout and closes the connection when it returns; phpredis reconnects on the next command.
- `listen()` returns at the segment's terminal event. It throws `ChannelReadException` when nothing arrives for 300 seconds (a worker that died without publishing; pass `timeout:` above the longest silent step, a tool batch plus an inference), or when the segment is incomplete: it started before the reader subscribed, or a redelivery started another one before it ended. The endpoint answers every failure with a `RUN_ERROR` frame.

## The endpoint

```php
// app/Http/Controllers/BackgroundChatController.php
use App\Http\Requests\RunAgentRequest;
use App\Jobs\RunSupportAgent;
use App\Neuron\Agents\SupportAgent;
use App\Neuron\Channels\RedisRelay;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

public function stream(RunAgentRequest $request, SupportAgent $agent, RedisRelay $relay): StreamedResponse
{
    $threadId = $request->threadId();

    // Refused here, before any header: browser tools that shadow backend tools, answers the run does not wait for.
    $agent = $agent->for($threadId)->addFrontendTools($request->frontendTools());
    if ($request->isContinuation()) {
        $agent->submitInputs($request->all(), new AGUIInputTranslator);
    }
    // A new turn gets a new run; a continuation names the suspended one.
    $runId = $request->isContinuation() ? $agent->inspect()->runId : (string) Str::uuid();

    Cache::forget(SupportAgent::stopKey($threadId));
    RunSupportAgent::dispatch($threadId, $runId, $request->isContinuation() ? null : $request->prompt(), input: $request->all());

    $adapter = new AGUIAdapter(threadId: $threadId, runId: $request->input('runId'));

    return response()->stream(function () use ($relay, $runId, $adapter): void {
        try {
            $relay->relay($runId);
        } catch (Throwable $e) {
            report($e);
            foreach ($adapter->error($e) as $event) {
                echo SSEEncoder::frame($event);
            }
        }
    }, 200, $adapter->getHeaders());
}
```

Route: `Route::post('agui/background', [BackgroundChatController::class, 'stream'])` in the `auth` group, under the `chat/*` path the input middlewares skip.

- It needs a real queue worker, in local development too. On the `sync` driver the job runs inside this request, before the response exists, and publishes to nobody: the browser receives nothing until the reader's timeout.
- A continuation carries the suspended run's ID, so the job recognises its own run when a redelivery finds it failed after the approved tools ran (see "Background Runs" in the skill).
- The callback is not a generator: phpredis delivers messages to a callback, so the relay echoes and flushes itself, with the `ob_get_level()` guard.
- `submitInputs()` only stages: calling it here turns a stale or malformed continuation into a 400 before any header; the job stages the same input again from the run as it is at job time.
- The job receives the whole AG-UI input so its adapter is seeded with the client's `runId`, messages and state: an interrupt's `MESSAGES_SNAPSHOT` then carries the client's whole conversation.

## What the relay shows the browser

| Situation | The response |
|---|---|
| A turn | The AG-UI frames as the worker streams them, ending after `RUN_FINISHED` |
| The run suspends for an approval | `RUN_FINISHED` with the interrupt outcome, then the response ends; the approval posts `resume` to the same endpoint |
| A new message while an approval is pending | A 200 carrying one `RUN_ERROR`: the worker is refused, the job fails at once and `failed()` publishes the error |
| The LLM fails on the first delivery of a turn or a continuation | `RUN_ERROR`, then the response ends. The retry recovers the same run into history 5 seconds later (its channel waits for a listener that already left); a reload shows the answer once |
| The worker is killed mid-answer | Frames stop. If the reader is still listening when the redelivery recovers the run, the recovered segment ends the response with `RUN_ERROR`: a second `RUN_STARTED` would restart the client's run. A reload shows the answer once the run completes |
| No worker picks the job up | `RUN_ERROR` after the reader's timeout |

Live output is never replayed: whenever the frames and the history might disagree, reload the conversation (see "Reloading a Conversation" in the skill).
