# Delivering a Messenger run to the browser

The handler ([background-runs.md](background-runs.md)) runs the turn in a worker with an `AGUIAdapter` seeded from the browser's input and a channel from `RedisRelay::publisher()`. Two ways to get those frames to the browser:

| Option | Fits | Browser side |
|---|---|---|
| Redis Pub/Sub relay | AG-UI clients that need one SSE response per request: the `HttpAgent`, or CopilotKit driving it in the browser | The same requests and frames as the synchronous endpoint: new turns, `resume`, trailing tool messages, browser tools. No `useChat` variant is shown |
| Mercure | Pages that subscribe once and receive pushes | `EventSource` on the hub, read with `subscribeToMercure` from `@neuron-core/streaming` |

Both carry the channel envelope `{streamId, sequence, type, data}` (a Mercure update holds an array of them) and end each segment with `stream.completed`, `stream.interrupted` or `stream.failed` (**neuron-streaming**, `references/channels.md`).

## Redis relay

The HTTP request connects to Redis and dispatches the message before its response, then subscribes to the run's Pub/Sub channel and relays each protocol event as an SSE frame until a terminal envelope. The worker waits for that subscription before publishing, because Pub/Sub keeps nothing for late subscribers.

```php
namespace App\Neuron;

use Closure;
use NeuronAI\Exceptions\ChannelReadException;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\Channel\RedisChannel;
use NeuronAI\Workflow\Streaming\Channel\RedisChannelReader;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use RedisException;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Carries a worker's run to the HTTP request that queued it, over Redis Pub/Sub.
 * Pub/Sub keeps nothing for late subscribers, so the worker waits for the request to listen.
 */
class RedisRelay
{
    public function __construct(#[Autowire(env: 'REDIS_DSN')] protected string $dsn)
    {
    }

    /** Worker side: the run's channel, whose first publish waits for the HTTP request to subscribe (10 s at most). */
    public function publisher(string $runId): StreamingChannelInterface
    {
        return new RedisChannel(RedisAdapter::createConnection($this->dsn), $this->channel($runId), awaitListener: 10);
    }

    /**
     * HTTP side, before the response: connect, then dispatch. An unreachable Redis throws here, before
     * any header, and nothing is queued. The returned body subscribes (the worker waits for this
     * subscription) and echoes each protocol event as an SSE frame until the run ends.
     */
    public function relay(string $runId, Closure $dispatch, StreamAdapterInterface $adapter): Closure
    {
        // Longer than the longest silent step of a turn (a tool batch plus one inference).
        $reader = new RedisChannelReader(RedisAdapter::createConnection($this->dsn), $this->channel($runId), timeout: 600);
        $dispatch();

        return function () use ($reader, $adapter): void {
            try {
                $reader->listen(fn (ProtocolEvent $event) => $this->send(SSEEncoder::frame($event)));
            } catch (ChannelReadException|RedisException $e) {
                // Headers are gone: end the protocol with its own error frame.
                foreach ($adapter->error($e) as $event) {
                    $this->send(SSEEncoder::frame($event));
                }
            }
        };
    }

    protected function send(string $frame): void
    {
        echo $frame;
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    protected function channel(string $runId): string
    {
        return "agent-run:{$runId}";
    }
}
```

```php
namespace App\Controller;

use App\Message\ResumeSupportAgent;
use App\Message\RunSupportAgent;
use App\Neuron\Agents\SupportAgent;
use App\Neuron\RedisRelay;
use App\Neuron\StopSignal;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Exceptions\InputTranslationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

class BackgroundChatController extends AbstractController
{
    public function __construct(
        protected SupportAgent $agent,
        protected RedisRelay $relay,
        protected StopSignal $stopSignal,
        protected MessageBusInterface $bus,
    ) {
    }

    #[Route('/chat/{threadId}/agui-background', methods: ['POST'])]
    #[IsCsrfTokenValid('chat', tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    #[IsGranted('THREAD', subject: 'threadId')]
    public function __invoke(string $threadId, Request $request): StreamedResponse
    {
        $input = $request->toArray();
        $translator = new AGUIInputTranslator();
        // The worker seeds its adapter from the same input; this one answers relay failures.
        $adapter = new AGUIAdapter(threadId: $threadId, runId: $input['runId'] ?? null, messages: $input['messages'] ?? [], state: $input['state'] ?? []);

        // Checked here to refuse bad input with a 400/409 now; the worker builds and stages it again.
        $agent = $this->agent->for($threadId)->addFrontendTools($translator->tools($input));
        if ($this->isContinuation($input)) {
            $agent->submitInputs($input, $translator);
            // The suspended run's ID: a retry of this message recognises its own run.
            $message = new ResumeSupportAgent($threadId, $agent->inspect()->runId, $input);
        } else {
            $message = new RunSupportAgent($threadId, (string) Uuid::v7(), $this->lastUserText($input), $input);
        }

        $this->stopSignal->clear($threadId);

        return new StreamedResponse($this->relay->relay($message->runId, fn () => $this->bus->dispatch($message), $adapter), 200, $adapter->getHeaders());
    }

    // isContinuation() and lastUserText(): the same helpers as the synchronous AG-UI controller.
}
```

