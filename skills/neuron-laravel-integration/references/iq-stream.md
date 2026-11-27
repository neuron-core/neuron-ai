# IQ: pushing a queued run to the browser

The page posts the turn. The endpoint makes a ticket for a new IQ stream, queues `RunSupportAgent` with the stream's ID and answers 202 with the ticket; the worker publishes every AG-UI event through Neuron's `IQStreamChannel`, and the page reads them from the ticket's URL with `subscribeToIQ` from `@neuron-core/streaming`. Unlike Reverb and Mercure, the page subscribes after it posts: IQ keeps a stream's frames while it runs and gives a late subscriber all of them. The ticket is the page's authorization to read that stream, so there is nothing to declare in `routes/channels.php`.

## Install

```bash
composer require neuron-core/iq-stream-php
```

The keys go in `config/services.php` with the others:

```php
'iq_stream' => [
    'url' => env('IQ_STREAM_URL'),                // how the workers reach the server
    'public_url' => env('IQ_STREAM_PUBLIC_URL'),  // how browsers reach it, when that is another address
    'key' => env('IQ_STREAM_APP_KEY'),
    'secret' => env('IQ_STREAM_APP_SECRET'),
],
```

One client for the application, in `NeuronServiceProvider::register()`:

```php
use App\Neuron\Channels\ChannelFactory;
use App\Neuron\Channels\IQStreamDelivery;
use IQStream\Credentials;
use IQStream\IQClient;

$this->app->singleton(IQClient::class, fn (): IQClient => new IQClient(
    config('services.iq_stream.url'),
    new Credentials(config('services.iq_stream.key'), config('services.iq_stream.secret')),
    config('services.iq_stream.public_url'),
));
$this->app->bind(ChannelFactory::class, IQStreamDelivery::class);
```

- **Not `IQClient::fromEnvironment()`.** It reads the process environment, which holds none of the `.env` values once `config:cache` has run.
- The client holds the app's secret: it signs the browser's ticket and a short-lived token for every request the worker opens. Neither the endpoint nor the job builds a token.

## The stream travels with the job

A stream carries one segment: once a segment closes it, IQ refuses any other with `stream_closed`. So every dispatch gets a stream of its own, a continuation after an approval included, and the run ID cannot be the stream's ID, since a continuation and a redelivered job share it. The job carries the ticket's stream, as one more constructor argument, and hands it to its channel factory, as a third argument of `make()`:

```php
// app/Jobs/RunSupportAgent.php: one more argument, last
public function __construct(
    public string $threadId,
    public string $runId,
    public ?string $message,
    public array $decisions = [],
    public array $input = [],
    public ?string $stream = null, // the IQ stream of the endpoint's ticket
) {}

// handle()
->setChannel(fn (): StreamingChannelInterface => $channels->make($this->threadId, $this->runId, $this->stream))

// failed()
$channel = app(ChannelFactory::class)->make($this->threadId, $this->runId, $this->stream);
```

```php
namespace App\Neuron\Channels;

use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;

/** Where a queued run publishes its stream. The job asks for a fresh channel for every segment. */
interface ChannelFactory
{
    /** @param string|null $stream the IQ stream of the endpoint's ticket */
    public function make(string $threadId, string $runId, ?string $stream = null): StreamingChannelInterface;
}
```

```php
namespace App\Neuron\Channels;

use IQStream\IQClient;
use LogicException;
use NeuronAI\Workflow\Streaming\Channel\IQStreamChannel;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;

/**
 * The worker publishes each segment on the IQ stream of the ticket its endpoint answered with.
 */
class IQStreamDelivery implements ChannelFactory
{
    public function __construct(protected IQClient $iq) {}

    public function make(string $threadId, string $runId, ?string $stream = null): StreamingChannelInterface
    {
        return new IQStreamChannel($this->iq, $stream ?? throw new LogicException('An IQ delivery needs the stream of the endpoint\'s ticket.'));
    }
}
```

The test doubles of [testing.md](testing.md) that implement `ChannelFactory` take the same third parameter: `make(string $threadId, string $runId, ?string $stream = null)`.

## The endpoint

The endpoint of [reverb.md](reverb.md), with the ticket:

