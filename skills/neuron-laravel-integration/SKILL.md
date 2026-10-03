---
name: neuron-laravel-integration
description: Integrate Neuron AI agents into a Laravel 13 app the framework-native way — setup checklist (App\Neuron layout, config/services.php keys, one migration, NeuronServiceProvider, bootstrap/app.php exception mapping, neuron:evaluate), container-built agents bound per thread with for(), thread authorization, JSON and AG-UI, CopilotKit or Vercel streaming, conversation reload, queued jobs through Reverb or a Redis SSE relay, failed-turn recovery, a Stop button, evaluations and tests. Use this skill whenever the user mentions Neuron AI in a Laravel app, an AI chat or assistant in a Laravel project, streaming an agent from a controller, CopilotKit or useChat with a Laravel backend, an agent in a queue job, Reverb, reloading a conversation, stopping a generation, or testing or evaluating a Laravel agent. Also trigger for NeuronServiceProvider, EloquentMessageStore, DatabasePersistence, RunInFlightException, retry_after, RedisChannelReader, StoppableHttpClient, FakeAIProvider, or config:cache and API keys.
---

# Neuron AI Laravel Integration

This skill wires Neuron agents into a Laravel 13 application: the foundations every feature stands on (classes, keys, tables, container, error mapping, the evaluation command), then the agent, the controllers that answer in JSON or stream to the browser, the queue jobs, the Stop button, observability, evaluations and tests. Neuron semantics (protocols, approvals, durability, channels, assertions) belong to the linked skills; this one owns everything Laravel-shaped. Every snippet below ran in a Laravel 13.34 application: its feature suite passes on SQLite, MySQL 8.4, MariaDB 11.7 and PostgreSQL 17, and the streaming, queue, Reverb, Redis and forked evaluation paths ran against real servers.

## The Mental Model

**The container builds the agent; the request binds it.** An agent is resolved from the container for each request or job, then `->for($threadId)` returns a copy bound to one conversation. Per-request settings (stream adapter, channel, browser tools) go on that copy. The stores are shared services in the container; the provider, tools and instructions are built by the agent's hooks for every execution segment.

| Piece | Where | Lifetime |
|---|---|---|
| `MessageStoreInterface` → `EloquentMessageStore` | `NeuronServiceProvider` | `singleton`: holds a model class and resolves its connection on every call |
| `PersistenceInterface` → `DatabasePersistence` | `NeuronServiceProvider` | `bind`: keeps the PDO it was given, so every resolution takes Laravel's current one |
| `WorkflowEngine` | autowired, no binding | per resolution: reads runs without building an agent |
| `SupportAgent` | `app/Neuron/Agents` | transient: resolved per request or job, then bound with `for()` |
| Provider, tools, instructions | the agent's hooks | built again for every execution segment |
| Stream adapter, channel, browser tools | the `for()` copy | one request; adapter and channel factories run once per segment |
| Thread ID | minted by the server, checked by a FormRequest | the conversation |
| Run ID of a queued turn | minted by the controller (a continuation reuses the suspended run's), serialized with the job | every delivery of that job |
| Evaluators | `app/Neuron/Evaluators`, built by `neuron:evaluate` through the container | one command run; with `--concurrency` each item runs in a forked child |

Rules that decide correctness:

- **The thread ID is untrusted input.** Mint it on the server with the owner in it and authorize it before `for()`.
- **An explicit setter beats its hook.** Tests call `setAiProvider()` and evaluators call `setMessageStore()` on the container's agent; the app code never changes.
- **`env()` only inside `config/*.php`.** After `php artisan config:cache`, `env()` returns null for `.env` values and `$_ENV` has none of them; `config()` keeps working.

## Setup: the Foundations

When the app has no Neuron integration yet, lay these foundations before building the feature the user asked for, in this order, and skip each one that already exists. Check first: in an integrated app `grep -rlsE 'NeuronServiceProvider|workflow_store|RunInFlightException|neuron:evaluate' bootstrap/app.php bootstrap/providers.php database/migrations app/Console` lists `bootstrap/app.php`, `bootstrap/providers.php`, the migration and the command (in a fresh app, nothing), `app/Neuron` holds the agents and `config/services.php` their provider's key.

**1. Install.** `spatie/fork` lets evaluations run items in parallel; without it `--concurrency` runs sequentially. It needs `ext-pcntl` and `ext-sockets`, and Neuron forks only when `ext-posix` is loaded too. A `--dev` install is enough for evaluations; queue workers that use `parallelToolCalls()` need it without `--dev`, or their tool calls run one after another.

```bash
composer require neuron-core/neuron-ai
composer require --dev spatie/fork
```

**2. Layout and generators.** Neuron classes live under `App\Neuron`: `Agents`, `Tools`, `Workflows`, `Nodes`, `Middleware`, `Channels`, `Evaluators`, and framework glue such as `App\Neuron\LaravelEventDispatcher` at the root. Framework-owned classes stay where Laravel puts them: `app/Providers`, `app/Http/Controllers`, `app/Http/Requests`, `app/Jobs`, `app/Console/Commands`, `app/Models`, `database/migrations`.

```bash
vendor/bin/neuron make:agent 'App\Neuron\Agents\SupportAgent'   # the full class name: a bare name lands in app/
vendor/bin/neuron make:tool 'App\Neuron\Tools\LookupOrder'
php artisan make:provider NeuronServiceProvider                 # registers itself in bootstrap/providers.php
php artisan make:migration create_neuron_tables
php artisan make:model ChatMessage
php artisan make:command NeuronEvaluate                         # app/Console/Commands is discovered automatically
```

**3. Keys.** They go in `config/services.php`, read from the environment there and nowhere else; agents read `config('services.anthropic.key')`.

```php
'anthropic' => [
    'key' => env('ANTHROPIC_API_KEY'),
    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
],
```

**4. Tables.** One migration creates both tables, `chat_messages` for the conversation and `workflow_store` for the durable runs, with columns that branch on the driver: thread and message IDs are `binary()` (VARBINARY) on MySQL and MariaDB, whose collations would let `user-1-CaseTest` and `user-1-casetest` share a history, and `string()` elsewhere; the `ascii_bin` identifiers of `workflow_store` exist only on MySQL and MariaDB (PostgreSQL refuses the collation). `App\Models\ChatMessage` makes `thread_id`, `message_id`, `role`, `content` and `meta` fillable and casts `content` and `meta` to `array`. Read [references/database.md](references/database.md) when writing the migration and the model: it has both, ran unchanged on SQLite, MySQL 8.4, MariaDB 11.7 and PostgreSQL 17, and lists what each column must be. Keep the MySQL connection's `'strict' => true` (Laravel's default): without strict mode `DatabasePersistence` refuses to write with a `PersistenceException`.