- `REDIS_DSN` goes through `RedisAdapter::createConnection()`, which returns a `\Redis` for a `redis://` DSN and honours auth and database in the DSN. Each side opens its own connection: a subscribed connection can do nothing else.
- `relay()` connects before the response exists. A refused connection throws Symfony's `Cache\Exception\InvalidArgumentException`, not `RedisException`: with Redis unreachable, the endpoint answered an ordinary 500 before any header (`Redis connection failed: Connection refused`, logged by Symfony) and queued nothing. The body catches only what `listen()` throws.
- The controller's adapter is seeded from the same input as the worker's: a malformed seed is a 400 before anything is queued, and it answers relay failures with `RUN_ERROR`.
- Route the messages to an async transport. On `sync://` the handler would run inside `$dispatch()`, before the subscription: its channel waits the full 10 s, then publishes to nobody.
- `listen()` stops at the first segment's terminal event, so the relay serves one segment. A continuation is a new request, relayed on the channel of the run it continues.
- `listen()` throws `ChannelReadException` when nothing arrives within its timeout, or when the segment is incomplete: it started before the subscription, or a redelivery started another one before it ended. A worker killed mid-answer therefore ends the response with `RUN_ERROR` once its redelivery recovers the run, instead of a second `RUN_STARTED` that would restart the client's run; the answer arrives on reload.

What running it showed (Messenger on Redis, a real worker, `curl` and the official `@ag-ui/client` 0.0.59 as clients):

- A new turn streamed `RUN_STARTED … TOOL_CALL_RESULT … TEXT_MESSAGE_CONTENT … RUN_FINISHED` through the worker; a turn that needed approval ended with `RUN_FINISHED` carrying the interrupt, after a `MESSAGES_SNAPSHOT` equal to the synchronous endpoint's for the same input; the `resume` request executed the tool in the worker and streamed the answer.
- The official client ran a turn, an approval, a reload, the `resume` after it, a browser tool round trip and a Stop through this endpoint and through the synchronous one, and held the same messages after every step.
- A second turn while the approval was pending: the handler gave the message up and `PublishRunFailure` sent `RUN_ERROR` then `stream.failed`, which ended the relay within a second.
- A provider failure after the tool on all four deliveries: the relay received one `RUN_ERROR` and closed; each retry published its own segment to nobody after waiting 10 s for a subscriber, and the next turn finished the run.
- No worker and a 2 s reader timeout: `ChannelReadException` inside the stream, answered with the adapter's `RUN_ERROR` under the 200.
- A worker killed with `kill -9` mid-answer: the frames stopped, and the response ended with `RUN_ERROR` when the redelivery started the recovered segment, 27 s later.
- Stop during a queued turn: the flag raised by the HTTP process stopped the worker's stream; history kept the partial answer with stop reason `stopped`. The AG-UI client's `abortRun()` instead never reached the worker, which finished the whole answer.

## Failure publication

A run that fails inside its segment sends `RUN_ERROR` and `stream.failed` itself, on every attempt. Refusals at admission, staging errors and crashes before the segment emit no frame, so the relay would wait until its timeout. Publish the terminal pair for those once Messenger gives the message up, on a fresh channel with a fresh adapter; `error()` of an adapter that sent nothing yields a bare `RUN_ERROR`, which the AG-UI client accepts as a first event.

