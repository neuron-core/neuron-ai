---
name: neuron-symfony-integration
description: Integrate Neuron AI agents into a Symfony 8 application the Symfony way — a foundations checklist (packages, the App\Neuron layout, keys from env vars, a Doctrine migration for chat_messages and workflow_store, services.yaml wiring of the message store, workflow persistence and WorkflowEngine over Doctrine's PDO, Neuron exceptions mapped to HTTP statuses, a bin/console neuron:evaluate command wrapping Neuron's evaluation runner), then a shared agent bound per request with for(), thread ownership with a voter, a JSON chat endpoint, an AG-UI/CopilotKit or Vercel useChat streaming endpoint with StreamedResponse, reload with pending approvals, Messenger background runs with a reserved run ID and failure publication, Redis relay or Mercure delivery, a Stop button, observability through Symfony's event dispatcher, evaluators built by the container with in-memory stores and run in parallel on the app's database, and WebTestCase tests with FakeAIProvider. Use this skill whenever the user mentions Symfony together with Neuron, an AI assistant or chat in a Symfony app, streaming an agent from a Symfony controller, running an agent in Messenger, a worker or a background job, showing a conversation after a reload, a stop-generating button, evaluating an agent in a Symfony app, or testing agents in Symfony. Also trigger for any task involving services.yaml with MessageStoreInterface, PersistenceInterface, WorkflowEngine, SQLMessageStore, DatabasePersistence, getNativeConnection, AutowireServiceClosure, #[Autowire(env:)], StreamedResponse with SSEEncoder, AGUIAdapter::hydrate, #[AsMessageHandler] with ExecutionRequest::start, RecoverableMessageHandlingException, WorkerMessageFailedEvent, RedisChannel, RedisChannelReader, MercureChannel, StoppableHttpClient with a cache pool, #[AsEventListener] on Neuron events, KernelBrowser disableReboot, doctrine:migrations for Neuron tables, EvaluationCommand, EvaluatorRunner beforeChild, #[AutowireLocator] of evaluators, evaluation.php or make:evaluators in a Symfony app.
---

# Neuron AI Symfony Integration

This skill wires Neuron into a Symfony 8 application: first the foundations every feature stands on (packages, layout, keys, tables, container wiring, error mapping, the evaluation command), then the features users ask for, from the agent and the controllers that run it inside a request to the Messenger handler that runs it in a worker, the Stop button, observability, evaluation and tests. Every snippet comes from a reference app (Symfony 8.1, Doctrine DBAL 4, Messenger on Redis) that ran on SQLite, MySQL 8.4, MariaDB 11.7 and PostgreSQL 17. What the agent, the protocols, the approval flow and evaluators mean is owned by the skills under Related; this one owns the Symfony shape.

## The Mental Model

**The container holds definitions; every request or message works on a bound copy.** The agent is a shared service. A controller or handler calls `$this->agent->for($threadId)`, which returns a copy bound to the thread and leaves the shared agent unbound. Per-request pieces (stream adapter, channel, frontend tools) go on the copy. Never call `setThreadId()` on the injected agent: the second request or message throws `WorkflowException: This workflow is bound to 't-a' and cannot be re-pointed to 't-b'.`

**Hooks run once per copy, so anything holding a connection is resolved there.** The agent pulls its stores through service closures of `shared: false` services built on Doctrine's current PDO. The shared agent never resolves them; each copy gets its own instances.

| Piece | Where | Lifetime |
|---|---|---|
| `SupportAgent`: provider hook, instructions, tools | `src/Neuron/Agents`, autowired | Shared service, never bound |
| Bound copy + adapter, channel, frontend tools | Controller action, message handler | One request or one message |
| `MessageStoreInterface`, `PersistenceInterface`, `neuron.pdo` | `config/packages/neuron.yaml` | `shared: false`: new per resolution, current PDO |
| `WorkflowEngine` | Autowired, `shared: false` | Reads runs without building the agent |
| Provider wrapped in `StoppableHttpClient` | Agent `provider()` hook | Built for every execution segment |
| Stop flags | Cache pool `neuron.stop_signals` | One flag stops one answer |
| Thread ownership | `ThreadVoter` + `#[IsGranted]` | Every Neuron route |
| Neuron exceptions to statuses | `NeuronExceptionListener` | `kernel.exception` |
| Background turn | `RunSupportAgent` message + handler | Run ID minted at dispatch |
| Evaluators | `src/Neuron/Evaluators`, run by `bin/console neuron:evaluate` | One copy with in-memory stores per dataset item |

## Setup: the Foundations

When the app has no Neuron integration yet, lay these foundations before building the feature the user asked for, in this order. Check first and skip each one that exists: `neuron-core/neuron-ai` in `composer.json`, `config/packages/neuron.yaml` (or store services in `config/services.yaml`), a migration creating `chat_messages`, classes in `src/Neuron/Agents`, a `kernel.exception` listener for Neuron exceptions, `evaluation.php` and `php bin/console list neuron`. The feature sections after Setup build on these.

### 1. Install

```bash
composer require neuron-core/neuron-ai
composer require --dev spatie/fork
```

`spatie/fork` and `ext-pcntl` run evaluation items in parallel; without them `--concurrency` prints a notice and runs sequentially. The features below also use `doctrine/doctrine-bundle` and `doctrine/doctrine-migrations-bundle` with a `pdo_*` driver, `symfony/security-bundle`, `symfony/uid` (thread and run IDs), `symfony/messenger` with a transport (`symfony/redis-messenger` here), and for delivery `ext-redis` or `symfony/mercure-bundle`.

