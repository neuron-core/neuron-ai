# Delivering a Messenger run to the browser

The handler ([background-runs.md](background-runs.md)) runs the turn in a worker with an `AGUIAdapter` seeded from the browser's input and a channel from `RedisRelay::publisher()`. Two ways to get those frames to the browser:

| Option | Fits | Browser side |
|---|---|---|
| Redis Pub/Sub relay | AG-UI clients that need one SSE response per request: the `HttpAgent`, or CopilotKit driving it in the browser | The same requests and frames as the synchronous endpoint: new turns, `resume`, trailing tool messages, browser tools. No `useChat` variant is shown |
| Mercure | Pages that subscribe once and receive pushes | `EventSource` on the hub, envelopes fed to `@neuron-core/streaming` |

Both carry the channel envelope `{streamId, sequence, type, data}` and end each segment with `stream.completed`, `stream.interrupted` or `stream.failed` (**neuron-streaming**, `references/channels.md`).

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

A channel that publishes each envelope as a private update:

```php
namespace App\Neuron\Channels;

use NeuronAI\Workflow\Streaming\Channel\AbstractChannel;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes each envelope of a run segment as a private Mercure update.
 * The hub throws on a failed publish, which Neuron reports as a ChannelError.
 */
class MercureChannel extends AbstractChannel
{
    public function __construct(
        protected HubInterface $hub,
        protected string $topic,
    ) {
    }

    protected function deliver(string $batch): void
    {
        $this->hub->publish(new Update($this->topic, $batch, private: true));
    }
}
```

In the handler, inject `HubInterface $hub` and replace the channel factory:

```php
use App\Neuron\Channels\MercureChannel;

->setChannel(fn (): MercureChannel => new MercureChannel($this->hub, "https://shop.example/threads/{$threadId}"))
```

The controller then dispatches and answers 202; nothing waits for a subscriber, so the browser must be subscribed to the topic before it posts, and reconciles from the reload endpoint after a gap.

Proven with `symfony/mercure-bundle` 0.5 against a `dunglas/mercure:v0.21` hub, building the copy exactly as above on the container's agent: a streamed turn, a turn suspended on approval, and its continuation reached a subscriber as three segments, each with its own `streamId`, sequences from 0, and `stream.completed`, `stream.interrupted`, `stream.completed` at the end. While the hub answered 401, every turn still completed: a failed delivery never fails the run.

Not run: a worker handling the message with this channel (the proof built the same copy in a script), the 202 controller, the browser side (the `mercure()` Twig helper or `Authorization::setCookie()` for the subscriber cookie, then `createChannelConsumer()` from `@neuron-core/streaming`, see **neuron-streaming**), and a hub speaking Mercure protocol 1.0. The current `dunglas/mercure` image does: it refused the bundle's default publisher token with `untrusted issuer ""`, so such a hub needs the bundle's `protocol_version` and `claims` options set to match it.