```php
namespace App\Neuron;

use App\Message\ResumeSupportAgent;
use App\Message\RunSupportAgent;
use Closure;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowStatus;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Throwable;

/**
 * A run that failed inside its segment already sent RUN_ERROR and stream.failed. Refusals at
 * admission, staging errors and crashes before the segment send nothing: publish the terminal
 * pair for those once Messenger gives the message up.
 */
#[AsEventListener]
class PublishRunFailure
{
    public function __construct(
        protected RedisRelay $relay,
        #[AutowireServiceClosure(WorkflowEngine::class)] protected Closure $engine,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ($event->willRetry() || !($message instanceof RunSupportAgent || $message instanceof ResumeSupportAgent) || $this->reported($message)) {
            return;
        }

        $channel = $this->relay->publisher($message->runId);
        foreach ((new AGUIAdapter(threadId: $message->threadId, runId: $message->runId))->error($event->getThrowable()) as $frame) {
            $channel->send($frame);
        }
        $channel->failed($event->getThrowable(), $message->threadId);
    }

    /** The message's own run failed: its segment published the failure. */
    protected function reported(RunSupportAgent|ResumeSupportAgent $message): bool
    {
        try {
            $run = ($this->engine)()->inspect($message->threadId);
        } catch (Throwable) {
            return false; // the store is down: nothing ran
        }

        return $run?->runId === $message->runId && $run->status === WorkflowStatus::Failed;
    }
}
```

- `reported()` reads the thread's run through a `shared: false` `WorkflowEngine`: when the message's own run is `failed`, its segment already published the failure, and a second pair would show the browser a second error. A store that cannot be read means nothing ran.
- A turn queued while an approval was pending (refused at admission, given up at once): the relay received the listener's `RUN_ERROR` and closed on its `stream.failed`. A turn whose answer failed on all four deliveries: four segments with their own `RUN_ERROR`, none from the listener.

## Mercure

The page opens an `EventSource` on the hub for its thread, then posts the turn. The controller dispatches and answers 202, and the worker publishes every AG-UI event through Neuron's `MercureChannel`, on the hub the bundle configures. Nothing waits for a subscriber. This ran with `symfony/mercure-bundle` 0.5.0 and `symfony/mercure` 0.8.0 against a `dunglas/mercure:v1.0.3` hub (Mercure protocol 1.0), Messenger on Redis with a real `messenger:consume` worker, and Chromium consuming with `subscribeToMercure` from `@neuron-core/streaming`.

### The hub

`composer require symfony/mercure-bundle` installs the bundle with `symfony/mercure` and `lcobucci/jwt`. Its recipe writes `MERCURE_URL`, `MERCURE_PUBLIC_URL` and `MERCURE_JWT_SECRET` to `.env`, and a configuration for the 0.x protocol. A hub speaking Mercure 1.0 needs three more keys:

```yaml
# config/packages/mercure.yaml
mercure:
    hubs:
        default:
            url: '%env(default::MERCURE_URL)%'
            public_url: '%env(default::MERCURE_PUBLIC_URL)%'
            protocol_version: '1.0'
            # Plain-HTTP development only: over HTTPS the default __Secure-mercure_access_token applies.
            cookie_name: mercure_access_token
            jwt:
                secret: '%env(MERCURE_JWT_SECRET)%'
                publish: '*'
                claims:
                    iss: '%env(DEFAULT_URI)%'
                    sub: support-app
                    client_id: support-app
```

- **`protocol_version` defaults to `0.x`.** Left there against the 1.0 hub, every publish was refused with `401 Unauthorized` (the hub logged `invalid JWT: untrusted issuer ""`) while each turn still completed, and the subscriber cookie was the legacy `mercureAuthorization`.
- **The claims are required.** Without them the container did not build: `The "mercure.hubs.default.jwt.claims" option must define the "iss", "sub", "client_id" claim(s): they are required by RFC 9068 access tokens when "protocol_version" is "1.0"`. The audience defaults to the hub's URL.
- **The hub is the developer's to configure.** It must trust `iss` as a token issuer, with the same secret for publishers and subscribers, accept the page's origin (the page and the hub are different origins) and use the same cookie name. The hub of these runs, for an application served at `http://localhost:8124` (its `DEFAULT_URI`):

```bash
docker run -p 3721:80 -e SERVER_NAME=':80' \
  -e MERCURE_PUBLISHER_JWT_KEY="$MERCURE_JWT_SECRET" -e MERCURE_SUBSCRIBER_JWT_KEY="$MERCURE_JWT_SECRET" \
  -e MERCURE_TRUSTED_ISSUERS='http://localhost:8124' \
  -e MERCURE_EXTRA_DIRECTIVES=$'cookie_name mercure_access_token\ncors_origins http://localhost:8124' \
  dunglas/mercure:v1.0.3
```

### The channel

```php
namespace App\Neuron;

use NeuronAI\Workflow\Streaming\Channel\MercureChannel;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use Symfony\Component\Mercure\HubInterface;

/**
 * A thread's stream on the Mercure hub: the worker publishes its runs there, the browser of its owner subscribes.
 */
class MercureStream
{
    public function __construct(protected HubInterface $hub)
    {
    }

    /** Worker side: a channel for one segment of a run. */
    public function publisher(string $threadId): StreamingChannelInterface
    {
        return new MercureChannel($this->hub, $this->topic($threadId));
    }

    public function topic(string $threadId): string
    {
        return 'https://shop.example/threads/'.rawurlencode($threadId);
    }
}
```