### 2. Layout and generators

Neuron classes live under `App\Neuron` in `src/Neuron`: `Agents`, `Tools`, `Workflows`, `Nodes`, `Middleware`, `Channels`, `Evaluators` (datasets in `Evaluators/datasets`), and framework glue at the root (`StopSignal`, `RedisRelay`, listeners). Framework-owned classes stay where Symfony puts them: `src/Controller`, `src/Command`, `src/Message`, `src/MessageHandler`, `src/Security/Voter`, `migrations/`, `config/packages/neuron.yaml`. The default `App\: resource: '../src/'` registers them all.

```bash
vendor/bin/neuron make:agent 'App\Neuron\Agents\SupportAgent'
vendor/bin/neuron make:tool 'App\Neuron\Tools\OrderStatusTool'
vendor/bin/neuron make:evaluators 'App\Neuron\Evaluators\SupportAgentEvaluator'
```

Pass the fully qualified name: without it the class lands in `src/`. The generated `provider()` hard-codes `key: 'ANTHROPIC_KEY'`; replace it as The Agent shows.

### 3. Keys

Keys are env vars: a placeholder in `.env`, the real value in a real env var or `.env.local`, injected with `#[Autowire(env: 'ANTHROPIC_API_KEY')]` or `%env(ANTHROPIC_API_KEY)%`.

```dotenv
###> neuron ###
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-sonnet-4-6
REDIS_DSN=redis://127.0.0.1:6379
###< neuron ###
```

- The env value is read when the service is built, not when the container compiles: the same compiled container picked up a new `ANTHROPIC_API_KEY` after a restart, with no `cache:clear`.
- Never read `$_ENV` or `getenv()` in app classes.
- Dotenv skips a variable only when it is already in `$_SERVER` or `$_ENV`. PHP's built-in server with `variables_order=GPCS` puts real env vars in neither, so the `.env` placeholder won and the provider sent an empty key. Start it with `php -d variables_order=EGPCS -S …`, or keep local values in `.env.local`.

### 4. Database tables

One Doctrine migration creates both tables with the DDL core documents, per platform: `VARBINARY` thread and message IDs and `ascii_bin` keys on MySQL and MariaDB, an identity column on PostgreSQL, plain types on SQLite. MySQL and MariaDB need strict SQL mode: on a session without it the first write threw `PersistenceException: Workflow persistence requires MySQL strict SQL mode to prevent truncated records.` The `schema_filter` of step 5 keeps Doctrine's diff away from them.

Read [references/database.md](references/database.md) when writing the migration: the full class, why PostgreSQL needs `GENERATED BY DEFAULT AS IDENTITY` instead of `BIGSERIAL`, and how it was proven on the four platforms.

### 5. Container wiring

```yaml
# config/packages/neuron.yaml
services:
    _defaults:
        autowire: true

    # Not shared: every resolution reads the connection's current PDO, so a
    # store never keeps a handle Doctrine closed and reopened in a worker.
    neuron.pdo:
        class: PDO
        factory: ['@doctrine.dbal.default_connection', 'getNativeConnection']
        shared: false

    NeuronAI\Chat\History\MessageStoreInterface:
        class: NeuronAI\Chat\History\SQLMessageStore
        arguments: ['@neuron.pdo']
        shared: false

    NeuronAI\Workflow\Persistence\PersistenceInterface:
        class: NeuronAI\Workflow\Persistence\DatabasePersistence
        arguments: ['@neuron.pdo']
        shared: false

    NeuronAI\Workflow\WorkflowEngine:
        shared: false

framework:
    cache:
        pools:
            neuron.stop_signals:
                adapter: cache.app

doctrine:
    dbal:
        schema_filter: '~^(?!(chat_messages|workflow_store)$)~'
```

```yaml
# config/services.yaml, next to the App\ resource
    _instanceof:
        NeuronAI\Workflow\Workflow:
            calls:
                - [setEventDispatcher, ['@event_dispatcher']]
        # Reached by bin/console neuron:evaluate through a service locator.
        NeuronAI\Evaluation\Contracts\EvaluatorInterface:
            tags: [neuron.evaluation]
        NeuronAI\Evaluation\Contracts\EvaluationOutputInterface:
            tags: [neuron.evaluation]
```

- `WorkflowEngine` autowires from `PersistenceInterface` with its default `PhpSerializer`, the one an Agent uses. If an agent gets another serializer, register the same `Serializer` service for the engine.
- Doctrine's PDO driver sets `ERRMODE_EXCEPTION`, which `DatabasePersistence` requires; `getNativeConnection()` is a PDO only with a `pdo_*` driver.
- **Why `shared: false`.** In a worker on MySQL, after the server dropped the idle connection, stores built per message worked, while shared stores kept the dead PDO and failed every retry with `2006 MySQL server has gone away`. DBAL itself reconnects only when something closes the dropped connection: add Messenger's `doctrine_ping_connection` middleware (Background Runs).
- Without the `schema_filter`, `doctrine:migrations:diff` and `doctrine:schema:update` proposed `DROP TABLE chat_messages` and `DROP TABLE workflow_store`.
- `_instanceof` applies to the services defined in the same file: an agent redefined in `services_dev.yaml` lost the call.

### 6. Neuron exceptions to HTTP statuses

