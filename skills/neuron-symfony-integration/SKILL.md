---
name: neuron-symfony-integration
description: Integrate Neuron AI agents into a Symfony 8 app, from packages, env keys, a Doctrine migration for the Neuron tables and services.yaml wiring to a shared agent bound per request with for(), a thread voter, JSON and AG-UI (CopilotKit) or useChat streaming with StreamedResponse, reload with pending approvals, Messenger background runs relayed over Redis or Mercure, a Stop button, observability, bin/console neuron:evaluate and WebTestCase tests. Use this skill whenever the user mentions Symfony together with Neuron, an AI assistant or chat in a Symfony app, streaming an agent from a Symfony controller, running an agent in Messenger or a worker, showing a conversation after a reload, a stop-generating button, or evaluating or testing agents in Symfony. Also trigger for SQLMessageStore or DatabasePersistence in services.yaml, AutowireServiceClosure, AsMessageHandler with ExecutionRequest::start, WorkerMessageFailedEvent, RedisChannelReader, MercureChannel, StoppableHttpClient, or KernelBrowser disableReboot.
---

# Neuron AI Symfony Integration

This skill wires Neuron into a Symfony 8 application: first the foundations every feature stands on (packages, layout, keys, tables, container wiring, error mapping, the evaluation command), then the features users ask for, from the agent and the controllers that run it inside a request to the Messenger handler that runs it in a worker, the Stop button, observability, evaluation and tests. Every snippet comes from a reference app (Symfony 8.1, Doctrine DBAL 4, Messenger on Redis) that ran on SQLite, MySQL 8.4, MariaDB 11.7 and PostgreSQL 17. What the agent, the protocols, the approval flow and evaluators mean is owned by the skills under Related; this one owns the Symfony shape.

## The Mental Model

**The container holds definitions; every request or message works on a bound copy.** The agent is a shared service. A controller or handler calls `$this->agent->for($threadId)`, which returns a copy bound to the thread and leaves the shared agent unbound. Per-request pieces (stream adapter, channel, frontend tools) go on the copy. Never call `setThreadId()` on the injected agent: the second thread served by the same container (the next message in a worker, the next request under a worker runtime or a test client that does not reboot) throws `WorkflowException: This workflow is bound to 't-a' and cannot be re-pointed to 't-b'.`

**Hooks run once per copy, so anything holding a connection is resolved there.** The agent pulls its stores through service closures of `shared: false` services built on Doctrine's current PDO. The shared agent never resolves them; each copy gets its own instances.

| Piece | Where | Lifetime |
|---|---|---|
| `SupportAgent`: provider hook, instructions, `tools()` hook | `src/Neuron/Agents`, autowired | Shared service, never bound |
| Bound copy + adapter, channel, frontend tools | Controller action, message handler | One request or one message |
| Tools, scoped to the thread's customer | Built by `tools()` on each copy | One copy |
| `MessageStoreInterface`, `PersistenceInterface`, `neuron.pdo` | `config/packages/neuron.yaml` | `shared: false`: new per resolution, current PDO |
| `WorkflowEngine` | Autowired, `shared: false` | Reads runs without building the agent |
| Provider wrapped in `StoppableHttpClient` | Agent `provider()` hook | Built for every execution segment |
| Stop flags | Cache pool `neuron.stop_signals` | Raised by Stop, cleared by the next turn (5 minutes at most) |
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

`spatie/fork` (with `ext-pcntl` and `ext-sockets`, plus `ext-posix` so forked children end with `SIGKILL`) runs evaluation items in parallel; without it `--concurrency` prints a notice and runs sequentially. `--dev` is enough for evaluations; workers that use `parallelToolCalls()` need it without `--dev`, or their tool calls run one after another. The features below also use `doctrine/doctrine-bundle` and `doctrine/doctrine-migrations-bundle` with a `pdo_*` driver, `symfony/security-bundle`, `symfony/uid` (thread and run IDs), `symfony/messenger` with a transport (`symfony/redis-messenger` here), and for delivery `ext-redis` or `symfony/mercure-bundle`. They assume a Doctrine `App\Entity\User` with an integer ID behind the firewall: `php bin/console make:user`, then `#[ORM\Table(name: 'app_user')]` on the entity (`user` is reserved on PostgreSQL).

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
MESSENGER_TRANSPORT_DSN=redis://127.0.0.1:6379/messages
###< neuron ###
```

`MESSENGER_TRANSPORT_DSN` replaces the Messenger recipe's `doctrine://default?auto_setup=0`, which needs `symfony/doctrine-messenger` (without it `messenger:consume` stopped with `No transport supports Messenger DSN "doctrine://default"`). Give each worker its own consumer name (Background Runs).

- The env value is read when the service is built, not when the container compiles: the same compiled container picked up a new `ANTHROPIC_API_KEY` after a restart, with no `cache:clear`.
- Never read `$_ENV` or `getenv()` in app classes.
- Dotenv skips a variable only when it is already in `$_SERVER` or `$_ENV`. PHP's built-in server with `variables_order=GPCS` puts real env vars in neither, so the `.env` placeholder won and the provider sent an empty key. Start it with `php -d variables_order=EGPCS -S …`, or keep local values in `.env.local`.

