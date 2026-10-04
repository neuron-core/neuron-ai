# Mercure: pushing a queued run to the browser

The browser opens an `EventSource` on the Mercure hub for the thread's private channel, then posts the turn; the endpoint queues `RunSupportAgent` and answers 202, and the worker publishes every AG-UI event through Neuron's `MercureChannel`, on the hub Laravel's own Mercure broadcaster uses. This ran with `symfony/mercure` 0.8.0 and `web-token/jwt-library` 4.2.3 against a `dunglas/mercure:v1.0.3` hub (Mercure protocol 1.0), a `queue:work` process and Chromium consuming with `subscribeToMercure` from `@neuron-core/streaming`.

## Install

```bash
php artisan install:broadcasting --mercure
```

In a terminal it asks for the hub (a standalone one, or FrankenPHP's), its URL, its public URL and the JWT secret. It requires `symfony/mercure` and `web-token/jwt-library`, creates `routes/channels.php`, registers it in `bootstrap/app.php` and writes:

```dotenv
BROADCAST_CONNECTION=mercure
MERCURE_URL="http://localhost:3720/.well-known/mercure"
MERCURE_PUBLIC_URL="http://localhost:3720/.well-known/mercure"
MERCURE_JWT_SECRET=...                       # 64 hex characters when the answer is left empty
MERCURE_COOKIE_NAME="mercure_access_token"   # only when the public URL is plain HTTP
VITE_MERCURE_HUB_URL="${MERCURE_PUBLIC_URL}"
```

- **It needs a terminal.** With `--no-interaction` it published `config/broadcasting.php`, created and registered `routes/channels.php`, set `BROADCAST_CONNECTION=mercure`, then stopped at its first question (`Required.`), before the variables and the packages. From then on every Artisan command failed with `The Mercure broadcasting connection requires a "url" configuration value, unless the application is served by FrankenPHP with its built-in Mercure hub enabled.` Without a terminal, finish its work by hand: write the `MERCURE_*` variables above, then run `composer require symfony/mercure:^0.8 web-token/jwt-library:^4.1`.
- **The cookie name** is set only for a plain-HTTP public URL, where a browser refuses the default `__Secure-` name. The hub must use the same name.
- **The hub is the developer's to configure.** It must trust the application as a token issuer: Laravel signs its tokens with `MERCURE_JWT_SECRET`, with `APP_URL` as their issuer and `MERCURE_PUBLIC_URL` as their audience. It must also accept the page's origin, since the page and the hub are different origins. The hub of these runs, for an application served at `http://localhost:8123`:

```bash
docker run -p 3720:80 -e SERVER_NAME=':80' \
  -e MERCURE_PUBLISHER_JWT_KEY="$MERCURE_JWT_SECRET" -e MERCURE_SUBSCRIBER_JWT_KEY="$MERCURE_JWT_SECRET" \
  -e MERCURE_TRUSTED_ISSUERS='http://localhost:8123' \
  -e MERCURE_EXTRA_DIRECTIVES=$'cookie_name mercure_access_token\ncors_origins http://localhost:8123' \
  dunglas/mercure:v1.0.3
```

## The channel factory

```php
namespace App\Neuron\Channels;

use Illuminate\Support\Facades\Broadcast;
use NeuronAI\Workflow\Streaming\Channel\MercureChannel;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;

/**
 * The worker publishes the run on the thread's private channel, through the hub of Laravel's Mercure broadcaster.
 */
class MercureBroadcast implements ChannelFactory
{
    public function make(string $threadId, string $runId): StreamingChannelInterface
    {
        return new MercureChannel(Broadcast::connection('mercure')->getHub(), self::topic($threadId));
    }

    /**
     * The topic the broadcaster gives the channel "private-agent.{threadId}": the one /broadcasting/auth lets its owner read.
     */
    public static function topic(string $threadId): string
    {
        return (config('broadcasting.connections.mercure.topic_prefix') ?: 'https://laravel.alt/echo/')
            .'channel/'.rawurlencode("private-agent.{$threadId}");
    }
}
```

Bind it in `NeuronServiceProvider`: `$this->app->bind(ChannelFactory::class, MercureBroadcast::class);`.

- `getHub()` returns the broadcaster's `Symfony\Component\Mercure\HubInterface`: the publisher token and the HTTP client are Laravel's. Publishing is synchronous from the worker and bypasses Laravel's broadcast queue.
- The topic follows the broadcaster's own naming, so Laravel's channel authorization decides who reads the stream. The updates are private: the hub delivers them only to a subscriber whose token names that topic.
- The updates hold Neuron envelopes, not Laravel broadcast events: the page reads them with its own `EventSource` and `subscribeToMercure`, not with Laravel Echo.

## Authorization

```php
// routes/channels.php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('agent.{threadId}', fn ($user, string $threadId): bool => str_starts_with($threadId, "user-{$user->id}-"));
```

`POST /broadcasting/auth` with `{"channel_names":["private-agent.<threadId>"]}` answered:

```json
{"channel_names":[{"name":"private-agent.user-1-94ec1a0c-…"}],"expires_in":300,"topic_prefix":"https://laravel.alt/echo/","client_events":true}
```

and set the hub's cookie: `mercure_access_token`, `HttpOnly`, `SameSite=Strict`, path `/.well-known/mercure`, 300 seconds. Its token lets the browser read the topic of that channel, and nothing else of the agent's.

- Another user asking for the same channel got a 200 with `"denied":true` and no grant. Their `EventSource` on the topic received nothing while the owner received the whole turn, and a turn they posted to the thread was a 403.
- With that token, a POST to the hub for the channel's topic was a 403: only the application publishes on it. (The same token could publish on the channel's separate whisper topic, which Laravel grants for client events.)
- **Every auth call replaces the cookie.** A call listing only another channel left the token with no grant for the agent channel; a call listing both restored it. List every channel the page reads in one call.