```php
namespace App\Neuron;

use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\WorkflowException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

#[AsEventListener(priority: 10)]
class NeuronExceptionListener
{
    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();

        $response = match (true) {
            $e instanceof InputTranslationException => new JsonResponse(['error' => $e->getMessage()], 400),
            $e instanceof PersistenceException => new JsonResponse(['error' => 'The conversation store is unavailable.'], 503),
            $e instanceof RunInFlightException => new JsonResponse(
                ['error' => 'The conversation is busy.', 'status' => $e->status->value],
                409,
                $e->leaseExpiresAt !== null ? ['Retry-After' => max(1, $e->leaseExpiresAt - time())] : [],
            ),
            $e instanceof WorkflowException => new JsonResponse(['error' => 'The conversation changed: reload it.'], 409),
            default => null,
        };

        if ($response !== null) {
            if ($response->getStatusCode() >= 500) {
                $this->logger->error($e->getMessage(), ['exception' => $e]);
            }
            $event->setResponse($response);
        }
    }
}
```

- The most specific class comes first: `PersistenceException` and `RunInFlightException` extend `WorkflowException`. Only `InputTranslationException`'s message is written for clients; the rest stay in the log.
- `Retry-After` is the lease expiry, an upper bound: a turn still streaming gave `Retry-After: 598`. A suspended run holds no lease, so no header.
- `framework.exceptions` is not enough: it matched the first `instanceof` in config order (a `RunInFlightException` listed after `WorkflowException` got the latter's status), and in prod it rendered the HTML error page with no message and no `Retry-After`.

### 7. The evaluation command

`php bin/console neuron:evaluate` wraps Neuron's `EvaluationCommand`: same path argument, `--concurrency`, `--cache`, `--fresh`, `-v`, output and exit code, with evaluators and output drivers built by the container and a runner that makes `--concurrency` safe on Doctrine's connection. Create `src/Command/NeuronEvaluateCommand.php` from [references/evaluation.md](references/evaluation.md); Evaluation below explains its parts. Next to it, `evaluation.php` at the project root keeps the output drivers and the cache path; the command's own resolver and runner win over its `resolver` and `runner` entries:

```php
// evaluation.php
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;

return [
    'output' => [ConsoleOutput::class, new JsonOutput('var/evaluation.json')],
    'cache' => ['path' => 'var/evaluation/cache'],
];
```

## The Agent

```php
namespace App\Neuron\Agents;

use App\Neuron\StopSignal;
use App\Neuron\Tools\OrderStatusTool;
use App\Neuron\Tools\RefundOrderTool;
use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

class SupportAgent extends Agent
{
    public function __construct(
        #[Autowire(env: 'ANTHROPIC_API_KEY')] protected string $anthropicKey,
        #[Autowire(env: 'ANTHROPIC_MODEL')] protected string $anthropicModel,
        #[AutowireServiceClosure(MessageStoreInterface::class)] protected Closure $conversations,
        #[AutowireServiceClosure(PersistenceInterface::class)] protected Closure $runs,
        protected StopSignal $stopSignal,
        protected OrderStatusTool $orderStatus,
        protected RefundOrderTool $refundOrder,
    ) {
        parent::__construct();
    }

    protected function provider(): AIProviderInterface
    {
        return new Anthropic(
            key: $this->anthropicKey,
            model: $this->anthropicModel,
            httpClient: new StoppableHttpClient(
                new CurlHttpClient(),
                fn (): bool => $this->stopSignal->pull($this->getThreadId()),
            ),
        );
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(background: ['You are the support assistant of an online shop.']);
    }

    protected function tools(): array
    {
        return [$this->orderStatus, $this->refundOrder];
    }

    protected function messageStore(): MessageStoreInterface
    {
        return ($this->conversations)();
    }

    protected function persistence(): PersistenceInterface
    {
        return ($this->runs)();
    }

    /**
     * @param list<FrontendTool> $tools
     * @throws InputTranslationException when a browser tool shadows a backend tool
     */
    public function addFrontendTools(array $tools): static
    {
        $backend = array_map(fn (ToolInterface $tool): string => $tool->getName(), $this->tools());
        foreach ($tools as $tool) {
            if (in_array($tool->getName(), $backend, true)) {
                throw new InputTranslationException("Frontend tool '{$tool->getName()}' collides with a backend tool.");
            }
        }

        return $this->addTool($tools);
    }
}
```

- **Each agent owns its provider.** The hook builds it per segment from injected env values: each agent picks its model, no provider instance is shared between agents, and the Stop predicate knows the thread. Do not register a global `AIProviderInterface`; tests use `setAiProvider()`, which wins over the hook and reaches every copy.
- **Promoted properties must not reuse Agent property names** (`$messageStore`, `$persistence`, `$provider`, `$tools`, …): `protected MessageStoreInterface $messageStore` is a fatal `Type of …::$messageStore must be ?NeuronAI\Chat\History\MessageStoreInterface`. Always call `parent::__construct()`.
- Tools are services with their own dependencies; `RefundOrderTool` requires approval through its `approvalPolicy()`. See **neuron-tool** and **neuron-tool-approval**.

## Thread Identity and Authorization

The thread ID selects which conversation is read, written and resumed: it is untrusted input. Mint it on the server and authorize ownership before `for()`.

`POST /chat` mints it for the `#[CurrentUser] User $user`: `$this->json(['threadId' => "user-{$user->getId()}-".Uuid::v4()], 201)`.

```php
namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Thread IDs are "user-{id}-{uuid}", minted by the server: the prefix names the owner.
 *
 * @extends Voter<string, string>
 */
class ThreadVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === 'THREAD' && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && str_starts_with($subject, "user-{$user->getId()}-");
    }
}
```

The `/chat` routes sit behind the firewall (`access_control: - { path: ^/chat, roles: ROLE_USER }`), and every route that names a thread carries it in its path (`/chat/{threadId}/…`) with `#[IsGranted('THREAD', subject: 'threadId')]`: another user's thread was a 403 before the controller ran. Key the prefix on the numeric ID: with identifiers that contain `-`, user `al` would own `user-al-ice-…`. Apps that list or title conversations can store a `Conversation` entity and vote on it instead. What a pending approval locks: **neuron-tool-approval**.

## A JSON Chat Endpoint

One endpoint takes a message or approval decisions (**neuron-tool-approval**, "One Endpoint for the Whole Conversation"). `ChatController` injects `SupportAgent $agent` and `StopSignal $stopSignal` in its constructor, like the streaming controller below:

```php
#[Route('/chat/{threadId}/messages', methods: ['POST'])]
#[IsCsrfTokenValid('chat', tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
#[IsGranted('THREAD', subject: 'threadId')]
public function message(string $threadId, Request $request): JsonResponse
{
    $body = $request->toArray();
    $agent = $this->agent->for($threadId);

    $state = isset($body['decisions'])
        ? $agent->submitApprovalDecisions($body['decisions'])->run()
        : $agent->chat(new UserMessage((string) ($body['message'] ?? '')));

    return $this->json($state->isInterrupted()
        ? ['status' => 'awaiting_approval', 'approvals' => $agent->pendingApprovals()]
        : ['status' => 'completed', 'answer' => $state->getMessage()->getContent()]);
}
```

A message while an approval is pending was refused before anything ran: 409 `{"error":"The conversation is busy.","status":"suspended"}`. A decision for an unknown call was a 400 carrying `No matching request for tool call 'call_9'.` Both come from the exception listener of Setup step 6. `$this->json()` gave the same JSON for Neuron's `JsonSerializable` objects with and without `symfony/serializer` installed.

## Streaming to the Browser (AG-UI, CopilotKit)

```php
namespace App\Controller;

use App\Neuron\Agents\SupportAgent;
use App\Neuron\StopSignal;
use Generator;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

class AgUiController extends AbstractController
{
    public function __construct(
        protected SupportAgent $agent,
        protected StopSignal $stopSignal,
        protected LoggerInterface $logger,
    ) {
    }

    #[Route('/chat/{threadId}/agui', methods: ['POST'])]
    #[IsCsrfTokenValid('chat', tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    #[IsGranted('THREAD', subject: 'threadId')]
    public function __invoke(string $threadId, Request $request): StreamedResponse
    {
        $input = $request->toArray();
        $translator = new AGUIInputTranslator();

        $adapter = new AGUIAdapter($threadId, $input['runId'] ?? null, $input['messages'] ?? [], $input['state'] ?? []);
        $agent = $this->agent->for($threadId)
            ->setStreamAdapter(fn (): AGUIAdapter => $adapter)
            ->addFrontendTools($translator->tools($input));

        $this->stopSignal->clear($threadId);
        $events = $this->isContinuation($input)
            ? $agent->submitInputs($input, $translator)->events()
            : $agent->stream(new UserMessage($this->lastUserText($input)));

        // Admission is lazy: priming turns a refusal (a pending approval, another
        // tab's run) into an HTTP status before any header is sent.
        $events->valid();

        return new StreamedResponse($this->frames($events, $adapter), 200, $adapter->getHeaders());
    }

    protected function frames(Generator $events, AGUIAdapter $adapter): Generator
    {
        try {
            yield from SSEEncoder::encode($events);
        } catch (Throwable $e) {
            $this->logger->error('Agent stream failed: '.$e->getMessage(), ['exception' => $e]);
            foreach ($adapter->error($e) as $event) {
                yield SSEEncoder::frame($event);
            }
        }
    }

    /** @param array<string, mixed> $input */
    protected function isContinuation(array $input): bool
    {
        $messages = $input['messages'] ?? [];

        return ($input['resume'] ?? []) !== [] || (end($messages)['role'] ?? null) === 'tool';
    }

    /** @param array<string, mixed> $input */
    protected function lastUserText(array $input): string
    {
        $messages = $input['messages'] ?? [];
        $last = end($messages);
        if (($last['role'] ?? null) !== 'user' || !is_string($last['content'] ?? null)) {
            throw new InputTranslationException('AG-UI input must end with a text user message or carry a continuation.');
        }

        return $last['content'];
    }
}
```

- **Prime before returning.** `stream()` and `events()` admit the run on first iteration. With `$events->valid()` in the controller, a second turn while an approval was pending became a 409 JSON response with no `data:` frame, not a `RUN_ERROR` under a 200. `AGUIAdapter` emits `RUN_STARTED` right after admission, so priming returns before the provider is called. After priming, only `SSEEncoder::encode()` may iterate the generator; never `foreach` it again.
- Everything eager throws before the headers and goes through the exception listener: a malformed seed in the `AGUIAdapter` constructor, a frontend tool shadowing a backend tool (400), a bad `resume`.
- `StreamedResponse` takes the generator as chunks and flushes each one: frames reached `curl` one at a time, as `text/event-stream; charset=UTF-8` with `X-Accel-Buffering: no`. `frames()` ends a failure after the first frame with the adapter's `RUN_ERROR` (a no-op when the segment already sent it): an empty fake queue produced exactly `RUN_STARTED`, `RUN_ERROR`.
- The route's `threadId` is the authorized one; the body's `threadId` is not used. Point the client at the route: `new HttpAgent({url: '/chat/' + threadId + '/agui', threadId, headers: {'X-CSRF-Token': 'csrf-token'}})`. Client wiring, continuation rules and `resume` payloads: **neuron-frontend-integration**.

**Vercel AI SDK (`useChat`).** The same controller shape: the thread is the chat `id`, a trailing assistant message is a continuation (`new VercelAIAdapter($last['id'], $last['parts'])` and `submitInputs($input, new VercelAIInputTranslator())`), priming gives the same 409, and there is no `hydrate()`. Read [references/streaming-endpoints.md](references/streaming-endpoints.md) for the controller and what differed when run.

**Disconnects.** When the client goes away, PHP ends the request and Neuron fails the run at once: the thread was `failed` a second after `curl --max-time 2`, and the next turn streamed normally. To keep the answer when the tab closes, call `ignore_user_abort(true)` before returning the response (the turn finished and the whole answer was saved), or run the turn in the background.

**Sessions and CSRF.** Symfony saves the session before the streamed body runs: a Stop request with the same session cookie was answered in 22 ms while a turn streamed. Read what you need from the session before returning the response. Plain controllers get no CSRF check; the attribute above needs `framework.csrf_protection.stateless_token_ids: [chat]` and a same-origin `fetch()` sending `X-CSRF-Token: csrf-token`. A cross-site POST was refused as an authentication failure (401 under HTTP Basic) before the provider was called.

**Local development.** With one built-in server worker, Stop waited 14 s for the whole answer. Run `PHP_CLI_SERVER_WORKERS=4 php -d variables_order=EGPCS -S 127.0.0.1:8000 -t public public/index.php`.

## Reload: the Conversation and its Pending Approvals

A page load reads the conversation without building the agent:

```php
#[Route('/chat/{threadId}', methods: ['GET'])]
#[IsGranted('THREAD', subject: 'threadId')]
public function show(string $threadId, Request $request, MessageStoreInterface $messages, WorkflowEngine $engine): JsonResponse
{
    $before = $request->query->get('before');
    $page = $messages->loadAll($threadId, limit: 50, before: $before);

    // The run's interrupts belong to the latest page only.
    return $this->json((new AGUIAdapter($threadId))->hydrate($page, $before === null ? $engine->inspect($threadId) : null));
}
```

It returns `{messages, interrupts}` for AG-UI's `initialMessages` and `pendingInterrupts`; a pending approval is a `confirmation` interrupt whose `id` is the call ID. Older pages pass the stored ID of the first loaded message as `?before=`. For a UI that is not AG-UI, return `'messages' => $messages->loadAll($threadId, limit: 50)` and `'approvals' => $this->agent->for($threadId)->pendingApprovals()` (the tail message is then the `tool_call` with pending tools), or read `$engine->inspect($threadId)?->interrupt` without the agent. What the client does with them: **neuron-frontend-integration** ("Reloading an AG-UI conversation") and **neuron-tool-approval** ("Rebuilding the UI after a reload").

## Background Runs with Messenger

The controller mints the run ID and dispatches; a handler runs the turn in a worker and pushes its frames through a channel.

The message is `final class RunSupportAgent` in `src/Message` with three public readonly strings, `threadId`, `runId` and `message`; the run ID is minted at dispatch, so every retry finishes the same run. Dispatch `new RunSupportAgent($threadId, (string) Uuid::v7(), $text)`: a UUID matches the reserved run ID format `^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$`. The transport's message ID will not do: a retry is a new envelope. `ResumeSupportAgent(threadId, runId, array $input)` carries an AG-UI continuation the same way.

```php
namespace App\MessageHandler;

use App\Message\ResumeSupportAgent;
use App\Message\RunSupportAgent;
use App\Neuron\Agents\SupportAgent;
use App\Neuron\RedisRelay;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\WorkflowStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

class SupportAgentHandler
{
    public function __construct(
        protected SupportAgent $agent,
        protected RedisRelay $relay,
    ) {
    }

    #[AsMessageHandler]
    public function start(RunSupportAgent $message): void
    {
        $agent = $this->agent($message->threadId, $message->runId);
        $start = ExecutionRequest::start(
            new AgentStartEvent([new UserMessage($message->message)], new AgentRunOptions(stream: true)),
            runId: $message->runId,
            recoverFailed: true, // a redelivery finishes this run from its last committed step
        );

        try {
            $agent->run($start);
        } catch (RunInFlightException $e) {
            if ($e->runId === $message->runId) {
                $this->redelivered($e);

                return;
            }
            if (!$this->isDead($e)) {
                throw new UnrecoverableMessageHandlingException('Another run holds the thread.', previous: $e);
            }
            // A reserved start never replaces another run: settle the turn that failed before this one.
            $agent->abandon($e->runId, $e->executionAttempt);
            $agent->run($start);
        }
    }

    #[AsMessageHandler]
    public function resume(ResumeSupportAgent $message): void
    {
        // Built at handling time, from the run as it is now.
        $this->agent($message->threadId, $message->runId)
            ->submitInputs($message->input, new AGUIInputTranslator())
            ->run();
    }

    protected function agent(string $threadId, string $runId): SupportAgent
    {
        return $this->agent->for($threadId)
            ->setStreamAdapter(fn (): AGUIAdapter => new AGUIAdapter($threadId, $runId))
            ->setChannel(fn (): StreamingChannelInterface => $this->relay->publisher($runId));
    }

    /** This message's own run, delivered again: wait for a live worker, or let a suspended run wait for its answer. */
    protected function redelivered(RunInFlightException $e): void
    {
        if ($e->status === WorkflowStatus::Running && $e->leaseExpiresAt !== null) {
            throw new RecoverableMessageHandlingException('The run is still executing.', previous: $e, retryDelay: max(1, $e->leaseExpiresAt - time()) * 1000);
        }
    }

    protected function isDead(RunInFlightException $e): bool
    {
        return $e->status === WorkflowStatus::Failed
            || ($e->status === WorkflowStatus::Running && $e->leaseExpiresAt !== null && $e->leaseExpiresAt <= time());
    }
}
```

| Situation, as run | What the handler did |
|---|---|
| Inference after a tool failed once (HTTP 500) | Messenger retried after 1 s; same run ID, attempt 2, tool not repeated, one question in history |
| Worker killed with `kill -9` mid-answer | The redelivery met its own run under the lease, retried after the remaining lease (27 s of a 30 s lease), then recovered and completed it |
| Redelivered while the run waits for approval | Nothing |
| A new turn while an approval is pending | Given up (`UnrecoverableMessageHandlingException`); the failure listener told the browser |
| The previous turn failed | Abandoned it, then ran. Without that, the reserved start threw `RunInFlightException` (status `failed`), where a plain `chat()` would have superseded it |
| The same message handled twice after success | Ran the turn twice (`Hi, One., Hi, Two.`): completion deletes the run's records |

Closing the last window takes `retainCompletionUntilAcknowledged()` on the copy plus `acknowledge()`, and a retained completion refuses every new turn on the thread, HTTP ones included, until acknowledged (**neuron-workflow**, "Workflow Lifecycle"). `stream: true` in `AgentRunOptions` makes the provider stream token by token to the channel; continuations follow that choice.

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    - doctrine_ping_connection # a live connection before each message (step 5)
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    redeliver_timeout: 3600 # longer than the longest turn (clocks below)
        routing:
            'App\Message\RunSupportAgent': async
            'App\Message\ResumeSupportAgent': async
```

| Clock | Rule | Otherwise, as run |
|---|---|---|
| Agent lease, 600 s (`leaseTimeout()` hook) | Above the longest single step, a whole tool batch plus one inference: it renews only at step commits | Past it the engine treats a live run as dead. A killed worker holds the thread until it expires: new turns get 409 with `Retry-After` |
| `redeliver_timeout` (Doctrine and Redis transports) | Above the longest turn, or run `php bin/console messenger:consume async --keepalive` | 8 s against a 15 s turn: a second worker claimed the message, waited on the lease, and ran the turn again once the first completed; the first worker then crashed on "Could not acknowledge redis message". With `--keepalive=2` the turn ran once |

- `doctrine_ping_connection` needs Doctrine ORM. **Never add `doctrine_transaction`** to the bus that runs agents: inside an open transaction the suspended run was invisible to other processes, and the rollback erased it.
- On the Redis transport, give each worker its own consumer name (`redis://host:6379/messages/symfony/worker-1`): a worker that starts takes every message pending under its name at once (after a kill, the new worker picked up the dead one's message immediately).
- A handler receives only the message: carry the user ID in it when tools need the user.
- `parallelToolCalls()` forks the process: enable it only in `messenger:consume` or console workers, with `beforeChild` reconnecting the child's DB and Redis clients (**neuron-agent**, "Parallel Tool Calls").

**Failure publication.** A failure inside the segment already sent `RUN_ERROR` and `stream.failed`; refusals, crashes before the segment and exhausted retries send nothing. `PublishRunFailure`, an `#[AsEventListener]` on `WorkerMessageFailedEvent`, returns while `$event->willRetry()`, then sends the frames of `(new AGUIAdapter($threadId, $runId))->error($throwable)` and `failed($throwable, $threadId)` on a fresh `$this->relay->publisher($runId)` channel. The full class is in the delivery reference below.

**Getting the frames to the browser.** For clients that need one SSE response per request (CopilotKit `HttpAgent`, `useChat`), the controller dispatches and relays the run's Redis Pub/Sub channel into a `StreamedResponse` with Neuron's `RedisChannelReader`; the `RedisChannel` from `RedisRelay::publisher()` waits up to 10 s for that subscription before its first publish, because Pub/Sub keeps nothing for late subscribers. For pages that subscribe once, a 20-line `MercureChannel extends AbstractChannel` publishes each envelope as a private Mercure update. Read [references/background-delivery.md](references/background-delivery.md) when building either: `RedisRelay`, the relay controller, `PublishRunFailure`, the Mercure channel, and what each delivered when run. Envelope and consumer: **neuron-streaming** (`references/channels.md`).

## The Stop Button

The provider hook asks `StopSignal::pull()` before every streamed event; the Stop endpoint raises the flag in a cache pool every process shares:

```php
namespace App\Neuron;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

class StopSignal
{
    public function __construct(#[Target('neuron.stop_signals')] protected CacheItemPoolInterface $flags)
    {
    }

    public function raise(string $threadId): void
    {
        $this->flags->save($this->flags->getItem($this->key($threadId))->set(true)->expiresAfter(300));
    }

    public function clear(string $threadId): void
    {
        $this->flags->deleteItem($this->key($threadId));
    }

    /** Get, then delete: one raised flag stops one answer. */
    public function pull(string $threadId): bool
    {
        $key = $this->key($threadId);
        if (!$this->flags->hasItem($key)) {
            return false;
        }
        $this->flags->deleteItem($key);

        return true;
    }

    /** PSR-6 reserves {}()/\@: in keys, which thread IDs may contain. */
    protected function key(string $threadId): string
    {
        return 'stop.'.hash('xxh128', $threadId);
    }
}
```

The Stop endpoint is `POST /chat/{threadId}/stop` with the same `#[IsCsrfTokenValid]` and `#[IsGranted('THREAD', subject: 'threadId')]` attributes as the chat routes: it calls `$this->stopSignal->raise($threadId)` and returns `new Response(status: 204)`.

- Stopped mid-answer, the stream ended with `RUN_FINISHED`, the turn completed and history kept the partial text with stop reason `stopped`. A turn running in a Messenger worker stopped the same way.
- **Clear the flag when a turn starts**, as the streaming controllers do: a flag raised after an answer ended stopped the next turn before its first word, which fails it (`The stream was stopped before the answer started.`).
- Only streamed inference stops; `chat()` without `stream: true` is not interrupted. `cache.app` lives on one host: with several, back the pool with Redis. Semantics and provider limits: **neuron-agent** (`references/providers.md`, "Stopping a streamed answer").

## Observability

With the `_instanceof` call of Setup step 5, every agent forwards its events to Symfony's PSR-14 dispatcher. Symfony matches listeners by exact class, so listen to concrete Neuron events:

```php
// App\Neuron\AgentEventsListener, with LoggerInterface $logger injected
#[AsEventListener]
public function onInference(InferenceStop $event): void
{
    $usage = $event->response->message()->getUsage();
    $this->logger->info('neuron.inference', ['input_tokens' => $usage?->inputTokens, 'output_tokens' => $usage?->outputTokens]);
}
```

- A Symfony listener on `ObservabilityEvent::class` never fired. For every event, use Neuron's `subscribe()`, which matches by `instanceof`, on the agent when it is built (for example in its constructor): `subscribe(ObservabilityEvent::class, new LogListener($logger))` on the container's agent logged `inference-stop`, `workflow-end` and the rest for every copy.
- **Listeners run inside the agent's step and must not throw**: a listener on `InferenceStop` that threw turned the turn into a 500.
- A failing channel never fails the run: with the push hub answering 401, every turn still completed. Listen to `ChannelError` to log failed deliveries. Event catalog and Neuron Cloud: **neuron-monitoring**.

## Evaluation

Evaluators are services in `src/Neuron/Evaluators` (datasets in `datasets/`), generated with `make:evaluators` (Setup step 2) and tagged by step 5, so their constructors receive the container's agent. Run them with the command of step 7:

```bash
php bin/console neuron:evaluate                    # every evaluator in src/Neuron/Evaluators
php bin/console neuron:evaluate path/to/dir -v     # another directory, evaluator names as they run
php bin/console neuron:evaluate --concurrency=4    # dataset items in 4 forked processes
php bin/console neuron:evaluate --cache            # reuse unchanged run() outputs; --fresh re-runs and rewrites
```

The exit code is 1 when any item fails or errors. The command turns its arguments into Neuron's, calls `chdir($this->projectDir)`, and returns `(new EvaluationCommand(runner: $this->runner(), resolver: $this->resolve(...)))->run($args)` with:

```php
/** Evaluators and the app's output drivers come from the container; Neuron's own drivers need no arguments. */
protected function resolve(string $class): object
{
    return $this->services->has($class) ? $this->services->get($class) : new $class();
}

protected function runner(): EvaluatorRunner
{
    return new EvaluatorRunner(
        beforeChild: function (): void {
            if ($this->connection->isConnected()) {
                $this->inherited = $this->connection->getNativeConnection();
                $this->connection->close(); // the next query opens this child's own connection
            }
        },
        afterChild: $this->connection->close(...),
    );
}
```

- **The project root, from anywhere.** Neuron reads `evaluation.php` and its relative paths from the working directory. Launched from `/tmp`, the command found the evaluators, wrote `var/evaluation.json` and cached into `var/evaluation/cache`; without the `chdir()`, `evaluation.php` was ignored and the cache landed in `/tmp/.neuron`. A path argument resolves against the launch directory (`Path::makeAbsolute($path, getcwd())`).
- **The child hooks.** `$services` is an `#[AutowireLocator('neuron.evaluation')]`, `$connection` Doctrine's `Connection`, and `$inherited` a property that keeps the child's copy of the parent's connection alive. With `--concurrency=4` on MySQL and an evaluator querying in `setUp()` and `run()`, each child got its own connection and the parent's still wrote the report afterwards. Without hooks the children shared it and 2 of 8 items failed with `2006 MySQL server has gone away`; a hook that only called `close()` destroyed the inherited PDO, which closed the parent's connection. Treat any other connection opened before the fork (a Redis client) the same way.

An evaluator injects the agent in `public function __construct(protected SupportAgent $agent)`, which must call `parent::__construct()` (without it every item errored with `Typed property NeuronAI\Evaluation\BaseEvaluator::$ruleExecutor must not be accessed before initialization`), and runs each item on its own copy:

```php
/** The app's agent on a fresh thread, with stores that never touch the conversation tables. */
protected function agent(): SupportAgent
{
    return $this->agent->for(UniqueIdGenerator::generateId('eval_'))
        ->setMessageStore(new InMemoryMessageStore())
        ->setPersistence(new InMemoryPersistence());
}
```

- **Keep evaluations out of the conversation tables.** Setters win over the hooks and stay on the copy: after runs on MySQL, `chat_messages` and `workflow_store` were empty and the container's agent still had no stores; a copy without the setters wrote its `eval_…` thread to `chat_messages`. Tools still run for real: the approved refund executed.
- **Multi-turn:** pass the same copy to `Conversation::make($this->agent())`. `Conversation` binds its own copy with `for()`, which kept the in-memory stores: the conversation's 6 messages were in the evaluator's `InMemoryMessageStore`.
- `vendor/bin/neuron evaluation --autoload-file=…` with a `$container->get()` resolver in `evaluation.php` fails: evaluators are private services (`has been removed or inlined when the container was compiled`). Use the console command.
- CI: run `php bin/console neuron:evaluate --cache` and fail the job on its exit code; persist `var/evaluation/cache` and schedule a `--fresh` run.

Read [references/evaluation.md](references/evaluation.md) for the command and evaluator classes, a multi-turn evaluator, why evaluators live in `src/` rather than an `autoload-dev` directory, and what each run showed. Evaluators, datasets, assertions, judges and caching: **neuron-evaluation**.

## Testing

Fake the provider on the shared agent before the first request, and keep one kernel for the whole test so the fake survives: a `WebTestCase` whose `setUp()` calls `$this->client->disableReboot()` right after `static::createClient()`, and a helper that runs `static::getContainer()->get(SupportAgent::class)->setAiProvider($fake)`. Messenger uses the `in-memory://` transport under `when@test`, and the client logs in an app user with `loginUser()` and sends `Sec-Fetch-Site: same-origin` plus `X-CSRF-Token: csrf-token`, as a same-origin `fetch()` does.

- A `StreamedResponse` body is in `getInternalResponse()->getContent()`; `getResponse()->getContent()` is `false`.
- `setAiProvider()` on the shared agent reaches every `for()` copy the controllers make, and the `FakeAIProvider` instance holds the assertions. Symfony's `MockHttpClient` never sees provider traffic: providers use Neuron's own HTTP client.
- The stores run against the test database: build the schema with the app's migrations in `tests/bootstrap.php` and empty `chat_messages` and `workflow_store` in `setUp()`.
- Call handlers directly after replacing `RedisRelay` in the test container with a subclass whose `publisher()` returns a `FakeChannel`, then `$channel->assertSuspended()` or `assertCompleted()`. Dispatch tests read `static::getContainer()->get('messenger.transport.async')->getSent()`.

Read [references/testing.md](references/testing.md) for the base class (with the SSE `frames()` parser), the `when@test` transport and the bootstrap, approval tests over JSON and AG-UI, and the handler tests (suspend and resume, a turn given up, redelivery after success). Fakes and assertions: **neuron-test**.

## Pitfalls

- **`setThreadId()` on the injected agent**: the second request or message throws. Use `for()`.
- **A promoted constructor property named like an Agent property** (`$messageStore`, `$persistence`, …): fatal type error.
- **A PDO captured once** (a shared store, a store built in a constructor): dead after the server drops the connection. Use `shared: false` stores plus `doctrine_ping_connection`.
- **`doctrine_transaction` on the agents' bus**: runs invisible while the handler runs, erased on rollback.
- **`redeliver_timeout` below the longest turn** without `--keepalive`: the same turn runs twice.
- **A reserved start after a failed turn**: refused until the failed run is abandoned.
- **Not priming the generator**: refusals become `RUN_ERROR` frames under a 200 instead of 409s.
- **Iterating the generator without the try/catch of `frames()`**: a failure after the headers escapes instead of ending with `RUN_ERROR`.
- **A stale stop flag**: clear it when a turn starts.
- **`framework.exceptions` for Neuron exceptions**: no message, no `Retry-After`, first match wins.
- **`php -S` without `-d variables_order=EGPCS`**: `.env` placeholders shadow real env vars. One server worker serialises Stop behind the stream.
- **A Symfony listener on `ObservabilityEvent`**: never called. **A throwing listener**: fails the turn.
- **MySQL/MariaDB without strict mode**: `PersistenceException` at the first write.
- **A Doctrine diff without `schema_filter`**, or a `BIGSERIAL` id on PostgreSQL: the diff drops Neuron's tables or sequence.
- **Entities in workflow state**: state is serialized at every step; keep IDs and load the entity in the tool.
- **Evaluating the container's agent without in-memory stores**: evaluation threads land in `chat_messages`.
- **`--concurrency` without the child hooks, or a hook that closes the inherited connection**: items fail with `2006 MySQL server has gone away`, or the parent loses its connection.
- **Running Neuron's `EvaluationCommand` outside the project root without `chdir()`**: `evaluation.php` is ignored and the cache lands in the launch directory.

## Related

- **neuron-agent** — the agent class, hooks, providers, `for()` and history, parallel tool calls, "Stopping a streamed answer".
- **neuron-frontend-integration** — AG-UI, CopilotKit and `useChat` client wiring, continuation rules, reload semantics.
- **neuron-tool-approval** — gating tools, decision maps, pending approvals.
- **neuron-streaming** — adapters, channels, the channel envelope and the `@neuron-core/streaming` consumer.
- **neuron-workflow** — persistence, leases, reserved run IDs, retained completion.
- **neuron-tool** — writing tools and toolkits with dependencies.
- **neuron-evaluation** — evaluators, datasets, assertions and judges, `Conversation` and `Trajectory`, output drivers, `--cache`.
- **neuron-test** — `FakeAIProvider`, `FakeChannel` and their assertions.
- **neuron-monitoring** — observability events, logging, Neuron Cloud.