### 4. Database tables

One Doctrine migration creates both tables with the DDL core documents, per platform: `VARBINARY` thread and message IDs and `ascii_bin` keys on MySQL and MariaDB, an identity column on PostgreSQL, plain types on SQLite. MySQL and MariaDB need strict SQL mode: on a session without it the first write threw `PersistenceException: Workflow persistence requires MySQL strict SQL mode to prevent truncated records.` The `schema_filter` of step 5 keeps Doctrine's diff away from them.

Read [references/database.md](references/database.md) when writing the migration: the full class (with `isTransactional(): false`, because MySQL and MariaDB commit DDL implicitly), why PostgreSQL needs `GENERATED BY DEFAULT AS IDENTITY` instead of `BIGSERIAL`, and how it was proven on the four platforms.

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
use Psr\Log\LogLevel;
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
            // setResponse() stops the event: Symfony's own exception logger never sees these.
            $this->logger->log($response->getStatusCode() >= 500 ? LogLevel::ERROR : LogLevel::NOTICE, $e->getMessage(), ['exception' => $e]);
            $event->setResponse($response);
        }
    }
}
```

- The most specific class comes first: `PersistenceException` and `RunInFlightException` extend `WorkflowException`. Only `InputTranslationException`'s message is written for clients; every mapped exception is logged with its real message, 4xx at notice and 5xx at error (a 409 during a pending approval logged `[notice] Cannot ignite a new run for workflow ID …`).
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
use App\Repository\OrderRepository;
use Closure;
use LogicException;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowStatus;
use Psr\Log\LoggerInterface;
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
        protected OrderRepository $orders,
        protected LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function provider(): AIProviderInterface
    {
        return new Anthropic(
            key: $this->anthropicKey,
            model: $this->anthropicModel,
            httpClient: new StoppableHttpClient(
                client: $this->transport(),
                shouldStop: fn (): bool => $this->stopSignal->raised($this->getThreadId()),
            ),
        );
    }

    /** The provider's HTTP client: a test subclass scripts a streamed answer here. */
    protected function transport(): HttpClientInterface
    {
        return new CurlHttpClient();
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(background: ['You are the support assistant of an online shop.']);
    }

    protected function tools(): array
    {
        return [
            new OrderStatusTool($this->orders, $this->customerId()),
            new RefundOrderTool($this->orders, $this->logger, $this->customerId()),
        ];
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

    /** A turn that failed after it stored its question blocks the next one: finish it first. */
    public function recoverFailedTurn(): void
    {
        $run = $this->inspect();
        if ($run?->status === WorkflowStatus::Failed) {
            $this->run(ExecutionRequest::resume(expectedRunId: $run->runId, expectedExecutionAttempt: $run->executionAttempt));
        }
    }

    /** The server names threads "user-{id}-{uuid}"; a worker has no security token to ask. */
    protected function customerId(): int
    {
        if (preg_match('/^user-(\d+)-/', (string) $this->getThreadId(), $match) !== 1) {
            throw new LogicException("Thread '{$this->getThreadId()}' names no customer.");
        }

        return (int) $match[1];
    }
}
```

- **Each agent owns its provider.** The hook builds it per segment from injected env values: each agent picks its model, no provider instance is shared between agents, and the Stop predicate knows the thread. Do not register a global `AIProviderInterface`; tests use `setAiProvider()`, which wins over the hook and reaches every copy. `transport()` is the seam a real Stop test replaces (Testing).
- **Promoted properties must not reuse Agent property names**: a fatal error when the types differ (`$messageStore`, `$persistence`, `$channel`: `Type of …::$messageStore must be ?NeuronAI\Chat\History\MessageStoreInterface`), a silent override when they match (a promoted `$provider` bypasses the `provider()` hook, and with it the Stop client). Always call `parent::__construct()`.
- **`recoverFailedTurn()` finishes a failed turn before the next one.** A turn that failed after it stored its question (the client disconnected or the provider failed after a tool step, an approved tool threw) makes the next `chat()` or `stream()` throw `ChatHistoryException` ("Invalid message sequence…": the dangling question, or an approved tool call), and `abandon()` refuses a history that ends in a tool call. Every endpoint that starts a turn calls it first, on a copy with no stream adapter or channel: an adapter factory returns the same instance for every segment, and the relay reads only the first segment. The failed turn's answer lands in history before the new question; if finishing it fails again, so does the new message, until `resetConversation()` clears the thread. It finishes a turn that stored nothing too (a provider error at the first inference, a Stop before the first word): a user who sends the same question again gets it answered twice. It only sees failed runs: a request killed outright (SIGKILL, OOM, `request_terminate_timeout`) leaves its run `running`, and once its lease expires the next message supersedes it and fails the same way when the question was stored, until `resetConversation()`.

### Tools that act for the user