## The endpoint

`BackgroundChatController::dispatch`, its route and `ChatRequest` are those of [reverb.md](reverb.md): only the `ChannelFactory` binding changes.

## The browser

```js
import { subscribeToMercure } from "@neuron-core/streaming";

const headers = { "Content-Type": "application/json", Accept: "application/json" };
const channel = `private-agent.${threadId}`;

// Laravel authorizes the private channel and sets the hub's cookie.
const authorize = () => fetch("/broadcasting/auth", { method: "POST", headers, body: JSON.stringify({ channel_names: [channel] }) })
  .then((response) => response.json());
const authorization = await authorize();

const url = new URL(MERCURE_PUBLIC_URL);
url.searchParams.append("match", `${authorization.topic_prefix}channel/${encodeURIComponent(channel)}`);
const source = new EventSource(url, { withCredentials: true });

subscribeToMercure(source, {
  // AG-UI events in order, each segment closed by stream.completed, stream.interrupted or stream.failed
  onEvent: ({ type, data }) => render(type, data),
  onGap: () => reloadConversation(),
});

// "open" fires again after every reconnection: post the turn once.
source.addEventListener("open", async () => {
  await fetch(`/chat/threads/${threadId}/runs`, { method: "POST", headers, body: JSON.stringify({ message }) });
}, { once: true });

// The hub closes the connection before the token expires: a fresh cookie lets the browser reconnect.
setInterval(authorize, authorization.expires_in * 500);
```

`MERCURE_PUBLIC_URL` is the hub's public URL: `config('broadcasting.connections.mercure.public_url')` in a Blade page, `import.meta.env.VITE_MERCURE_HUB_URL` in a Vite bundle. What the page received in the run:

```
POST /runs 202 {"runId":"3815a8ce-…"}
RUN_STARTED, TEXT_MESSAGE_START, TEXT_MESSAGE_CONTENT ×29 (one event per token), TEXT_MESSAGE_END, RUN_FINISHED
stream.completed {"workflowId":"user-1-94ec1a0c-…"}
```

An approval arrives as `RUN_FINISHED` with the interrupt outcome, then `stream.interrupted`. The page posts `{"decisions": {"<call id>": "approve"}}` to the same endpoint; the continuation's segment arrives on the same topic under a new stream ID and ends with `stream.completed`.

- The job's adapter is not seeded with browser messages on this path, so the interrupt's `MESSAGES_SNAPSHOT` carried only what that segment streamed (the assistant's sentence, without the question). Render the conversation from your own state or from the reload endpoint, not from that snapshot.
- A message posted while the approval was pending was a 202; the job failed at once and its `failed()` hook closed the stream with `RUN_ERROR` and `stream.failed`.
- Events published before the subscription are gone: reconcile from the reload endpoint on `onGap` and on page load.

## Keeping the subscription open

The token lives `subscribe_expiration` minutes (5 by default: the auth response says `"expires_in":300`). The hub closes the connection a few seconds before the token expires, and the browser reconnects three seconds later with the cookie it holds at that moment.

- **With the renewal of the snippet**, a page whose tokens lived 15 seconds stayed subscribed across five expiries. Every reconnection was accepted, the hub replayed what it had published while the browser was away (a turn posted during a disconnection arrived whole, sequences 0 to 33), and `onGap` never fired. The replay comes from the hub's history, which its default transport keeps.
- **Without it**, the reconnection was refused and the `EventSource` closed for good (`readyState` 2): a turn posted afterwards delivered nothing.
- `open` fired again after each reconnection, six times in that run: the `{ once: true }` handler posted the turn once.

## A hub with limits

A managed hub (Mercure Cloud) caps the requests it accepts per second and their size, for the whole hub. State the plan's limits in the factory:

```php
// A hub that accepts one request per second of 15KB at most: the limits of the smallest Mercure Cloud plan.
return new MercureChannel(
    Broadcast::connection('mercure')->getHub(),
    self::topic($threadId),
    maxRequestBytes: 15_000,
    maxRequestsPerSecond: 1.0,
);
```

Against a hub limited to 15KB (`max_request_body_size 15KB`), a 2,520-character answer of 508 events arrived as 18 updates one second apart, about 32 events in each, and the hub refused none. An approval turn took three updates. The rate belongs to the hub: every run streaming at the same time and the application's own broadcasts share it, so give a run its share of the plan. What the channel does with a full request, a large event or a refused one: **neuron-streaming** ("MercureChannel").

## When the hub fails

With the hub stopped, the turn still completed and its answer was in history; the page received nothing. Each segment raised `ChannelError` twice, for its first event and for its terminal one. The hub's SDK reports every failure as `Failed to send an update.`, so log the previous exception, with the event bridge of [observability.md](observability.md):

```php
// AppServiceProvider::boot()
Event::listen(function (ChannelError $event): void {
    Log::warning('The agent stream was not delivered: '.($event->exception->getPrevious() ?? $event->exception)->getMessage());
});
```

It logged `The agent stream was not delivered: Failed to connect to localhost port 3720 after 0 ms: Connection refused for "http://localhost:3720/.well-known/mercure".`

Not run: a hub over HTTPS with the default `__Secure-` cookie, FrankenPHP's built-in hub, Laravel Echo on the same page, and Mercure Cloud itself. The size limit was reproduced on the open-source hub; no hub here refused a request for its rate, so the channel only paced itself. Envelope, packing and the consumer: **neuron-streaming** (`references/channels.md`).
