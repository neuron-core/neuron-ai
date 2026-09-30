# Reverb: pushing a queued run to the browser

The browser subscribes to the thread's private channel, then posts the turn; the endpoint queues `RunSupportAgent` and answers 202, and the worker publishes every AG-UI event on the channel through Neuron's `PusherChannel`. This ran against a real Reverb server (`php artisan reverb:start`), a `queue:work` process and a `pusher-js` client consuming with `@neuron-core/streaming`.

Install Reverb as Laravel documents it; `PusherChannel` also needs `pusher/pusher-php-server` (^7.2.4). The `reverb` connection in `config/broadcasting.php` then gives Neuron a configured SDK client.

## The channel factory

```php
namespace App\Neuron\Channels;

use Illuminate\Support\Facades\Broadcast;
use NeuronAI\Workflow\Streaming\Channel\PusherChannel;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;

/**
 * The worker publishes the run on the thread's private channel; the browser subscribed before the turn was queued.
 */
class ReverbBroadcast implements ChannelFactory
{
    public function make(string $threadId, string $runId): StreamingChannelInterface
    {
        // batchSize 1 delivers every token as it arrives; the default of 10 holds them back in groups.
        return new PusherChannel(Broadcast::connection('reverb')->getPusher(), "private-agent.{$threadId}", batchSize: 1);
    }
}
```

Bind it in `NeuronServiceProvider`: `$this->app->bind(ChannelFactory::class, ReverbBroadcast::class);`. Publishing is synchronous from the worker and bypasses Laravel's broadcast queue. Channel names allow `[-a-zA-Z0-9_=@,.;]` only, which the `user-{id}-{uuid}` thread IDs satisfy.

## Authorization

```php
// routes/channels.php, registered by withRouting(channels: __DIR__.'/../routes/channels.php') in bootstrap/app.php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('agent.{threadId}', fn ($user, string $threadId): bool => str_starts_with($threadId, "user-{$user->id}-"));
```

Laravel strips the `private-` prefix before matching, so `private-agent.{threadId}` is authorized by the `agent.{threadId}` pattern: the owner gets a 200 from `/broadcasting/auth`, anyone else a 403.

## The endpoint

```php
// app/Http/Controllers/BackgroundChatController.php
use App\Http\Requests\ChatRequest;
use App\Jobs\RunSupportAgent;
use App\Neuron\Agents\SupportAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

public function dispatch(ChatRequest $request, SupportAgent $agent): JsonResponse
{
    $threadId = $request->threadId();
    $agent = $agent->for($threadId);

    if ($request->has('decisions')) {
        // Refused here, before queuing, when the run does not wait for these answers; the job stages them again.
        $agent->submitApprovalDecisions($request->input('decisions'));
    }
    // A new turn gets a new run; decisions continue the suspended one.
    $runId = $request->has('decisions') ? $agent->inspect()->runId : (string) Str::uuid();

    Cache::forget(SupportAgent::stopKey($threadId));
    RunSupportAgent::dispatch($threadId, $runId, $request->input('message'), $request->input('decisions', []));

    return response()->json(['runId' => $runId], 202);
}
```

Route: `Route::post('threads/{thread}/runs', [BackgroundChatController::class, 'dispatch'])` in the `auth` group. `ChatRequest` authorizes the thread and accepts either `message` or `decisions`.

## The browser

Pusher does not replay: subscribe, wait for `pusher:subscription_succeeded`, then post the turn, once. pusher-js fires that event again after every reconnect, so the handler unbinds itself; a handler left bound posted the same turn a second time when the Reverb server came back.

```js
import Pusher from "pusher-js";
import { subscribeToPusher } from "@neuron-core/streaming";

const pusher = new Pusher(REVERB_APP_KEY, {
  cluster: "mt1", wsHost: REVERB_HOST, wsPort: REVERB_PORT, forceTLS: false, enabledTransports: ["ws"],
  channelAuthorization: {
    // Laravel authorizes private channels at /broadcasting/auth with the session cookie.
    customHandler: async ({ socketId, channelName }, callback) => {
      const response = await fetch("/broadcasting/auth", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
        body: `socket_id=${socketId}&channel_name=${channelName}`,
      });
      response.ok ? callback(null, await response.json()) : callback(new Error(`auth ${response.status}`), null);
    },
  },
});

const channel = pusher.subscribe(`private-agent.${threadId}`);
channel.bind("pusher:subscription_succeeded", async function once() {
  channel.unbind("pusher:subscription_succeeded", once);
  subscribeToPusher(channel, {
    // AG-UI events in order, each segment closed by stream.completed, stream.interrupted or stream.failed
    onEvent: ({ type, data }) => render(type, data),
    onGap: () => reloadConversation(),
  });
  await fetch(`/chat/threads/${threadId}/runs`, {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({ message }),
  });
});
```

What the subscriber received in the run:

```
POST /runs 202 {"runId":"772d8d73-…"}
RUN_STARTED {"runId":"772d8d73-…","threadId":"user-1-ec5d29d6-…"}
TEXT_MESSAGE_START, TEXT_MESSAGE_CONTENT "Hello!", " You", " said:", …   (one event per token, about 30 ms apart)
TEXT_MESSAGE_END, RUN_FINISHED
stream.completed {"workflowId":"user-1-ec5d29d6-…"}
```

An approval arrives as `RUN_FINISHED` with the interrupt outcome, then `stream.interrupted`. The page posts `{"decisions": {"<call id>": "approve"}}` to the same endpoint; the continuation's segment arrives on the same channel under a new stream ID, which the consumer tracks on its own, and ends with `stream.completed`.

- The job's adapter is not seeded with browser messages on this path, so an interrupt's `MESSAGES_SNAPSHOT` carries only what this segment streamed (`{"messages":[]}` when the model went straight to the tool call). Render the conversation from your own state or from the reload endpoint, not from that snapshot.
- Events published before the subscription, or while the tab is closed, are gone: reconcile from the reload endpoint on `onGap`, on reconnect and on page load.
- Envelope, ordering, fragments and the consumer's options: **neuron-streaming** (`references/channels.md`).