Tools run inside the agent's copy, in a controller or in a Messenger worker, and a worker has no security token: a tool never reads `Security`. `tools()` builds them per copy for the customer the thread names (`customerId()` reads the server-minted `user-{id}-{uuid}`), from injected dependencies such as a repository or DBAL's `Connection`. `OrderStatusTool` takes `(OrderRepository $orders, int $customerId)` and looks orders up with `findForCustomer($reference, $customerId)`; `RefundOrderTool` also takes the logger and requires approval through its `approvalPolicy()`. Never set the user on a shared tool service per request: the agent and its injected services serve every request and message of the process. On Alice's thread, the lookup and an approved refund of Bob's order both answered `The customer has no order B-1.`, and the order stayed `shipped`. Evaluations pin their tools to a fixture customer (Evaluation). Writing tools and gating them: **neuron-tool** and **neuron-tool-approval**.

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
use NeuronAI\Chat\Messages\UserMessage;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/chat/{threadId}/messages', methods: ['POST'])]
#[IsCsrfTokenValid('chat', tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
#[IsGranted('THREAD', subject: 'threadId')]
public function message(string $threadId, Request $request): JsonResponse
{
    $body = $request->toArray();
    $agent = $this->agent->for($threadId);

    $this->stopSignal->clear($threadId);
    if (isset($body['decisions'])) {
        $state = $agent->submitApprovalDecisions($body['decisions'])->run();
    } else {
        $agent->recoverFailedTurn();
        $state = $agent->chat(new UserMessage((string) ($body['message'] ?? '')));
    }

    return new JsonResponse($state->isInterrupted()
        ? ['status' => 'awaiting_approval', 'approvals' => $agent->pendingApprovals()]
        : ['status' => 'completed', 'answer' => $state->getMessage()->getContent()]);
}
```

This copy has no stream adapter or channel, so it finishes a failed turn itself before its own; the Stop flag is cleared first, because a flag still raised would stop that recovery too (The Stop Button). A message while an approval is pending was refused before anything ran: 409 `{"error":"The conversation is busy.","status":"suspended"}`. A decision for an unknown call was a 400 carrying `No matching request for tool call 'call_9'.` Both come from the exception listener of Setup step 6.

Return Neuron's objects with `new JsonResponse()`: with `symfony/serializer` installed, `$this->json()` turned a tool call without arguments, `"inputs":{}`, into `"inputs":[]` in `pendingApprovals()` and in stored messages. `$this->json($data, context: ['preserve_empty_objects' => true])` keeps `{}` too.

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

        $adapter = new AGUIAdapter(threadId: $threadId, runId: $input['runId'] ?? null, messages: $input['messages'] ?? [], state: $input['state'] ?? []);
        $agent = $this->agent->for($threadId)
            ->setStreamAdapter(fn (): AGUIAdapter => $adapter)
            ->addFrontendTools($translator->tools($input));

        $this->stopSignal->clear($threadId);
        if ($this->isContinuation($input)) {
            $events = $agent->submitInputs($input, $translator)->events();
        } else {
            $message = new UserMessage($this->lastUserText($input));
            // On its own copy: this one's adapter belongs to the new turn.
            $this->agent->for($threadId)->recoverFailedTurn();
            $events = $agent->stream($message);
        }

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
- Everything eager throws before the headers and goes through the exception listener: a malformed seed in the `AGUIAdapter` constructor, a frontend tool shadowing a backend tool (400), a bad `resume`, a failed turn that cannot be finished.
- A new turn finishes a failed one first, on its own copy: `$agent` carries this response's adapter, which must see only the new turn. After a disconnect past a tool step, the next message streamed only its own answer, and history held the old question, its tool call and result, the recovered answer, then the new turn.
- `StreamedResponse` takes the generator as chunks and flushes each one: frames reached `curl` one at a time, as `text/event-stream; charset=UTF-8` with `X-Accel-Buffering: no`. `frames()` ends a failure after the first frame with the adapter's `RUN_ERROR` (a no-op when the segment already sent it): an empty fake queue produced exactly `RUN_STARTED`, `RUN_ERROR`.
- The route's `threadId` is the authorized one; the body's `threadId` is not used. Client wiring, continuation rules and `resume` payloads: **neuron-frontend-integration**.

**The browser client (AG-UI or CopilotKit).** Point the AG-UI `HttpAgent` at the route from the page itself; CopilotKit v2 can drive the same agent without a runtime (the Vue `CopilotKitProvider` takes it in `self-managed-agents`). A same-origin `fetch()` sends the session cookie and `Sec-Fetch-Site`; add `X-CSRF-Token`, and seed a reloaded page from the reload endpoint (Reload, below):

```ts
import { HttpAgent } from "@ag-ui/client";