`HubInterface` is the bundle's default hub: the publisher token and the HTTP client are its own. In the handler ([background-runs.md](background-runs.md)) and in `PublishRunFailure` above, inject `MercureStream $stream` in place of `RedisRelay $relay` and publish on the thread:

```php
// SupportAgentHandler::agent()
->setChannel(fn (): StreamingChannelInterface => $this->stream->publisher($threadId))

// PublishRunFailure::__invoke()
$channel = $this->stream->publisher($message->threadId);
```

The updates are private: the hub delivers them only to a subscriber whose token names the thread's topic. The topic is an identifier, not an address: any IRI the application owns will do.

### The endpoint

The relay controller without its relay: it checks the input, dispatches and answers 202 with the run's ID.

```php
namespace App\Controller;

use App\Message\ResumeSupportAgent;
use App\Message\RunSupportAgent;
use App\Neuron\Agents\SupportAgent;
use App\Neuron\StopSignal;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Exceptions\InputTranslationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

class BackgroundChatController extends AbstractController
{
    public function __construct(
        protected SupportAgent $agent,
        protected StopSignal $stopSignal,
        protected MessageBusInterface $bus,
    ) {
    }

    #[Route('/chat/{threadId}/runs', methods: ['POST'])]
    #[IsCsrfTokenValid('chat', tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    #[IsGranted('THREAD', subject: 'threadId')]
    public function __invoke(string $threadId, Request $request): JsonResponse
    {
        $input = $request->toArray();
        $translator = new AGUIInputTranslator();

        // Checked here to refuse bad input with a 400/409 now; the worker seeds its adapter and stages the answers again.
        new AGUIAdapter(threadId: $threadId, runId: $input['runId'] ?? null, messages: $input['messages'] ?? [], state: $input['state'] ?? []);
        $agent = $this->agent->for($threadId)->addFrontendTools($translator->tools($input));
        if ($this->isContinuation($input)) {
            $agent->submitInputs($input, $translator);
            // The suspended run's ID: a retry of this message recognises its own run.
            $message = new ResumeSupportAgent($threadId, $agent->inspect()->runId, $input);
        } else {
            $message = new RunSupportAgent($threadId, (string) Uuid::v7(), $this->lastUserText($input), $input);
        }

        $this->stopSignal->clear($threadId);
        $this->bus->dispatch($message);

        return new JsonResponse(['runId' => $message->runId], 202);
    }

    // isContinuation() and lastUserText(): the same helpers as the synchronous AG-UI controller.
}
```

- It accepts what the relay endpoint accepts, the AG-UI `RunAgentInput`: a trailing user message starts a turn, a `resume` array continues the suspended run, whose ID the answer returns.
- A `resume` with no pending interrupt was a 400 (`There is no persisted run to continue.`) and nothing was queued.
- Route the messages to an async transport, as for the relay.

### The subscriber

An endpoint authorizes the thread and sets the hub's cookie, here in the controller that mints and reloads threads:

```php
    /** Seconds the browser may read the thread's updates before it asks again. */
    protected const SUBSCRIPTION_LIFETIME = 600;

    #[Route('/chat/{threadId}/subscription', methods: ['POST'])]
    #[IsCsrfTokenValid('chat', tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    #[IsGranted('THREAD', subject: 'threadId')]
    public function subscribe(string $threadId, Request $request, Authorization $authorization, HubInterface $hub, MercureStream $stream): JsonResponse
    {
        $topic = $stream->topic($threadId);
        // The bundle adds the hub's cookie to the response: its token grants this topic and nothing else.
        $authorization->setCookie($request, [$topic], additionalClaims: ['exp' => new \DateTimeImmutable('+'.self::SUBSCRIPTION_LIFETIME.' seconds')]);

        return new JsonResponse(['hub' => $hub->getPublicUrl(), 'topic' => $topic, 'expiresIn' => self::SUBSCRIPTION_LIFETIME]);
    }
```

`Authorization` and `HubInterface` are the `Symfony\Component\Mercure` services the bundle autowires. It answered `{"hub":"http://localhost:3721/.well-known/mercure","topic":"https://shop.example/threads/user-1-e26de28c-…","expiresIn":600}` with `Set-Cookie: mercure_access_token=…; Max-Age=600; path=/.well-known/mercure; httponly; samesite=strict`. The token carries the configured claims and lets its bearer read that topic, and nothing else.