```php
// app/Http/Controllers/BackgroundChatController.php
use App\Http\Requests\ChatRequest;
use App\Jobs\RunSupportAgent;
use App\Neuron\Agents\SupportAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use IQStream\IQClient;

public function dispatch(ChatRequest $request, SupportAgent $agent, IQClient $iq): JsonResponse
{
    $threadId = $request->threadId();
    $agent = $agent->for($threadId);

    if ($request->has('decisions')) {
        // Refused here, before queuing, when the run does not wait for these answers; the job stages them again.
        $agent->submitApprovalDecisions($request->input('decisions'));
    }
    // A new turn gets a new run; decisions continue the suspended one.
    $runId = $request->has('decisions') ? $agent->inspect()->runId : (string) Str::uuid();

    // A new stream for every dispatch, and the ticket the page reads it with.
    $ticket = $iq->ticket();

    Cache::forget(SupportAgent::stopKey($threadId));
    RunSupportAgent::dispatch($threadId, $runId, $request->input('message'), $request->input('decisions', []), stream: $ticket->streamId->value);

    return response()->json(['runId' => $runId, 'ticket' => $ticket], 202);
}
```

- **The ticket is the authorization.** It lets its holder read one stream, and only this endpoint hands it out: `ChatRequest` has authorized the thread by then.
- **Only the stream's ID goes in the job**, never the client or the ticket: a job's arguments wait in the queue as they are, and the client holds the secret.
- **A queue that keeps jobs waiting.** The page subscribes at once, and IQ waits 30 seconds by default for the stream's worker, after which the stream fails with `publisher_timeout`. Where a job can wait longer, reserve the stream before dispatching, `$iq->reserve($ticket->streamId)`: IQ then waits as long as it waits for an idle stream, two minutes by default. It throws when IQ cannot be reached, so decide whether that should refuse the turn.

## The browser

```js
import { subscribeToIQ } from "@neuron-core/streaming";

const response = await fetch(`/chat/threads/${threadId}/runs`, {
  method: "POST",
  headers: { "Content-Type": "application/json", Accept: "application/json" },
  body: JSON.stringify({ message }),
});
const { ticket } = await response.json();

const subscription = subscribeToIQ(ticket.url, {
  // AG-UI events in order, then stream.completed, stream.interrupted or stream.failed
  onEvent: ({ type, data }) => render(type, data),
  onGap: () => reloadConversation(),
});
// When the component unmounts: subscription.close()
```

An approval ends the segment with `RUN_FINISHED` carrying the interrupt, then `stream.interrupted`, and IQ ends the stream. The page posts `{"decisions": {"<call id>": "approve"}}` to the same endpoint and subscribes to the ticket of that answer: the continuation is a stream of its own.

- `subscribeToIQ` opens an `EventSource`, which a server render does not have: call it from a mount hook. More on the subscription, the ticket's lifetime and IQ's terminal events: **neuron-streaming** (`references/channels.md`).
- The job's adapter is not seeded with browser messages on this path, so an interrupt's `MESSAGES_SNAPSHOT` carries only what this segment streamed. Render the conversation from your own state or from the reload endpoint, not from that snapshot.

## When something goes wrong

- **IQ unreachable or refusing.** The turn still completes and its answer is in history; the page receives nothing, or its subscription is refused and `onGap` reloads the conversation. Each segment raises `ChannelError` with an `IQStream\Exceptions\IQError` that carries IQ's status and code, after the client's retries (10 seconds by default). The client also writes it to PHP's error log, unless the `IQClient` is built with an `ErrorReporter` of its own, such as one that logs through `Log::warning()`.
- **A retry after a failed segment cannot stream.** The first attempt closed the stream with `fail`, and the page heard `iq:fail`: the retry's segment is refused with `stream_closed` (a `ChannelError`), and the retry runs without delivery. The page reloads the conversation from history.
- **`failed()` after such an attempt** finds the stream closed too, and its channel throws `stream_closed`: the page already heard of the failure.
- **A silent tool.** IQ lets go of a publish request that sends nothing for a minute and the next event continues the stream, but after about three minutes of silence the stream closes with `publisher_idle`: for tools that run longer, raise the server's limit on idle streams.
- **A fast segment holds the worker three seconds** at its close, so that a page that subscribes just after it still receives it: the client's `minOpen`.

Verified: the interface and `IQStreamDelivery` above, as written here, against a `ghcr.io/neuron-core/iq-stream:1` server. The ticket came from `IQClient::ticket()`, only the stream's ID went through a serialized job payload, and an agent run with an `AGUIAdapter` reached the ticket's URL as `RUN_STARTED`, `TEXT_MESSAGE_START`, the `TEXT_MESSAGE_CONTENT` events, `TEXT_MESSAGE_END`, `RUN_FINISHED`, `stream.completed`, then `iq:end`; `make()` without a stream threw its `LogicException`. Not run: this endpoint and job in a Laravel application, a queue worker, and the page in a browser. Envelope, ordering and the consumer: **neuron-streaming** (`references/channels.md`).