const { messages, interrupts } = await (await fetch(`/chat/${threadId}`)).json();
const agent = new HttpAgent({ url: `/chat/${threadId}/agui`, threadId, headers: { "X-CSRF-Token": "csrf-token" }, initialMessages: messages });
agent.pendingInterrupts = interrupts;
```

Run from Node with `@ag-ui/client` 0.0.59 and a session cookie (plus the `Origin` a browser adds), the approval turn, the reload and the `resume` after it worked.

**CopilotKit's runtime bridge** calls the route server to server, with neither the browser's cookie nor its same-origin headers. Forwarding the session cookie and `X-CSRF-Token` was a 401 (the stateless CSRF check found no origin); adding the app's `Origin` made it a 200. Forward those from the bridge, or authenticate the bridge with a token on a firewall without the CSRF check, and authorize the thread either way. The bridge then owns the reload (**neuron-frontend-integration**, "CopilotKit").

**Vercel AI SDK (`useChat`).** The same controller shape: the thread is the chat `id`, a trailing assistant message is a continuation (`new VercelAIAdapter(messageId: $last['id'], parts: $last['parts'] ?? [])` and `submitInputs($input, new VercelAIInputTranslator())`), a new turn finishes a failed one first, priming gives the same 409, and there is no `hydrate()`. Read [references/streaming-endpoints.md](references/streaming-endpoints.md) for the controller and what differed when run.

**Disconnects.** When the client goes away, PHP ends the request and Neuron fails the run at once: the thread was `failed` a second after `curl --max-time 2`. The next message first finishes that turn through `recoverFailedTurn()`, then answers. To keep the answer when the tab closes, call `ignore_user_abort(true)` before returning the response (the turn finished and the whole answer was saved), or run the turn in the background.

**Sessions and CSRF.** Symfony saves the session before the streamed body runs: a Stop request with the same session cookie was answered in 22 ms while a turn streamed. Read what you need from the session before returning the response. Plain controllers get no CSRF check; the attribute above needs `framework.csrf_protection.stateless_token_ids: [chat]` and a same-origin `fetch()` sending `X-CSRF-Token: csrf-token`. A cross-site POST was refused as an authentication failure (401 under HTTP Basic) before the provider was called.

**Local development.** With one built-in server worker, Stop waited 14 s for the whole answer. Run `PHP_CLI_SERVER_WORKERS=4 php -d variables_order=EGPCS -S 127.0.0.1:8000 -t public public/index.php`.

## Reload: the Conversation and its Pending Approvals

A page load reads the conversation without building the agent:

```php
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Workflow\WorkflowEngine;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/chat/{threadId}', methods: ['GET'])]
#[IsGranted('THREAD', subject: 'threadId')]
public function show(string $threadId, Request $request, MessageStoreInterface $messages, WorkflowEngine $engine): JsonResponse
{
    $before = $request->query->get('before');
    $page = $messages->loadAll($threadId, limit: 50, before: $before);

    // The run's interrupts belong to the latest page only.
    return new JsonResponse((new AGUIAdapter($threadId))->hydrate($page, $before === null ? $engine->inspect($threadId) : null));
}
```

It returns `{messages, interrupts}` for AG-UI's `initialMessages` and `pendingInterrupts`; a pending approval is a `confirmation` interrupt whose `id` is the call ID. Older pages pass the stored ID of the first loaded message as `?before=`. For a UI that is not AG-UI, return `'messages' => $messages->loadAll($threadId, limit: 50)` and `'approvals' => $this->agent->for($threadId)->pendingApprovals()` (the tail message is then the `tool_call` with pending tools), or read `$engine->inspect($threadId)?->interrupt` without the agent. What the client does with them: **neuron-frontend-integration** ("Reloading an AG-UI conversation") and **neuron-tool-approval** ("Rebuilding the UI after a reload").

## Background Runs with Messenger

The controller mints the run ID and dispatches; a handler runs the turn in a worker and pushes its frames through a channel.

The message is `final class RunSupportAgent` in `src/Message` with public readonly `threadId`, `runId`, `message` and `array $input = []`, the browser's `RunAgentInput`: the worker seeds its adapter with the client's `runId`, `messages` and `state`, and adds the browser's tools from it. Without the seed, the `MESSAGES_SNAPSHOT` sent at an approval prompt was empty, and the AG-UI client replaced its transcript with it. The run ID is minted at dispatch, so every retry finishes the same run: dispatch `new RunSupportAgent($threadId, (string) Uuid::v7(), $text, $input)`, a UUID matching the reserved run ID format `^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$`. The transport's message ID will not do: a retry is a new envelope. `ResumeSupportAgent(threadId, runId, array $input)` carries an AG-UI continuation of the suspended run, whose ID it takes (`$agent->for($threadId)->inspect()->runId`), so a retry recognises its own run.

`SupportAgentHandler` handles both messages on the injected agent, with `RedisRelay` as the channel factory. Read [references/background-runs.md](references/background-runs.md) when writing it: the messages, the full class, and what each situation did when run. What it does:

- **`start()`** runs `ExecutionRequest::start(new AgentStartEvent(messages: [new UserMessage($message->message)], options: new AgentRunOptions(stream: true)), runId: $message->runId, recoverFailed: true)` on a copy whose adapter factory seeds a new `AGUIAdapter(threadId:, runId:, messages:, state:)` from the input for every segment, whose channel comes from `RedisRelay::publisher($runId)`, and which carries the browser's tools (`addFrontendTools()`). A redelivery finishes the same run from its last committed step: after an HTTP 500 past a tool, the retry did not run the tool again and history held one question.
- **Refused by its own run**: still executing under its lease → `RecoverableMessageHandlingException` with the remaining lease as `retryDelay` (after a `kill -9`, the redelivery waited 27 s of a 30 s lease, then recovered and completed the run); waiting for an approval → nothing.
- **Refused by another message's dead run** (failed, or running past its lease): finish it with `ExecutionRequest::resume(expectedRunId: $e->runId, expectedExecutionAttempt: $e->executionAttempt)` on `$this->agent->for($threadId)`, a copy without adapter or channel, then start again. Never `abandon()` it: `Agent::abandon()` throws when the history ends in a tool call, and when it succeeds the next start still fails with `ChatHistoryException`. A refund approved while the payment API threw was executed once, when the next turn finished that run. A pending approval or a live turn → `UnrecoverableMessageHandlingException` at once, instead of burning retries.
- **`resume()`** stages the answers at handling time, from the run as it is then. A retry that finds its own run failed with no interrupt (the approved tool ran, then the answer failed) finishes that run instead, because staging again throws `There is no current interruption to answer`; an approved tool that threw leaves the approval pending, so its retries stage it again and re-run the tool.
- **Redelivery after success runs the turn again**: completion deletes the run's records, so a reserved start finds nothing to match (`Hi, One., Hi, Two.`). A failed turn's retry still queued when the next turn finishes that turn asks its question again.

`retainCompletionUntilAcknowledged()` on the copy narrows only this message's own redelivery after success, and never belongs on the copy that finishes another message's run. It works only if the handler calls `acknowledge()` after `run()` and also when a redelivery meets its own completed run: a completion never acknowledged refused the next turn on the thread, HTTP ones included (**neuron-workflow**, "Workflow Lifecycle"). `stream: true` in `AgentRunOptions` makes the provider stream token by token to the channel; continuations follow that choice.

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
| Agent lease, 600 s (`leaseTimeout()` hook) | Above the longest single step, one inference or one whole tool batch: it renews only at step commits | Past it the engine treats a live run as dead. A killed worker holds the thread until it expires: new turns get 409 with `Retry-After` |
| `redeliver_timeout` (Doctrine and Redis transports) | Above the longest turn, or run `php bin/console messenger:consume async --keepalive` | 8 s against a 15 s turn: a second worker claimed the message, waited on the lease, and ran the turn again once the first completed; the first worker then crashed on "Could not acknowledge redis message". With `--keepalive=2` the turn ran once |

- `doctrine_ping_connection` needs Doctrine ORM. **Never add `doctrine_transaction`** to the bus that runs agents: inside an open transaction the suspended run was invisible to other processes, and the rollback erased it.
- On the Redis transport, give each worker its own consumer name (`redis://host:6379/messages/symfony/worker-1`): a worker that starts takes every message pending under its name at once (after a kill, the new worker picked up the dead one's message immediately).
- A handler receives only the message and has no security token: the tools take their customer from the thread (Tools that act for the user).
- `parallelToolCalls()` forks the process: enable it only in `messenger:consume` or console workers, with `spatie/fork` installed without `--dev` and `beforeChild` reconnecting the child's DB and Redis clients (**neuron-agent**, "Parallel Tool Calls").