**5. Container wiring.**

```php
namespace App\Providers;

use App\Models\ChatMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use NeuronAI\Chat\History\EloquentMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;

class NeuronServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stateless: it resolves the model's connection on every call.
        $this->app->singleton(MessageStoreInterface::class, fn (): MessageStoreInterface => new EloquentMessageStore(ChatMessage::class));

        // It keeps the PDO it was given, so every resolution takes the current one (reconnects replace it).
        $this->app->bind(PersistenceInterface::class, fn (): PersistenceInterface => new DatabasePersistence(DB::connection()->getPdo()));
    }
}
```

Background runs add a `ChannelFactory` binding and observability adds the event bridge to the same `register()`; both are shown in their sections.

- **Never register the agent as a singleton.** It captures the persistence (and its PDO) when it is built, so a singleton keeps one for a whole queue worker; resolve it per request or job by method injection and bind it with `for()`.
- **`WorkflowEngine` needs no binding**: Laravel autowires it from `PersistenceInterface` with the default `PhpSerializer`, the serializer every Agent uses. If you ever change the serializer, bind `Serializer` and return the same one from the agents' `serializer()` hook.
- **`EloquentPersistence`** is the alternative that resolves the connection per operation; it needs its own model and table rules (surrogate key plus `unique(partition, key)`), described in **neuron-workflow** ("Database Table Schema").
- Give Neuron a least-privilege database user of its own, and never run agents inside an application transaction: recommend both to the developer, never change credentials yourself.

**6. `bootstrap/app.php`: input middleware and error mapping.** Laravel's global `TrimStrings` and `ConvertEmptyStringsToNull` would change agent payloads on their way in (an empty AG-UI tool result becomes `null`, a code block loses its indentation), so the agent routes skip them. Neuron's exceptions become statuses once, here.

```php
// bootstrap/app.php (the skeleton already imports Exceptions, Middleware and Request)
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\WorkflowException;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->trimStrings(except: [fn (Request $request) => $request->is('chat/*')]);
    $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('chat/*')]);
})
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(
        fn (Request $request) => $request->is('api/*', 'chat/*') || $request->expectsJson(),
    );

    // The first callback whose type matches wins: the most specific class goes first.
    $exceptions->render(fn (InputTranslationException $e) => response()->json(['message' => $e->getMessage()], 400));
    $exceptions->render(fn (PersistenceException $e) => response()->json(['message' => 'Conversations are unavailable. Retry later.'], 503));
    // A database error is not a Neuron exception; null leaves the application's other routes to Laravel.
    $exceptions->render(fn (PDOException $e, Request $request) => $request->is('chat/*')
        ? response()->json(['message' => 'Conversations are unavailable. Retry later.'], 503)
        : null);
    $exceptions->render(fn (RunInFlightException $e) => response()->json(
        ['message' => 'The conversation is busy.', 'status' => $e->status->value],
        409,
        $e->leaseExpiresAt === null ? [] : ['Retry-After' => max(1, $e->leaseExpiresAt - time())],
    ));
    $exceptions->render(fn (WorkflowException $e) => response()->json(['message' => 'The conversation changed. Reload it.'], 409));
    $exceptions->dontReport([InputTranslationException::class, RunInFlightException::class]);
})
```

- **Match the path with `is()`**: these middlewares run before routing, so `routeIs()` never matches there. **The most specific exception goes first**: Laravel uses the first render callback whose type matches, in registration order, and `PersistenceException` and `RunInFlightException` both extend `WorkflowException`.
- **Laravel's own errors answer JSON on the agent routes too.** The skeleton's `shouldRenderJsonWhen()` covers only `api/*` and clients that ask for JSON. The AG-UI client sends `Accept: text/event-stream` and `useChat` sends `*/*`: without `chat/*`, a validation error is a 302 and a refused thread an HTML 403. Guests get a JSON 401 when the app has a `login` route (the starter kits define one) or `$middleware->redirectGuestsTo('/login')`; with neither, the redirect lookup is a 500.

| Exception | Status | When |
|---|---|---|
| `InputTranslationException` | 400 | Malformed or stale input: no persisted run, unknown call ID, bad AG-UI seed or tool. Its message is safe for clients |
| `PersistenceException` | 503 | A corrupted record, MySQL without strict mode, a Redis persistence error |
| `PDOException` on `chat/*` | 503 | The database is unreachable or a query fails: `DatabasePersistence` and `EloquentMessageStore` pass driver errors on as they are, and Laravel's `QueryException` is a `PDOException`. The application's own queries on the agent routes are covered too; every other route keeps Laravel's handling |
| `RunInFlightException` | 409 | An approval is pending (`status: suspended`), or a turn is executing (`status: running`, with `Retry-After` from the lease) |
| other `WorkflowException` | 409 | A stale continuation or a race between two tabs; its message carries internals, keep it off the wire |

**7. The evaluation command.** `neuron:evaluate` runs Neuron's evaluation CLI inside the booted application: evaluators and output drivers are built by the container, and forked children get their own database connections. Create it with `php artisan make:command NeuronEvaluate` and replace the class, then add `evaluation.php` at the project root: both are in [references/evaluation.md](references/evaluation.md) ("The command"). The evaluators and the commands that run them are in Evaluation.

- **`chdir()` makes `evaluation.php` count from any directory.** Without it, a command started elsewhere reads no configuration: the default path is not found, and with an absolute path the cache lands in `.neuron/` of that directory. Paths passed to the command are relative to the project root. Both storage paths are already gitignored by Laravel.
- **The resolver is the container**: evaluators and output drivers listed as class names get constructor injection. Drivers are built after all runs, in the parent process. The command's resolver and runner win over `resolver` and `runner` entries in `evaluation.php`.
- **The child hooks make `--concurrency` safe with the database.** Without them the children share the parent's MySQL socket: queries fail with "Premature end of data" or return wrong counts, and the parent's own next query fails. `DB::purge()` alone closes the parent's session, because the child destroys the PDO it inherited; keeping the inherited PDOs referenced leaves the parent on the same connection. `disconnect()` closes each child's own connection, which MySQL otherwise counts as an aborted client.
- **Flags and exit code are Neuron's**: the path, `--concurrency=N`, `--cache`, `--fresh` and `-v` are forwarded, and the command exits 1 when an assertion fails or an evaluator errors.
- **It refuses to evaluate on the main database.** Laravel ignores `--env=evaluation` while config is cached (`config:cache`, `optimize`) and falls back to `.env` when `.env.evaluation` is missing, yet the environment still reads `evaluation`. The command checks `environmentFile()` and exits 1 in both cases.