- Another user asking for the thread got a 403 from the voter. Their `EventSource` on the topic, with the cookie of a thread of their own, received nothing while the owner received the whole turn.
- Without an `exp` claim, `setCookie()` minted a token of one hour in a session cookie.

```js
import { subscribeToMercure } from "@neuron-core/streaming";

const headers = { "Content-Type": "application/json", "X-CSRF-Token": "csrf-token" };

// The application authorizes the thread and sets the hub's cookie.
const subscribe = () => fetch(`/chat/${threadId}/subscription`, { method: "POST", headers }).then((response) => response.json());
const { hub, topic, expiresIn } = await subscribe();

const url = new URL(hub);
url.searchParams.append("match", topic);
const source = new EventSource(url, { withCredentials: true });

subscribeToMercure(source, {
  // AG-UI events in order, each segment closed by stream.completed, stream.interrupted or stream.failed
  onEvent: ({ type, data }) => render(type, data),
  onGap: () => reloadConversation(),
});

// "open" fires again after every reconnection: post the turn once.
source.addEventListener("open", async () => {
  await fetch(`/chat/${threadId}/runs`, { method: "POST", headers, body: JSON.stringify(input) });
}, { once: true });

// The hub closes the connection before the token expires: a fresh cookie lets the browser reconnect.
setInterval(subscribe, expiresIn * 500);
```

`input` is the AG-UI `RunAgentInput`: `{ runId, messages: [{ id, role: "user", content }], state: {}, tools: [] }` for a turn, `{ runId, messages: [], resume: [{ interruptId, status: "resolved", payload: { approved: true } }] }` for an approval.

### What ran

- **A turn**: `POST /chat/{threadId}/runs` answered 202 with the run's ID, and the page received `RUN_STARTED`, `TEXT_MESSAGE_START`, 29 `TEXT_MESSAGE_CONTENT`, `TEXT_MESSAGE_END`, `RUN_FINISHED`, then `stream.completed`.
- **An approval**: the segment ended with `STATE_SNAPSHOT`, `MESSAGES_SNAPSHOT` (the client's question and the assistant's sentence: the worker's adapter is seeded with the input), `RUN_FINISHED` carrying the interrupt, then `stream.interrupted`. The `resume` request answered 202 with the suspended run's ID; its segment arrived on the same topic under a new stream ID, with the tool call, its result and the answer, and the refund was executed once.
- **A turn posted while the approval was pending** was a 202: Messenger gave the message up at once (`Another run holds the thread.`) and `PublishRunFailure` closed the stream with `RUN_ERROR` and `stream.failed`.
- **The hub stopped**: the turn still completed and its answer was in history; the page received nothing. Each segment raised `ChannelError` twice, for its first event and for its terminal one. The hub's SDK reports every failure as `Failed to send an update.`, so the listener of SKILL.md logged only that; with `($event->exception->getPrevious() ?? $event->exception)->getMessage()` it logged `Failed to connect to localhost port 3721 after 0 ms: Connection refused for "http://localhost:3721/.well-known/mercure".`
- **The token's expiry**: the hub closes the connection a few seconds before the token expires, and the browser reconnects three seconds later with the cookie it holds at that moment. With the renewal of the snippet, a page whose tokens lived 15 seconds stayed subscribed across four expiries: every reconnection was accepted, the hub replayed what it had published while the browser was away (a turn posted during a disconnection arrived whole, sequences 0 to 33) and `onGap` never fired. The replay comes from the hub's history, which its default transport keeps. `open` fired again after each reconnection: the `{ once: true }` handler posted the turn once.
- **A hub with limits**: Mercure Cloud caps the requests it accepts per second and their size, for the whole hub. With `new MercureChannel($this->hub, $this->topic($threadId), maxRequestBytes: 15_000, maxRequestsPerSecond: 1.0)` against a hub limited to 15KB (`max_request_body_size 15KB`), a 2,600-character answer of 524 events arrived as 18 updates about a second apart, some 33 events in each, and the hub refused none. Every run streaming at the same time shares the hub's rate: give a run its share of the plan. What the channel does with a full request, a large event or a refused one: **neuron-streaming** ("MercureChannel").

Not run: a hub over HTTPS with the default `__Secure-` cookie, the `mercure()` Twig helper in place of the subscription endpoint, and Mercure Cloud itself. The size limit was reproduced on the open-source hub; no hub here refused a request for its rate, so the channel only paced itself. Events published before the subscription are gone: reconcile from the reload endpoint on `onGap` and on page load.