**Failure publication.** A run that fails inside its segment sends `RUN_ERROR` and `stream.failed` itself, on every attempt; refusals, crashes before the segment and attempts refused at admission send nothing. `PublishRunFailure`, an `#[AsEventListener]` on `WorkerMessageFailedEvent`, returns while `$event->willRetry()` and when the message's own run is `failed` (its segment reported it, and a second pair would show the browser a second error), then sends the frames of `(new AGUIAdapter(threadId: $threadId, runId: $runId))->error($throwable)` and `failed($throwable, $threadId)` on a fresh `$this->relay->publisher($runId)` channel. A turn whose answer failed on all four deliveries reached the relay as one `RUN_ERROR`, and so did a turn refused while an approval was pending. The full class is in the delivery reference below.

**Getting the frames to the browser.** For AG-UI clients that need one SSE response per request (the `HttpAgent`, or CopilotKit driving it in the browser), the controller dispatches and relays the run's Redis Pub/Sub channel into a `StreamedResponse` with Neuron's `RedisChannelReader`; the `RedisChannel` from `RedisRelay::publisher()` waits up to 10 s for that subscription before its first publish, because Pub/Sub keeps nothing for late subscribers. The relay endpoint accepts what the synchronous one does (new turns, `resume`, trailing tool messages, browser tools), and the official client ended every step of a turn, approval, reload and browser-tool round trip with the same transcript on both. It speaks AG-UI only. For pages that subscribe once, a 20-line `MercureChannel extends AbstractChannel` publishes each envelope as a private Mercure update. Read [references/background-delivery.md](references/background-delivery.md) when building either: `RedisRelay`, the relay controller, `PublishRunFailure`, the Mercure channel, and what each delivered when run. Envelope and consumer: **neuron-streaming** (`references/channels.md`).