**8. The queue, when turns run in jobs.** Set `DB_QUEUE_RETRY_AFTER=360` in `.env` (`REDIS_QUEUE_RETRY_AFTER` on a Redis queue, the connection's `retry_after` under Horizon): it must exceed the job's `$timeout` of 300, and Laravel ships 90 (Background Runs). Run a real worker, `php artisan queue:work`, in local development too: on the `sync` driver the Redis relay runs the job before its response exists and streams nothing.

## The Agent

Replace the generated body: the stub reads the key and the model from `$_ENV`. Stores arrive through the constructor, the provider is built from config in its hook.

```php
namespace App\Neuron\Agents;

use App\Neuron\Tools\LookupOrder;
use App\Neuron\Tools\RefundOrder;
use Illuminate\Support\Facades\Cache;
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

class SupportAgent extends Agent
{
    public function __construct(
        protected MessageStoreInterface $conversations,
        protected PersistenceInterface $runs,
    ) {
        parent::__construct();
    }

    protected function provider(): AIProviderInterface
    {
        return new Anthropic(
            key: config('services.anthropic.key'),
            model: config('services.anthropic.model'),
            // The Stop button raises this flag; the provider asks before every streamed event.
            httpClient: new StoppableHttpClient(
                client: $this->transport(),
                shouldStop: fn (): bool => Cache::get(self::stopKey($this->getThreadId()), false),
            ),
        );
    }

    /** The provider's HTTP client: a test subclass replaces it to script a streamed answer. */
    protected function transport(): HttpClientInterface
    {
        return new CurlHttpClient();
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ['You are the customer support assistant of an online shop.'],
            steps: ['Look up an order before answering a question about it.'],
        );
    }

    protected function tools(): array
    {
        return [
            LookupOrder::make($this->customerId()),
            RefundOrder::make($this->customerId()),   // declares approvalPolicy(): runs only after a human approves
        ];
    }

    protected function messageStore(): MessageStoreInterface
    {
        return $this->conversations;
    }

    protected function persistence(): PersistenceInterface
    {
        return $this->runs;
    }

    /**
     * @param  FrontendTool[]  $tools
     *
     * @throws InputTranslationException when a browser tool would shadow a backend tool
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

    public static function stopKey(string $threadId): string
    {
        return "neuron:stop:{$threadId}";
    }

    /** Threads are named "user-{id}-{uuid}" by the server, and queue jobs have no auth(). */
    protected function customerId(): int
    {
        if (preg_match('/^user-(\d+)-/', $this->getThreadId(), $match) !== 1) {
            throw new LogicException("Thread '{$this->getThreadId()}' names no customer.");
        }

        return (int) $match[1];
    }
}
```

- **Always call `parent::__construct()`.** Promoted properties must not reuse Agent property names: a fatal error when the types differ (`$messageStore`, `$persistence`, `$channel`: "Type of …::$messageStore must be ?…MessageStoreInterface (as in class NeuronAI\Agent\Agent)"), a silent override when they match (a promoted `$provider` bypasses the `provider()` hook, and with it the Stop client).
- **`recoverFailedTurn()` finishes a failed turn before the next one.** A turn that failed after it stored its question (the client disconnected, the provider failed after a tool step) makes the next `chat()` or `stream()` throw `ChatHistoryException`. Every endpoint that starts a turn calls it first, on a copy with no stream adapter or channel: an adapter factory returns the same instance for every segment, and the relay reads only the first one. The failed turn's answer lands in history before the new question; if finishing it fails again, so does the new message, until `resetConversation()` clears the thread. It finishes a turn that stored nothing too (a provider error at the first inference, a Stop before the first word): a user who sends the same question again gets it answered twice. It only sees failed runs: a request killed outright (SIGKILL, OOM, `request_terminate_timeout`) leaves its run `running`, and once its lease expires the next message supersedes it and fails the same way when the question was stored, until `resetConversation()`.
- **Each agent owns its provider.** Do not bind `AIProviderInterface` globally: the hook builds a fresh provider per segment, knows the thread ID the Stop flag needs, and lets each agent pick its model. Other vendors and client options: **neuron-agent** (`references/providers.md`).
- **Tools take identity from the thread, never from `auth()`**: the same agent runs in queue workers, where there is no user. `customerId()` refuses a thread that names no customer: an evaluation `Conversation` names its own threads (`eval_<uuid>`), and splitting one on `-` would read a customer ID out of the UUID. `LookupOrder` and `RefundOrder` (`lookup_order` and `refund_order`, an integer `order_id`, the customer in the constructor, `approvalPolicy()` on the refund) are in [references/tools.md](references/tools.md); the tests and datasets rely on those names. Writing tools and declaring approval risk: **neuron-tool** and **neuron-tool-approval**.
- **The frontend-tool check is the app's policy.** Without it a browser tool named like a backend tool fails the run with a `ToolException` (a 500) and records it as failed. If `tools()` returns toolkits, compare the names of their `tools()`.

## Threads and Authorization

The server names a conversation after its owner: `ThreadController::store(Request $request)` answers `response()->json(['threadId' => "user-{$request->user()->id}-".Str::uuid()], 201)`. Every request that carries a thread ID checks the prefix before anything runs:

```php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ThreadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return str_starts_with($this->threadId(), "user-{$this->user()->id}-");
    }

    public function rules(): array
    {
        return [];
    }

    public function threadId(): string
    {
        return (string) $this->route('thread');
    }
}
```

`authorize()` runs before validation, and a refusal is a 403 with nothing executed. Apps that list or title conversations can use a `Conversation` model with a policy instead of the prefix. What a thread refuses while an approval is pending is described in **neuron-tool-approval**.

Every endpoint of this skill sits in one `auth` route group under the `chat/` prefix that step 6 of Setup excludes from input rewriting: `POST chat/threads`, `GET chat/threads/{thread}`, `POST chat/threads/{thread}/messages`, `/runs` and `/stop`, `POST chat/agui` and `chat/agui/background`. The group is in [references/streaming-endpoints.md](references/streaming-endpoints.md).

## A JSON Turn

The simplest endpoint: a message, or the decisions on the pending approvals. `ChatRequest extends ThreadRequest` with the rules `'message' => ['required_without:decisions', 'prohibits:decisions', 'string']` and `'decisions' => ['required_without:message', 'array']`.

```php
// app/Http/Controllers/ChatController.php
use App\Http\Requests\ChatRequest;
use App\Neuron\Agents\SupportAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use NeuronAI\Chat\Messages\UserMessage;

public function send(ChatRequest $request, SupportAgent $agent): JsonResponse
{
    $agent = $agent->for($request->threadId());
    Cache::forget(SupportAgent::stopKey($request->threadId()));
    $agent->recoverFailedTurn();

    $state = $request->has('decisions')
        ? $agent->submitApprovalDecisions($request->input('decisions'))->run()
        : $agent->chat(new UserMessage($request->input('message')));

    if ($state->isInterrupted()) {
        return response()->json(['status' => 'awaiting_approval', 'approvals' => $agent->pendingApprovals()]);
    }

    return response()->json(['status' => 'completed', 'message' => $state->getMessage()->jsonSerialize()]);
}
```

It clears the Stop flag first, like every turn endpoint (Stop Button), and this copy has no stream adapter or channel, so it can finish a failed turn before its own. A message while an approval is pending is a 409 `{"status":"suspended"}`; decisions for an unknown call ID or with no pending run are 400s with Neuron's message (the mapping of Setup, step 6). Decision values and the approval UI: **neuron-tool-approval**.

## Streaming to the Browser

The AG-UI endpoint (the AG-UI `HttpAgent` or CopilotKit in the browser) runs the agent inside the request. `RunAgentRequest extends ThreadRequest` validates the `RunAgentInput` and reads the thread from the body; its helpers decide the turn: `frontendTools()` builds the browser tools with `AGUIInputTranslator::tools()`, `isContinuation()` is true for a `resume` array or a tool message after the last user message, and `prompt()` is the last user message's text (an `InputTranslationException` when there is none). Read [references/streaming-endpoints.md](references/streaming-endpoints.md) for the class and for the Vercel variant of this controller.

```php
// app/Http/Controllers/ChatController.php
use App\Http\Requests\RunAgentRequest;
use App\Neuron\Agents\SupportAgent;
use Generator;
use Illuminate\Support\Facades\Cache;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

public function stream(RunAgentRequest $request, SupportAgent $agent): StreamedResponse
{
    $threadId = $request->threadId();
    Cache::forget(SupportAgent::stopKey($threadId));
    // On a copy of its own: the recovered turn must not stream into this response.
    $agent->for($threadId)->recoverFailedTurn();

    $adapter = new AGUIAdapter(threadId: $threadId, runId: $request->input('runId'), messages: $request->input('messages'), state: $request->array('state'));
    $agent = $agent->for($threadId)
        ->setStreamAdapter(fn (): AGUIAdapter => $adapter)
        ->addFrontendTools($request->frontendTools());

    $events = $request->isContinuation()
        ? $agent->submitInputs($request->all(), new AGUIInputTranslator)->events()
        : $agent->stream(new UserMessage($request->prompt()));

    // Admission is lazy: start it now, so a refusal is an HTTP status instead of a RUN_ERROR frame.
    $events->valid();

    return response()->stream(function () use ($events, $adapter): Generator {
        try {
            yield from SSEEncoder::encode($events);
        } catch (Throwable $e) {
            report($e);
            foreach ($adapter->error($e) as $event) {
                yield SSEEncoder::frame($event);
            }
        }
    }, 200, $adapter->getHeaders());
}
```

- **Prime before returning.** `stream()` and `events()` admit the run only when first iterated. `$events->valid()` in the controller turns a refusal (an approval pending, another tab streaming) into a 409 before any header; without it the browser gets a 200 with a `RUN_ERROR` frame. After priming, only `SSEEncoder::encode($events)` may iterate the generator: never `foreach` it. Build the adapter and add the tools there too, so their `InputTranslationException`s become a 400.
- **Use the generator form of `response()->stream()`.** Laravel echoes each yielded frame, flushes with an `ob_get_level()` guard and adds `X-Accel-Buffering: no`. The content type becomes `text/event-stream; charset=utf-8`. Never `response()->eventStream()`: it wraps every frame in `event: update` and appends a `data: </stream>` frame no AG-UI or Vercel client can parse. In a hand-written callback, guard `ob_flush()` with `ob_get_level() > 0`: with no output buffer it raises a notice that Laravel turns into an `ErrorException` mid-stream.
- **`$adapter->error($e)` is idempotent**: it yields nothing when the run already sent its `RUN_ERROR`, and closes the protocol when the failure happened before any frame.
- **The text reaches the agent verbatim** because the `chat/*` routes skip `TrimStrings` and `ConvertEmptyStringsToNull` (Setup, step 6): the client's messages seed the adapter, whose snapshots would otherwise send back `null` for an empty tool result and trimmed code.
- **Clear the Stop flag first, then finish a failed turn, then build the turn's copy.** A flag still raised would stop the recovery too (Stop Button). The recovered answer is not streamed: this response starts after it.

**The browser client.** Point the AG-UI `HttpAgent` at the route from the page itself; CopilotKit can drive the same agent without a runtime (its Vue v2 `CopilotKitProvider` takes it in `self-managed-agents`). The page's `fetch` carries the session cookie; send the `XSRF-TOKEN` cookie back as `X-XSRF-TOKEN`, and seed a reloaded page from the reload endpoint (Reloading a Conversation):

```ts
import { HttpAgent } from "@ag-ui/client";

const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? "");
const page = await (await fetch(`/chat/threads/${threadId}`, { headers: { Accept: "application/json" } })).json();
const agent = new HttpAgent({ url: "/chat/agui", threadId, initialMessages: page.messages, headers: { "X-XSRF-TOKEN": xsrf } });
agent.pendingInterrupts = page.interrupts;
```

**CopilotKit's runtime bridge** calls the route server to server, with neither the browser's cookie nor its headers: Laravel answers 419. Forward the browser's `Cookie` and `X-XSRF-TOKEN` from the bridge, or serve the bridge from routes outside the `web` group with token authentication, and authorize the thread either way. The bridge then owns the reload (**neuron-frontend-integration**, "CopilotKit").

**Vercel AI SDK (`useChat`).** The same controller with the Vercel pieces: the thread is the chat `id`, the adapter is `VercelAIAdapter`, continuations use `VercelAIInputTranslator`, and `getHeaders()` adds `x-vercel-ai-ui-message-stream: v1`. The variant that ran is in [references/streaming-endpoints.md](references/streaming-endpoints.md); payload rules are in **neuron-frontend-integration**.

**Sessions and CSRF.** Laravel saves the session before the stream callback runs: write session data before `return response()->stream(...)`; a write inside the callback is lost. A running stream holds no session lock, so the Stop button and other requests go through. Laravel 13's `PreventRequestForgery` lets a same-origin `fetch` through (`Sec-Fetch-Site: same-origin`), as well as a CSRF token such as the `X-XSRF-TOKEN` header. A request with neither is a 419, and a cross-site request carries no session cookie (`SameSite=lax`).

**Disconnects.** When the client goes away, PHP ends the request at the next frame and Neuron fails the run at once. The next message first finishes that turn through `recoverFailedTurn()`, then answers. To keep the answer when the tab closes, call `ignore_user_abort(true)` before returning the response (the run then completes into history), or move the turn to a job (below).

**Local development.** `php artisan serve` runs one worker unless `PHP_CLI_SERVER_WORKERS` is above 1 and `--no-reload` is passed; with one worker every other request waits until the stream ends. Use `PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload`.

## Reloading a Conversation

The page load reads the transcript and the run without building the agent:

```php
// app/Http/Controllers/ThreadController.php
use App\Http\Requests\ThreadRequest;
use Illuminate\Http\JsonResponse;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Workflow\WorkflowEngine;

public function show(ThreadRequest $request, MessageStoreInterface $messages, WorkflowEngine $engine): JsonResponse
{
    $before = $request->validate(['before' => ['nullable', 'string']])['before'] ?? null;
    $threadId = $request->threadId();

    // The run belongs to the latest page only.
    $run = $before === null ? $engine->inspect($threadId) : null;
    $loaded = $messages->loadAll($threadId, limit: 50, before: $before);

    return response()->json([
        ...(new AGUIAdapter(threadId: $threadId))->hydrate($loaded, $run),
        'status' => $run?->status->value,
        // The cursor of the previous page: a stored message ID, never an AG-UI one.
        'before' => $loaded === [] ? null : $loaded[0]->getId(),
    ]);
}
```

`messages` seed the AG-UI client's `initialMessages`, `interrupts` its `pendingInterrupts` (a pending approval comes back as `confirmation` interrupts). Page backwards with `?before=` set to the `before` the previous page returned: the stored ID of its first message. Never page with an `id` from `messages`: a page that starts at a tool result or a reasoning block hydrates it as `result_…` or `reasoning_…`, which no store knows, and the older page comes back empty. `before` is validated as a string, so `?before[]=x` is a 422, not a 500. `status` is `suspended`, `running` or `failed` while a run exists and `null` once it completed; a reload does not reattach to a running stream. For UIs that do not speak AG-UI, serve `loadAll()` plus `$engine->inspect($threadId)?->interrupt`, or `$agent->for($threadId)->pendingApprovals()`. Client wiring: **neuron-frontend-integration** ("Reloading an AG-UI conversation") and **neuron-tool-approval** ("Rebuilding the UI after a reload").

## Background Runs

A queued turn survives a closed tab and turns longer than a proxy allows. The controller mints the run ID and dispatches, for example `RunSupportAgent::dispatch($threadId, (string) Str::uuid(), $request->input('message'))`; the job carries the ID, so every delivery reaches the same run. A continuation carries the ID of the suspended run it continues (`$agent->for($threadId)->inspect()->runId`), so its job recognises that run too.

```php
namespace App\Jobs;

use App\Neuron\Agents\SupportAgent;
use App\Neuron\Channels\ChannelFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowStatus;
use Throwable;

class RunSupportAgent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Longer than the longest turn, shorter than the queue connection's retry_after (DB_QUEUE_RETRY_AFTER=360). */
    public int $timeout = 300;

    /**
     * $runId names the run this job starts (minted by the controller) or continues (the suspended run's).
     * $message null continues it with $decisions, or with the AG-UI $input when the browser speaks AG-UI.
     */
    public function __construct(
        public string $threadId,
        public string $runId,
        public ?string $message,
        public array $decisions = [],
        public array $input = [],
    ) {}

    public function handle(SupportAgent $agent, ChannelFactory $channels): void
    {
        $turn = $agent->for($this->threadId)
            ->setStreamAdapter(fn (): AGUIAdapter => $this->adapter())
            ->setChannel(fn (): StreamingChannelInterface => $channels->make($this->threadId, $this->runId))
            ->addFrontendTools((new AGUIInputTranslator)->tools($this->input));

        try {
            if ($this->message === null) {
                $this->continueRun($turn);
            } else {
                $turn->run(ExecutionRequest::start(
                    new AgentStartEvent([new UserMessage($this->message)], new AgentRunOptions(stream: true)),
                    runId: $this->runId,
                    recoverFailed: true,
                ));
            }
        } catch (RunInFlightException $e) {
            $this->settle($e, $agent);
        }
    }

    /** Staged now, from the run as it is at job time. */
    protected function continueRun(SupportAgent $turn): void
    {
        $run = $turn->inspect();

        // A redelivery after the approved tools ran: the answers are applied, finish the run.
        if ($run?->runId === $this->runId && $run->status === WorkflowStatus::Failed && $run->interrupt === null) {
            $turn->run(ExecutionRequest::resume(expectedRunId: $run->runId, expectedExecutionAttempt: $run->executionAttempt));

            return;
        }

        $continuation = $this->input === []
            ? $turn->submitApprovalDecisions($this->decisions)
            : $turn->submitInputs($this->input, new AGUIInputTranslator);
        $continuation->run();
    }

    /** A reserved start never replaces another run, even a dead one: this job decides. */
    protected function settle(RunInFlightException $e, SupportAgent $agent): void
    {
        $dead = $e->status === WorkflowStatus::Failed
            || ($e->status === WorkflowStatus::Running && $e->leaseExpiresAt !== null && $e->leaseExpiresAt <= time());

        if ($e->reservedRunId === $e->runId) {
            if ($e->status === WorkflowStatus::Running) {
                $this->release(max(1, $e->leaseExpiresAt - time())); // this run still executes in another worker
            }
        } elseif ($dead) {
            // Another job gave up on its turn: finish it on a copy without this job's channel, then start again.
            $agent->for($this->threadId)->run(ExecutionRequest::resume(expectedRunId: $e->runId, expectedExecutionAttempt: $e->executionAttempt));
            $this->release();
        } else {
            $this->fail($e); // a pending approval or a live turn holds the thread
        }
    }

    /** Refusals, staging errors, timeouts and killed workers publish nothing: close the browser's stream here. */
    public function failed(Throwable $e): void
    {
        // A run that failed inside its segment already published RUN_ERROR and stream.failed.
        $run = app(WorkflowEngine::class)->inspect($this->threadId);
        if ($run?->runId === $this->runId && $run->status === WorkflowStatus::Failed) {
            return;
        }

        $channel = app(ChannelFactory::class)->make($this->threadId, $this->runId);
        foreach ($this->adapter()->error($e) as $event) {
            $channel->send($event);
        }
        $channel->failed($e, $this->threadId);
    }

    protected function adapter(): AGUIAdapter
    {
        return new AGUIAdapter(
            threadId: $this->threadId,
            runId: $this->input['runId'] ?? $this->runId,
            messages: $this->input['messages'] ?? [],
            state: $this->input['state'] ?? [],
        );
    }
}
```

The job gets its channel from a one-method interface of the app, bound in `NeuronServiceProvider::register()` to the delivery you use, for example `$this->app->bind(ChannelFactory::class, RedisRelay::class);` (below); tests bind one that returns a `FakeChannel`:

```php
namespace App\Neuron\Channels;

use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;

/** Where a queued run publishes its stream. The job asks for a fresh channel for every segment. */
interface ChannelFactory
{
    public function make(string $threadId, string $runId): StreamingChannelInterface;
}
```

- **Why `ExecutionRequest::start(..., runId:, recoverFailed: true)` and not `chat()`**: `chat()` cannot reserve a run ID. With it, a redelivery after a failure or a killed worker finishes the same run from its last step: the question is not asked twice and a tool that already ran is not run again. Use `chat($message, stream: true)` only where redelivery cannot happen; a buffered `chat()` streams no text to the channel.
- **Continuations are built at job time** from a fresh read, never from the state the controller saw. A continuation redelivered after its approved tools ran finds its run failed with no interrupt: `continueRun()` finishes that run instead of staging the answers again, which would fail with "There is no current interruption to answer" on every retry. The tools do not run again.
- **`settle()`** handles what the engine refuses: the job's own run still executing elsewhere waits for its lease; another job's dead run (failed, or running past its lease) is finished first, on a copy without this job's channel, then this job is released to start again (if that other job still had a retry queued, the retry asks its question once more, as any redelivery after success does); a pending approval or a live turn fails the job at once instead of burning retries. Never `abandon()` that dead run: `Agent::abandon()` throws when the history ends in a tool call, and when it succeeds the next start still fails with `ChatHistoryException`.
- **Redelivery after success runs the turn again**: a completed run's records are deleted, so a reserved start finds nothing to match. The window is small with the clocks below; retained completion is described in **neuron-workflow** ("Workflow Lifecycle").
- **`failed()` publishes the terminal pair only for failures the run did not report**: admission refusals, staging errors, timeouts and killed workers. A run that failed inside its segment on the last attempt already published `RUN_ERROR` and `stream.failed`, and a second pair would show the browser a second error.
- **Dispatch after commit.** An agent turn inside `DB::transaction()` joins it: a rollback erases the stored turn after the LLM was paid. Dispatch with `->afterCommit()` and never wrap a turn in a transaction.
- **Parallel tool calls** (`parallelToolCalls()`) fork the process: enable them only in queue or console workers, with `beforeChild` reconnecting database and Redis; see **neuron-agent**.

Three clocks keep a live run from being delivered twice and a dead one from blocking the thread:

| Rule | Why |
|---|---|
| Agent lease (600 s default, `leaseTimeout()`) > the longest single step: one inference, or one whole tool batch | A live run is never taken for dead |
| Job `$timeout` > the longest turn | Laravel kills a timed-out job with SIGKILL; its run stays `running` until the lease expires, then a redelivery recovers it |
| Queue `retry_after` > job `$timeout`: `DB_QUEUE_RETRY_AFTER=360` for `$timeout = 300` (`REDIS_QUEUE_RETRY_AFTER` on Redis); Laravel ships 90 | Otherwise a second worker takes the job while the first still streams, and a later copy runs the turn a second time |

Apply the same clocks to a Horizon supervisor that serves this queue.

### Delivering the job's output to the browser

**(a) Reverb (or Pusher) for a UI that listens on a websocket.** Bind `ChannelFactory` to a factory returning `new PusherChannel(Broadcast::connection('reverb')->getPusher(), "private-agent.{$threadId}", batchSize: 1)`, authorize `agent.{threadId}` in `routes/channels.php`, and let the browser subscribe before it posts the turn to an endpoint that dispatches the job and answers 202; `@neuron-core/streaming` consumes the channel. A queued approval posts the decisions to the same endpoint and arrives on the same channel. Read [references/reverb.md](references/reverb.md) when building it: it has the factory, the endpoint, the channel authorization and the browser code.

**(b) Redis relay for clients that need one SSE response** (the AG-UI `HttpAgent` or CopilotKit in the browser, `useChat`): the controller dispatches the job with the AG-UI input and relays the Redis channel named after the run with Neuron's `RedisChannelReader`, one SSE frame per protocol event until the segment ends; the job's `RedisChannel` is built with `awaitListener`, so its first publish waits for that subscription. It needs a real queue worker, in local development too: on the `sync` driver the job runs inside the request, before the response exists, and the browser receives nothing. Read [references/redis-relay.md](references/redis-relay.md) when building it: it has the relay class, the endpoint, and what the browser sees when a run fails, is killed or never starts.

## Stop Button

The provider hook already wraps its client in `StoppableHttpClient` with a predicate reading a cache flag (see The Agent). The Stop endpoint, `ThreadController::stop(ThreadRequest $request)`, raises the flag with `Cache::put(SupportAgent::stopKey($request->threadId()), true, now()->addMinutes(5))` and answers `response()->noContent()`. The predicate only reads it, once per streamed event, so the flag stays raised until the next turn: every turn endpoint clears it first (`Cache::forget(SupportAgent::stopKey($threadId))`), before finishing a failed turn.

The streamed answer ends at its next event: the client gets `TEXT_MESSAGE_END` and `RUN_FINISHED`, and history keeps the text so far with stop reason `stopped`. It works for queued turns as long as web and workers share the cache store. Only streamed inference stops: a Stop pressed before the first word, or during a tool step, fails the turn, and every retry of a queued turn stops too while the flag is up. The next message then finishes that turn before answering (`recoverFailedTurn()`), so the stopped answer is generated at that point. Details: **neuron-agent** (`references/providers.md`, "Stopping a streamed answer").

Wire the chat UI's stop control to the endpoint and keep reading the stream until `RUN_FINISHED`:

```ts
// The stream then ends with TEXT_MESSAGE_END and RUN_FINISHED; history keeps the text so far.
const stop = () => fetch(`/chat/threads/${threadId}/stop`, { method: "POST", headers: { "X-XSRF-TOKEN": xsrf } });
```

Never abort the fetch to stop (the AG-UI client's `abortRun()`): on `/chat/agui` that is a disconnect, which fails the run and loses the partial answer; on the relay endpoint it never reaches the worker, which finishes the whole answer.

Tests: `FakeAIProvider` replaces the provider and its Stop client, so a faked turn never stops. Test the flag through the endpoints, and a real stop with a test subclass that overrides `transport()` with a scripted stream (in [references/testing.md](references/testing.md)).

## Observability

Laravel's dispatcher is not PSR-14: forward Neuron's events through a small bridge, `App\Neuron\LaravelEventDispatcher implements Psr\EventDispatcher\EventDispatcherInterface`, that resolves Laravel's dispatcher for every event (so `Event::fake()` applies whenever a test calls it) and calls it inside `try`/`catch` + `report()` (a throwing listener on an event a node emits would otherwise fail the run), and set it on every container-built agent with `$this->app->afterResolving(Workflow::class, …)` in `NeuronServiceProvider`. Listen to concrete classes such as `NeuronAI\Workflow\Observability\WorkflowEnd` or to the wildcard `'NeuronAI\*'`: `Event::listen(ObservabilityEvent::class)` never fires, because Laravel does not match parent classes. Never queue listeners on live Neuron events. Read [references/observability.md](references/observability.md) for the bridge, the wiring and `LogListener` on a `neuron` log channel defined in `config/logging.php`; redaction, tracing and Neuron Cloud: **neuron-monitoring**.

## Evaluation

Evaluators are Neuron classes: `App\Neuron\Evaluators` in `app/Neuron/Evaluators`, their JSON datasets in `app/Neuron/Evaluators/datasets`. `vendor/bin/neuron make:evaluators 'App\Neuron\Evaluators\OrderAnswerEvaluator'` writes the class there; replace its body. `App\` already autoloads them and the command finds them by directory, so `composer.json` needs no entry.

`neuron:evaluate` (Setup, step 7) builds each evaluator through the container, so an evaluator receives the app's agent in its constructor and evaluates it as deployed, minus the conversation tables: it sets in-memory stores on it, `$this->agent->setMessageStore(new InMemoryMessageStore())->setPersistence(new InMemoryPersistence())`, and `run()` binds a fresh thread per item named like the app's own, `$this->agent->for("user-{$datasetItem['customer']}-".Str::uuid())->chat(...)`, so the tools act for the dataset's customer. Read [references/evaluation.md](references/evaluation.md) for the complete evaluators and their datasets.

- **Call `parent::__construct()`** in an evaluator with a constructor: `BaseEvaluator` prepares its assertions there; without it every item fails with `Error: Typed property …` before `evaluate()` runs.
- **The in-memory stores stay with this evaluator.** The agent is transient, so the setters change only the instance the container built for this evaluator and the copies made from it; `app(SupportAgent::class)` still returns the Eloquent store and database persistence everywhere else.
- **Forked items need the in-memory persistence too.** The container's `DatabasePersistence` keeps the PDO of the process that built it, which the child hooks cannot replace: kept on an evaluator's agent, every item failed under `--concurrency=4` ("MySQL server has gone away") and the parent's connection answered wrong afterwards.
- **The tools still use the database.** Approving a refund in an evaluation refunds that order. Select a dedicated database with Laravel's `--env`: `php artisan neuron:evaluate --env=evaluation` reads `.env.evaluation`, and the command refuses to run when it was not loaded (Setup, step 7). Rebuild that database before every run, CI included, with an `EvaluationSeeder` that creates the customer and the orders the datasets name: `php artisan migrate:fresh --seed --seeder=EvaluationSeeder --env=evaluation` ([references/evaluation.md](references/evaluation.md), "The evaluation database").
- **Multi-turn conversations** (`Conversation::make($this->agent)->withTurns(...)->withApprovals(...)->run()`) bind their own copies of the agent to `eval_<uuid>` threads. The copies share the in-memory stores, but no customer can be read from those threads: pin the tools in the constructor with `->setTools([LookupOrder::make(1), RefundOrder::make(1)])` for the customer the evaluation database is seeded with.

```bash
php artisan neuron:evaluate --env=evaluation -v                 # every evaluator in app/Neuron/Evaluators, one line each
php artisan migrate:fresh --seed --seeder=EvaluationSeeder --env=evaluation
php artisan neuron:evaluate --env=evaluation --concurrency=4    # the evaluation database, items in four processes
php artisan neuron:evaluate --env=evaluation --cache            # reuse unchanged run() outputs; --fresh re-runs and rewrites them
```

A directory argument, relative to the project root, runs the evaluators found there instead.

**CI.** The command exits 1 when an assertion fails or an evaluator errors, so it fails the build step; keep `storage/framework/cache/evaluation` between builds and run with `--cache`, plus a scheduled `--fresh` run (**neuron-evaluation**, "Caching in CI"). `--cache` replays `run()` until `run()`, its dataset item or a file in `cacheDependencies()` changes: every evaluator that receives the agent lists `SupportAgent::class` and each tool class there, or cached outputs survive a new prompt or tool (a prompt change still reported "Cached runs: 8 of 8").

**Without Artisan.** `vendor/bin/neuron evaluation app/Neuron/Evaluators --autoload-file=bootstrap/neuron.php` also works, from the project root, with a bootstrap file that boots the console kernel and a `'resolver' => fn (string $class): object => app($class)` entry in `evaluation.php`; `--concurrency` then needs the child hooks again as a `'runner'` entry. Both files are in [references/evaluation.md](references/evaluation.md). The Artisan command keeps all of it in one class, runs from any directory and refuses a missing `.env.evaluation`: prefer it.

Datasets, assertions, judges, trajectories and output drivers: **neuron-evaluation**.

## Testing

Swap the provider on the container's agent and hand that instance to the app. A setter beats the hook, and the `for()` copy the controller makes shares it:

```php
// tests/TestCase.php
use App\Neuron\Agents\SupportAgent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Testing\FakeAIProvider;

protected function fakeSupportAgent(Message ...$responses): FakeAIProvider
{
    $provider = new FakeAIProvider(...$responses);

    $this->app->instance(SupportAgent::class, $this->app->make(SupportAgent::class)->setAiProvider($provider));

    return $provider;
}
```

Script tool calls with `new ToolCallMessage(null, [ToolCall::make(name: 'refund_order', callId: 'call_1', inputs: ['order_id' => 1])])`, then drive the real routes: `postJson("/chat/threads/{$threadId}/messages", ['message' => 'Refund my order'])` answers `awaiting_approval`, the decisions answer `completed`, and `$provider->assertCallCount(2)`. A serialized message's text is at `message.content.0.content`.

- **Streams:** `postJson('/chat/agui', …)->assertStreamed()`, header `text/event-stream; charset=utf-8`, and the frames from `streamedContent()` (parse the `data:` lines). `getContent()` is `false` on a streamed response. Test error responses with `json('POST', …, ['Accept' => 'text/event-stream'])`, as the AG-UI client sends: `postJson()` asks for JSON and hides a 302.
- **Jobs:** bind a `ChannelFactory` that returns one `FakeChannel`, then `RunSupportAgent::dispatchSync(...)` and `$channel->assertCompleted()`. `$this->app->call([$job, 'handle'])` twice replays a redelivery. `Queue::fake()` plus `Queue::assertPushed()` covers the endpoint alone; for the relay endpoint assert the 200 and the SSE header, never its `streamedContent()`: with the sync queue the job runs before the relay subscribes, and the relay waits for its timeout.
- **Database:** `RefreshDatabase` works with `EloquentMessageStore` and `DatabasePersistence` on every driver: the persistence joins the test transaction through savepoints.
- **HTTP fakes do not apply:** `Http::fake()` and `Http::preventStrayRequests()` never see the provider's calls, which use Neuron's own HTTP client. Fake the provider.
- **Time:** leases and deadlines read `time()`; `travel()` and `Carbon::setTestNow()` do not move them.

Read [references/testing.md](references/testing.md) for the complete tests: the approval round trip in JSON and over AG-UI, JSON errors for event-stream clients, a failed turn finished before the next one, reload and paging, the queued turn and the relay endpoint, redeliveries, refused and failed jobs, and a real Stop. Fakes and their assertions: **neuron-test**.

## Pitfalls

- `env('ANTHROPIC_API_KEY')` in an agent → null after `config:cache`. Read `config('services.anthropic.key')`; never `$_ENV` or `getenv()`.
- `response()->eventStream()` → `event: update` lines and a `</stream>` frame. Use `response()->stream()` with a generator and `SSEEncoder`.
- Unguarded `ob_flush()` → `ErrorException` when no output buffer is active (`php artisan serve` has one, FPM with `output_buffering=0` does not). Use the generator form or guard with `ob_get_level() > 0`.
- Iterating the stream inside the callback without priming → refusals become `RUN_ERROR` under a 200. Call `$events->valid()` in the controller.
- `routeIs()` in `trimStrings()`/`convertEmptyStringsToNull()` exceptions → never matches before routing. Use `$request->is('chat/*')`.
- The agent as a `singleton` → it keeps the persistence and PDO it was built with for a worker's life. Resolve per request or job and call `for()`.
- `SupportAgent::make()` or `make(workflowId: …)` → `ArgumentCountError` or "Unknown named parameter $workflowId": the agent has its own constructor. Resolve it from the container and bind it with `for()`.
- A promoted constructor property named like an Agent property → a fatal error when the types differ (`$messageStore`, `$persistence`, `$channel`), a silent override when they match (a promoted `$provider` bypasses the `provider()` hook). Use `$conversations`, `$runs`, `$llm`.
- A new turn after a failed one → `ChatHistoryException` when the failed turn had stored its question. Finish it first (`recoverFailedTurn()`); in a job, finish another job's dead run, never `abandon()` it.
- The skeleton's `shouldRenderJsonWhen()` on the agent routes → validation errors are 302s and refusals HTML for event-stream clients. Add `chat/*` to it.
- `DatabasePersistence` as a `singleton` → a stale PDO after a reconnect. Use `bind`, or `EloquentPersistence`.
- `string()` ID columns on MySQL/MariaDB → threads differing by case share a history. Use `binary()` there; `collation('ascii_bin')` on PostgreSQL fails ("collation does not exist").
- MySQL `'strict' => false` → `PersistenceException` on the first write. Keep strict mode.
- An agent turn inside `DB::transaction()` → a rollback erases the paid turn. Dispatch with `->afterCommit()`, never wrap.
- `retry_after` ≤ job `$timeout` → a second worker takes the running job and the turn runs twice. Keep `retry_after` > `$timeout` > the longest turn: `DB_QUEUE_RETRY_AFTER=360` for `$timeout = 300` (Laravel ships 90).
- The Redis relay on the `sync` queue driver → the job runs before the response exists and the browser gets nothing. Run `php artisan queue:work`.
- `abortRun()` (aborting the fetch) as the Stop button → a disconnect: the run fails and the partial answer is lost, or, on the relay, the worker finishes the whole answer. Post to the stop endpoint and keep reading.
- `now()` passed to an `ApprovalRequest` expiry, `awaitEvent()` or `sleepUntil()` → `TypeError` (mutable Carbon). Pass `now()->addDay()->toDateTimeImmutable()`.
- Eloquent models in workflow state → the serializer stores the whole object graph with every step. Keep IDs in state and load models in nodes; jobs carry scalars.
- `auth()` in a tool or a job → no user in a worker. Carry the user in the thread ID or the job.
- `Http::fake()` to fake the LLM → the real provider is called. Use `FakeAIProvider`.
- `vendor/bin/neuron evaluation` or an evaluation command without `chdir()` → `evaluation.php` is ignored outside the project root. Use `php artisan neuron:evaluate`.
- `--env=evaluation` with cached config or no `.env.evaluation` → evaluations run on the main database. `neuron:evaluate` refuses both; run `php artisan config:clear` and create the file.
- `--concurrency` without child hooks → forked children share the parent's database socket and break it. Use the hooks of `neuron:evaluate`; `DB::purge()` alone still closes the parent's session.
- An evaluator using the container's agent as is → its turns are written to `chat_messages` and `workflow_store`, and forked items share the parent's PDO through its `DatabasePersistence`. Set in-memory stores on it.

## Related

- **neuron-agent** — agent hooks, providers and the Stop client, conversation memory, parallel tool calls.
- **neuron-frontend-integration** — AG-UI, CopilotKit and Vercel payloads, browser tools, continuation rules, client-side reload.
- **neuron-tool-approval** — gating tools, decision maps, the approval UI and its reload.
- **neuron-streaming** — adapters, terminal frames, channels, the envelope and `@neuron-core/streaming`.
- **neuron-workflow** — persistence backends, `EloquentPersistence` tables, leases, reserved runs, retained completion.
- **neuron-evaluation** — evaluators, datasets, assertions and judges, `Conversation` and trajectories, output drivers, the run cache.
- **neuron-tool** — writing tools and toolkits with dependencies.
- **neuron-test** — `FakeAIProvider`, `FakeChannel` and the other fakes.
- **neuron-monitoring** — events, `LogListener` redaction, Neuron Cloud for Laravel.