## The Stop Button

The provider hook asks `StopSignal::raised()` before every streamed event; the Stop endpoint raises the flag in a cache pool every process shares, and every endpoint that starts a turn clears it first:

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

    /**
     * Asked before every streamed event; only the next turn clears the flag, so a retry stays stopped.
     * Not hasItem(): on the filesystem pool a worker kept seeing a cleared flag (PHP's stat cache).
     */
    public function raised(string $threadId): bool
    {
        return $this->flags->getItem($this->key($threadId))->isHit();
    }

    /** PSR-6 reserves {}()/\@: in keys, which thread IDs may contain. */
    protected function key(string $threadId): string
    {
        return 'stop.'.hash('xxh128', $threadId);
    }
}
```

The Stop endpoint is `POST /chat/{threadId}/stop` with the same `#[IsCsrfTokenValid]` and `#[IsGranted('THREAD', subject: 'threadId')]` attributes as the chat routes: it calls `$this->stopSignal->raise($threadId)` and returns `new Response(status: 204)`.

- Stopped mid-answer, the stream ended with `TEXT_MESSAGE_END` and `RUN_FINISHED`, the turn completed and history kept the partial text with stop reason `stopped`, in the request and in a Messenger worker alike.
- **The predicate only reads the flag**, so it stays raised until the next turn clears it (5 minutes at most): a queued turn stopped before its first word failed on all four deliveries instead of generating the answer on a retry. A Stop pressed before the first word fails the turn; the next message then finishes that turn before answering (`recoverFailedTurn()`, or the handler), so the stopped answer is generated at that point.
- **Clear the flag before finishing a failed turn**: a flag still raised stopped that recovery too, before its first word.
- **`getItem()->isHit()`, not `hasItem()`**: on the filesystem pool, a worker kept seeing a flag the next turn had cleared, and stopped that turn's recovery on every delivery. `hasItem()` reads PHP's file-stat cache, which keeps the last file checked and can go stale across deliveries (it answered false only after `clearstatcache()`); `getItem()` opens the file and saw the deletion at once.
- Only streamed inference stops; `chat()` without `stream: true` is not interrupted. `cache.app` lives on one host: with several, back the pool with Redis. Semantics and provider limits: **neuron-agent** (`references/providers.md`, "Stopping a streamed answer").

Wire the chat UI's stop control to the endpoint and keep reading the stream until `RUN_FINISHED`:

```ts
// The stream then ends with TEXT_MESSAGE_END and RUN_FINISHED; history keeps the text so far.
const stop = () => fetch(`/chat/${threadId}/stop`, { method: "POST", headers: { "X-CSRF-Token": "csrf-token" } });
```

Never abort the fetch to stop (the AG-UI client's `abortRun()`): on the in-request endpoint that is a disconnect, which failed the run and lost the partial answer; on the relay endpoint it never reaches the worker, which finished the whole answer.

## Observability

With the `_instanceof` call of Setup step 5, every agent forwards its events to Symfony's PSR-14 dispatcher. Symfony matches listeners by exact class, so listen to concrete Neuron events:

```php
namespace App\Neuron;

use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Workflow\Observability\ChannelError;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Symfony matches listeners by exact class: listen to concrete Neuron events. Never throw here:
 * an exception on an event a node emits (InferenceStop) fails the turn.
 */
class AgentEventsListener
{
    public function __construct(protected LoggerInterface $logger)
    {
    }

    #[AsEventListener]
    public function onInference(InferenceStop $event): void
    {
        $usage = $event->response->message()->getUsage();
        $this->logger->info('neuron.inference', ['input_tokens' => $usage?->inputTokens, 'output_tokens' => $usage?->outputTokens]);
    }

    #[AsEventListener]
    public function onChannelError(ChannelError $event): void
    {
        $this->logger->warning('neuron.channel_error: '.$event->exception->getMessage());
    }
}
```

- A Symfony listener on `NeuronAI\Observability\ObservabilityEvent` never fired. For every event, use Neuron's `subscribe()`, which matches by `instanceof`, on the agent when it is built (for example in its constructor): `subscribe(ObservabilityEvent::class, new LogListener($logger))` (`NeuronAI\Observability\LogListener`) on the container's agent logged `inference-stop`, `workflow-end` and the rest for every copy.
- **Listeners must not throw.** On an event a node emits (`InferenceStop`, `ToolCalling`, `ToolCalled`, `MessageSaving`, …) a listener that threw turned the turn into a 500; on the engine's lifecycle events (`WorkflowStart`, `WorkflowNodeEnd`, `WorkflowEnd`) the turn completed and the failure was reported as a `WorkflowError`.
- Only delivery failures are isolated: with the push hub answering 401 or the channel throwing on send, every turn still completed, and `NeuronAI\Workflow\Observability\ChannelError` carried the failure to the listener above. A channel factory that throws fails the run: `RedisRelay::publisher()` connects inside it, and a worker whose Redis was unreachable failed the run with `Redis connection failed: Connection refused`. Event catalog and Neuron Cloud: **neuron-monitoring**.

## Evaluation

Evaluators are services in `src/Neuron/Evaluators` (datasets in `datasets/`), generated with `make:evaluators` (Setup step 2) and tagged by step 5, so their constructors receive the container's agent. Run them with the command of step 7:

```bash
php bin/console neuron:evaluate                    # every evaluator in src/Neuron/Evaluators
php bin/console neuron:evaluate path/to/dir -v     # another directory, evaluator names as they run
php bin/console neuron:evaluate --concurrency=4    # dataset items in 4 forked processes
php bin/console neuron:evaluate --cache            # reuse unchanged run() outputs; --fresh re-runs and rewrites
```

The exit code is 1 when any item fails or errors. The command turns its arguments into Neuron's, calls `chdir($this->projectDir)`, and runs Neuron's `EvaluationCommand` with a resolver that takes evaluators and the app's output drivers from an `#[AutowireLocator('neuron.evaluation')]` and an `EvaluatorRunner` whose child hooks give every forked child its own Doctrine connection (the class is in [references/evaluation.md](references/evaluation.md)).

- **The project root, from anywhere.** Neuron reads `evaluation.php` and its relative paths from the working directory. Launched from `/tmp`, the command found the evaluators, wrote `var/evaluation.json` and cached into `var/evaluation/cache`; without the `chdir()`, `evaluation.php` was ignored and the cache landed in `/tmp/.neuron`. A path argument resolves against the launch directory (`Path::makeAbsolute($path, getcwd())`).
- **The child hooks.** `beforeChild` keeps the child's copy of the parent's connection referenced and closes Doctrine's `Connection`, so the child's next query opens its own; `afterChild` closes that one. With `--concurrency=4` on MySQL and an evaluator querying in `setUp()` and `run()`, each child got its own connection and the parent's still wrote the report afterwards. Without hooks the children shared it and 2 of 8 items failed with `2006 MySQL server has gone away`; a hook that only called `close()` destroyed the inherited PDO, which closed the parent's connection. Treat any other connection opened before the fork (a Redis client) the same way.

An evaluator injects the agent in `public function __construct(protected SupportAgent $agent)`, which must call `parent::__construct()` (without it every item errored with `Typed property NeuronAI\Evaluation\BaseEvaluator::$ruleExecutor must not be accessed before initialization`), and runs each item on its own copy, bound to a thread of the customer the evaluation database is seeded with:

```php
use App\Neuron\Agents\SupportAgent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use Symfony\Component\Uid\Uuid;

/** The app's agent on a fresh thread of the evaluation customer, with stores that never touch the conversation tables. */
protected function agent(): SupportAgent
{
    return $this->agent->for('user-'.self::CUSTOMER.'-'.Uuid::v4())
        ->setMessageStore(new InMemoryMessageStore())
        ->setPersistence(new InMemoryPersistence());
}
```

- **Keep evaluations out of the conversation tables.** Setters win over the hooks and stay on the copy: after runs on MySQL, `chat_messages` and `workflow_store` were empty and the container's agent still had no stores; a copy without the setters wrote its thread to `chat_messages`.
- **Multi-turn:** `Conversation::make($this->agent())` binds its own copy with `for()`, which kept the in-memory stores (the conversation's 6 messages were in the evaluator's `InMemoryMessageStore`), but to an `eval_…` thread that names no customer: `customerId()` refused it with `LogicException: Thread 'eval_…' names no customer.` The conversation evaluator pins its tools on its copy with `->setTools([new OrderStatusTool($this->orders, self::CUSTOMER), new RefundOrderTool($this->orders, $this->logger, self::CUSTOMER)])`; a setter wins over the `tools()` hook.
- **A database of its own.** The tools run for real: the approved refund refunded the order. Point `DATABASE_URL` at a dedicated database and rebuild it before every run, CI included: drop, create, migrate, and load the fixtures that create the customer and the orders the datasets name (the commands are in the reference).
- **CI.** Fail the job on the command's exit code, persist `var/evaluation/cache`, run with `--cache` and schedule a `--fresh` run. `--cache` replays `run()` until `run()`, its dataset item or a file in `cacheDependencies()` changes, and the agent's prompt and tools live outside `run()`: every evaluator that receives the agent declares `public function cacheDependencies(): array { return [SupportAgent::class, OrderStatusTool::class, RefundOrderTool::class]; }`. Without it a changed prompt still gave `Cached runs: 5 of 5` and called no model; with it every item ran again.
- `vendor/bin/neuron evaluation --autoload-file=…` with a `$container->get()` resolver in `evaluation.php` fails: evaluators are private services (`has been removed or inlined when the container was compiled`). Use the console command.

Read [references/evaluation.md](references/evaluation.md) for the command and evaluator classes, the evaluation database, why evaluators live in `src/` rather than an `autoload-dev` directory, and what each run showed. Evaluators, datasets, assertions, judges and caching: **neuron-evaluation**.

## Testing

Fake the provider on the shared agent before the first request, and keep one kernel for the whole test so the fake survives: a `WebTestCase` whose `setUp()` calls `$this->client->disableReboot()` right after `static::createClient()`, and a helper that runs `static::getContainer()->get(SupportAgent::class)->setAiProvider($fake)`. Messenger uses the `in-memory://` transport under `when@test`, and the client logs in an app user with `loginUser()` and sends `Sec-Fetch-Site: same-origin` plus `X-CSRF-Token: csrf-token`, as a same-origin `fetch()` does.

- A `StreamedResponse` body is in `getInternalResponse()->getContent()`; `getResponse()->getContent()` is `false`.
- `setAiProvider()` on the shared agent reaches every `for()` copy the controllers make, and the `FakeAIProvider` instance holds the assertions. Symfony's `MockHttpClient` never sees provider traffic: providers use Neuron's own HTTP client.
- The stores run against the test database: build it with the app's migrations in `tests/bootstrap.php` (unlink and migrate on SQLite; drop, create and migrate on MySQL, MariaDB or PostgreSQL) and empty `chat_messages`, `workflow_store` and the app's tables in `setUp()`.
- Replace `RedisRelay` in the test container with a subclass whose `publisher()` returns a `FakeChannel` and whose `relay()` only dispatches. Call handlers directly, then `$channel->assertSuspended()` or `assertCompleted()`; the relay endpoint's tests read the queued message from `static::getContainer()->get('messenger.transport.async')->getSent()`.
- **Stop.** `FakeAIProvider` replaces the provider and its Stop client, so a faked turn never stops: test the flag through the endpoints (204, raised, cleared by the next turn), and a real stop with a subclass whose `transport()` streams a scripted answer.

Read [references/testing.md](references/testing.md) for the base class (with the SSE `frames()` parser), the `when@test` transport and both bootstraps, approval tests over JSON and AG-UI, a failed turn finished before the next one, the relay endpoint and the handler tests, and a real Stop. Fakes and assertions: **neuron-test**.

## Pitfalls

- **`setThreadId()` on the injected agent**: the second thread the same container serves throws. Use `for()`.
- **`SupportAgent::make()` or `make(workflowId: …)`**: `ArgumentCountError` or `Unknown named parameter $workflowId`, because the agent has its own constructor. Inject it from the container and bind it with `for()`.
- **A promoted constructor property named like an Agent property**: fatal when the types differ (`$messageStore`, `$persistence`, `$channel`), a silent override when they match (a promoted `$provider` bypasses the `provider()` hook).
- **A PDO captured once** (a shared store, a store built in a constructor): dead after the server drops the connection. Use `shared: false` stores plus `doctrine_ping_connection`.
- **`doctrine_transaction` on the agents' bus**: runs invisible while the handler runs, erased on rollback.
- **`redeliver_timeout` below the longest turn** without `--keepalive`: the same turn runs twice.
- **A new turn after a failed one**: `ChatHistoryException` when the failed turn had stored its question. Finish it first (`recoverFailedTurn()`); in a handler, finish another message's dead run, never `abandon()` it.
- **Not priming the generator**: refusals become `RUN_ERROR` frames under a 200 instead of 409s.
- **Iterating the generator without the try/catch of `frames()`**: a failure after the headers escapes instead of ending with `RUN_ERROR`.
- **A stale stop flag**: clear it when a turn starts, before finishing a failed one. **A consuming `pull()`**: a retry generates the stopped answer. **`hasItem()` on the filesystem pool**: a worker kept seeing the flag after the next turn cleared it.
- **`abortRun()` as the Stop button**: in the request it is a disconnect that loses the partial answer; relayed, the worker finishes the whole answer. POST the Stop endpoint and keep reading.
- **`$this->json()` with `symfony/serializer`**: empty tool inputs `{}` become `[]`. Return `new JsonResponse()`.
- **`framework.exceptions` for Neuron exceptions**: no message, no `Retry-After`, first match wins.
- **`php -S` without `-d variables_order=EGPCS`**: `.env` placeholders shadow real env vars. One server worker serialises Stop behind the stream.
- **A Symfony listener on `ObservabilityEvent`**: never called. **A listener that throws on a node event**: fails the turn.
- **MySQL/MariaDB without strict mode**: `PersistenceException` at the first write.
- **A Doctrine diff without `schema_filter`**, or a `BIGSERIAL` id on PostgreSQL: the diff drops Neuron's tables or sequence.
- **Entities in workflow state**: state is serialized at every step; keep IDs and load the entity in the tool.
- **Evaluating the container's agent without in-memory stores**: evaluation threads land in `chat_messages`. **Without `cacheDependencies()`**: `--cache` replays outputs of an agent that changed.
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
