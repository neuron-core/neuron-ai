# Neuron core improvements for framework integration

This document specifies the changes to Neuron core (the 4.x branch, where backward compatibility is not a constraint) that turn it into the durable AI layer for the whole PHP ecosystem. It starts from what the integration packages for Laravel and Symfony need, but every proposal is framework-neutral: the aim is that any host, from Laravel and Symfony to Laminas, Spiral, Yii, Slim, WordPress or a plain PHP application with cron and a queue, can run Neuron correctly by wiring a handful of standard PHP contracts to the reference gateway that core ships (C14). It lists the defects found while reviewing every subsystem (D1 to D18), then 25 design proposals (C1 to C25) with the evidence behind each one, the benefit for each framework, the alternatives considered, the breaking impact and a priority. The companion documents [neuron-laravel-integration.md](neuron-laravel-integration.md) and [neuron-symfony-integration.md](neuron-symfony-integration.md) refer to these proposals as "core improvement C<n>" and describe the interim approach against today's core.

How to read this document: sections 1 to 3 give the thesis (with a short glossary at the end of section 1), the integration contract and the principles; section 4 lists defects to fix regardless of design decisions; section 5 holds the proposals grouped by theme, so they do not appear in numeric order, and section 7's table lists every proposal by ID with its priority and dependencies; sections 6 to 8 cover placement, sequencing and the decisions the maintainer has to take. Every API marked as proposed, with its ID (for example "(proposed, C2)" or a "Proposed (C2)" comment in a sketch), does not exist yet; every citation of the form `src/...php:line` describes the current code of this branch.

## 1. Why this document

Neuron's core value is durable execution. A run is owned through fenced optimistic writes on a single `__control` record; interruptions (approvals, awaited events, timers, deferred tool results) are persisted and survive any process; completed steps and memoized sub-operations make recovery free, because replay reuses what was already committed; leases detect dead workers; retained completions make the final answer replayable until the caller acknowledges it. None of this depends on a framework, and all of it is exercised the moment a framework hosts Neuron.

Both major PHP frameworks now ship first-party AI layers: laravel/ai reached 1.0 in September 2026, and Symfony AI is at 0.14. Both are capable prompt layers with tools, structured output and streaming, and both have added some form of human tool approval. Neither has suspend and resume, fenced recovery, memoized replay, timers or deadlines: a laravel/ai pause is a record in the conversation store, and a Symfony AI execution is an in-memory lazy object that can be cancelled but not re-consumed. The Neuron packages should therefore not compete on prompt ergonomics. They position Neuron as the durable orchestration layer that coexists with the first-party layers: laravel/ai and Symfony AI answer a prompt; Neuron finishes a process.

Framework integration is where durability is won or lost in production. Queues deliver at least once and redeliver after worker crashes; deploys replace code under runs that have been suspended for days; browsers disconnect in the middle of a stream; Octane, FrankenPHP, RoadRunner, Horizon and `messenger:consume` keep objects alive across thousands of requests; several hosts share one store while each compares leases and deadlines against its own wall clock. Today's core mostly allows a correct integration, but it relies on conventions that every package must rediscover and get exactly right:

- definitions must be registered as prototypes, because a Workflow instance binds its identity once and generates one on an unbound start (`src/Workflow/Workflow.php:122-130`, `:273`);
- framework defaults can only be applied through setters that silently override a subclass's own hooks (`src/Workflow/HandleComponents.php:89-97`), so packages resort to reflection checks;
- most refusals are plain `WorkflowException` messages, so queue retry policies and HTTP status mappings end up matching strings (`src/Workflow/WorkflowEngine.php:108`, `:123-126`, `:286-289`, `:382-385`, `:404-406`);
- deadlines are read through `instanceof` checks over interrupt subtypes, four times in core alone (`src/Workflow/WorkflowEngine.php:355-370`, `src/Exceptions/RunInFlightException.php:74-89`, `src/Agent/Frontend/AGUIInputTranslator.php:118-119`, `src/Agent/Adapters/AGUIAdapter.php:706`);
- a streaming endpoint must prime the lazy generator by hand to get admission errors before the first byte (`src/Workflow/Workflow.php:258-296`), and a client disconnect leaves the run `running` under a ten-minute lease (`src/Workflow/Executor/Segment.php:96-100`, `src/Agent/Agent.php:111-114`).

The proposals below remove those conventions. After them, the integration surface of core reduces to standard PHP contracts plus one reference gateway, which is what "the AI layer of the entire PHP ecosystem" means concretely.

The three documents share a small vocabulary. Terms that name proposed concepts are marked with their proposal.

| Term | Meaning |
|---|---|
| Definition | A Workflow, Agent or RAG instance: the graph, its hooks, factories and configured collaborators. After C2 it is shareable and never bound to an address. |
| Handle | A definition bound to one workflow ID by `for()` (proposed, C2). It lives for one call and never enters a container. |
| Workflow ID | The address of a run's durable records; for an Agent, the thread ID of its conversation. |
| Run | One execution of a definition at a workflow ID, identified by its run ID, from its start until it completes (and, when its completion is retained, is acknowledged) or is abandoned. At most one run per workflow ID is in flight. |
| Segment | One admitted stretch of a run in one process, from admission until the run suspends, completes or fails. |
| Execution attempt | A counter on the run, 1 for a new run (0 for a pending one, C9), that grows with every claim: each continuation that resumes or recovers the run, and an idle poll that re-suspends a failed run. It fences recoveries. |
| Interrupt | A persisted pause (approval, awaited event, timer, deferred tool result) with a run-scoped, monotonic ID, resolved by input or by its deadline. |
| Fence | The expected run ID, execution attempt or, after C8, interrupt ID a request carries; the engine refuses a request whose fence no longer matches. |
| Lease | The expiry a running segment renews at every step commit. While it is fresh no other process can take the run over; once it expires, a fenced continuation recovers the run. |
| Retained completion | The final state of a completed run, kept until the caller calls `acknowledge()`. |
| Invocation | The JSON-safe message the reference gateway executes: a definition name, a workflow ID, fences and a payload (proposed, C14). |
| Disposition | The gateway's verdict on one invocation, done, retry at, discard or fail, which a package maps onto its queue verbs (proposed, C14). |
| Projection | A platform-side row per workflow ID (`neuron_runs`) derived only from returned state or `inspect()`, used to schedule timers and rechecks; an expiring hint, never the truth (proposed, C14). |
| Sweep | The periodic, locked job that calls `Gateway::due()` and dispatches what it yields (proposed, C14). |

## 2. The integration contract of core

The target contract is small on purpose. Core defines interfaces, the engine, canonical schemas, conformance tests and one reference consumer of the engine (the gateway); a framework package supplies implementations wired from its container and configuration. The table maps each integration concern to the standard it rides on. Seams marked (today) exist on this branch; seams marked (proposed) are introduced by the proposal in the last column.

| Concern | Standard contract | Core seam | Proposal |
|---|---|---|---|
| App-wide collaborators for definitions | PSR-11 `ContainerInterface` | `Workflow::setDefaults()` (proposed), consulted by the base hooks | C3 |
| Definitions addressable by name | PSR-11 `ContainerInterface` | `Gateway` constructor and `#[AsWorkflow(name:)]` (proposed) | C14, C19 |
| Events and telemetry | PSR-14 `EventDispatcherInterface` | `setEventDispatcher()` (today); a defaults key and `EventRecord` (proposed) | C3, C22 |
| Time | PSR-20 `ClockInterface` | `WorkflowEngine` constructor argument and defaults key (proposed) | C10 |
| Logs | PSR-3 `LoggerInterface` | `LogListener` (today), with per-event levels (proposed) | C22 |
| Durable storage | `PDO` (today) or `Closure(): PDO` (proposed), `\Redis` | `DatabasePersistence`, `SQLMessageStore`, `RedisPersistence`, or any `PersistenceInterface` (today) | C13, C16 |
| HTTP | `HttpClientInterface` | Curl, Guzzle and Amp adapters (today) and a Symfony adapter (proposed) in core; framework adapters in packages | C17 |
| Execution over queues | JSON `Invocation` (proposed) | `NeuronAI\Gateway` (proposed) | C9, C14 |
| Live push | `StreamingChannelInterface` | `PusherChannel` and `RedisChannel` (today), `MercureChannel` (proposed) | C16 |

With that contract in place, a framework package implements the following, and nothing else touches engine semantics:

1. Discover definitions by their `#[AsWorkflow]` name and expose them to the gateway as a PSR-11 locator (C14, C19).
2. Build one PSR-11 defaults locator from configuration and apply it to every definition with `setDefaults()` (C3).
3. Provide the storage: a `PersistenceInterface`, a `MessageStoreInterface` and a `ProjectionStoreInterface` over the framework's connections, each passing the core contract test case (C13, C14).
4. Provide an `HttpClientInterface` adapter over the framework HTTP stack when the core adapters are not enough, passing `HttpClientContractTestCase` (C17).
5. Bridge PSR-14 events to the framework dispatcher, forwarding serializable `EventRecord`s to queued listeners (C22).
6. Bind a PSR-20 clock that the framework's test helpers can move (C10).
7. Carry `Invocation::toArray()` in one queue message type, translate each returned `Disposition` into the queue's verbs, and schedule the sweep that calls `Gateway::due()` (C14).
8. At the HTTP edge, authorize thread ownership before `for()`, stream through `SSEStream`, and map typed exceptions to statuses (C2, C6, C15).
9. Ship operational commands, generators and test fakes in the framework's idiom.
10. Validate the durability configuration without I/O, at boot or compile time: no in-memory or file persistence in production, persistent queue transports only, the lease and timeout rules of the gateway (C14), and no invocation inside an open transaction on the persistence connection (C13).
11. Bind an idempotent `CompletionHandlerInterface`, and hand every invocation the transport gives up on to `Gateway::exhausted()`, which parks the projection row of a failed run (C14).
12. Prune stale runs through the definition's own `abandon()`, fenced by the projected run ID and attempt, and settle a user's threads when their data is erased.

The gateway is a consumer of the engine's public API, the same way `Agent` is a reference composition of the component catalog. It adds no scheduler interface to the engine and no callbacks into it; the engine stays unaware of queues, schedulers and frameworks.

The checklist is short enough that a host without a framework can follow it by hand. The sketch below wires a plain PHP application with a queue client and cron. Apart from the constructors of `DatabasePersistence`, `SQLMessageStore`, `Anthropic` and `WorkflowEngine`, every Neuron API in it is proposed; the `Closure` connection arguments are C13 and the engine's clock argument is C10. Imports are omitted. `$config` holds the credentials, `$queue` stands for the host's queue client, shown with Beanstalkd's verbs (put, reserve, delete, release with a delay, bury) and illustrative job fields (`body`, `attempts`), and `$logger` is any PSR-3 logger.

```php
// bootstrap.php, shared by the web entry point, the worker and the sweep.
$pdoFactory = static function () use ($config): PDO {
    static $pdo = null;

    return $pdo ??= new PDO($config['dsn'], $config['db_user'], $config['db_password']);
};

$clock = new SystemClock();                                                         // proposed, C10
$persistence = new DatabasePersistence($pdoFactory, 'neuron_workflow_store');       // Closure: proposed, C13

$defaults = new Defaults([                                                          // proposed, C3
    PersistenceInterface::class => $persistence,
    MessageStoreInterface::class => new SQLMessageStore($pdoFactory, 'neuron_chat_messages'),
    AIProviderInterface::class => new Anthropic(key: $config['anthropic_key'], model: $config['anthropic_model']), // shared after C5
    ClockInterface::class => $clock,
]);

// Any PSR-11 container keyed by #[AsWorkflow] name (C19); here pimple/pimple's PSR-11 wrapper.
$workflows = new Pimple\Psr11\Container(new Pimple\Container([
    'support' => fn (): SupportAgent => (new SupportAgent())->setDefaults($defaults), // one shared definition (C2, C3)
]));

$gateway = new Gateway(                                                             // proposed, C14
    $workflows,
    new WorkflowEngine($persistence, clock: $clock),                                // no verb uses it: see section 8
    new DatabaseProjectionStore($pdoFactory, 'neuron_runs'),
    $clock,
    new App\CompletedRuns(),                                                        // CompletionHandlerInterface, idempotent by run ID
);
```

```php
// worker.php: a long-running consumer. Each message is {"invocation": {...}, "firstDispatchedAt": <unix time>}.
$discard = function (object $job, string $reason) use ($logger, $queue): void {
    $logger->info('neuron.invocation.discarded', ['reason' => $reason]);
    $queue->delete($job);
};

$deadLetter = function (object $job, Invocation $invocation) use ($gateway, $queue): void {
    $gateway->exhausted($invocation); // reconciles the row from inspect(); parks a failed run
    $queue->bury($job);
};

while ($job = $queue->reserve()) {
    $message = json_decode($job->body, true, flags: JSON_THROW_ON_ERROR);
    $invocation = Invocation::fromArray($message['invocation']);

    try {
        $disposition = $gateway->invoke($invocation);
    } catch (Throwable $e) {
        // A node failure: the run is failed durably, and the next delivery recovers it through its fence.
        $logger->warning('neuron.invocation.failed', ['exception' => $e]);
        $job->attempts < 5 ? $queue->release($job, delay: 30 * $job->attempts) : $deadLetter($job, $invocation);
        continue;
    }

    $now = $clock->now()->getTimestamp();
    $signalExpired = $message['invocation']['kind'] === 'signal' && $now - $message['firstDispatchedAt'] > 3600;

    match ($disposition->kind) {
        DispositionKind::Done => $queue->delete($job),
        DispositionKind::RetryAt => $signalExpired
            ? $discard($job, 'signal window elapsed')
            : $queue->release($job, delay: max(1, $disposition->retryAt - $now)),
        DispositionKind::Discard => $discard($job, $disposition->reason),
        DispositionKind::Fail => $deadLetter($job, $invocation),
    };
}
```

```php
// sweep.php, run by cron every minute on one host; the lock skips a run while the previous one is still busy.
$lock = fopen(__DIR__.'/sweep.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

foreach ($gateway->due(500) as $invocation) {
    $queue->put(json_encode(['invocation' => $invocation->toArray(), 'firstDispatchedAt' => time()], JSON_THROW_ON_ERROR));
}
```

The HTTP edge, omitted here, authorizes the thread, calls `ignite()` on the handle, saves the `pending` projection row and puts `Invocation::resume()` on the queue (C9, C14). Nothing in the three scripts knows about fences, leases or refusals: that is the gateway's job, and it is the same code the Laravel package and the Symfony bundle run.

## 3. Design principles

These principles are shared by the three documents and use the same names everywhere. Each proposal in section 5 serves at least one of them.

1. **Definitions are shared, executions are bound.** A Workflow, Agent or RAG instance built by a container is a definition and is safe to share. `$definition->for($id)` returns a bound execution handle that lives for one call and never enters the container (C2).
2. **Configure once, at the lowest precedence.** Frameworks supply app-wide collaborators through one defaults tier that the base hooks consult. Precedence is: explicit setter, then the class's own hook, then framework defaults, then the core fallback (C3).
3. **Constructors belong to the application and do no I/O.** No Neuron constructor opens connections, fetches credentials, creates collections or spawns processes. Provisioning is an explicit `setup()` step, and subclasses own their constructors (C4).
4. **Shared means stateless.** Anything a container may share holds only immutable configuration and shareable collaborators. Per-call data travels in arguments: `ProviderRequest`, `ToolContext`, `ExecutionContext` (C5, C11, C18).
5. **The returned outcome is the only scheduling signal.** Timers, projections, retries and completion hooks derive from the state `run()` returns, or from `inspect()`, before a job is acknowledged. Observability listeners are telemetry, never schedulers.
6. **Every delivery is a fenced continuation.** Queues deliver at least once. Correctness comes from core fences, never from broker deduplication, which only saves cost.
7. **Names and JSON cross process boundaries; PHP objects stay in the workflow store.** Messages carry a definition name, a workflow ID, fences and a JSON payload.
8. **Monitoring never changes execution.** A failing listener can never fail a step (C22).
9. **Time and failure are typed.** Time is a PSR-20 clock (C10). Every refusal that depends on run state is a typed exception with structured fields (C6).
10. **Persisted records survive deploys.** Engine records are versioned, incompatibilities are typed, and definitions can declare a version (C12).
11. **Core owns contracts, schemas and conformance tests; adapters live where their conventions live.** Section 6 gives the placement rule.

## 4. Defects found during the review

These defects were re-verified against the source of this branch; several were reproduced with a small script. They should be fixed regardless of the design decisions in section 5, because each one corrupts durable state, leaks data across users, breaks a common framework path or silently skews evaluation results. Where a proposal later provides the structural fix, the defect names it; the minimal fix is still worth shipping first.

### D1. Provider stream state leaks into later calls on the same instance

`OpenAI::enrichMessage()` copies every metadata entry of `$this->streamState` into the message whenever that property is set (`src/Providers/OpenAI/OpenAI.php:123-135`), and the buffered path calls it too (`src/Providers/OpenAI/HandleChat.php:89`). `stream()` assigns a new `StreamState` when a stream starts (`src/Providers/OpenAI/HandleStream.php:58`) and never clears it. `Deepseek` accumulates `reasoning_content` into that state (`src/Providers/Deepseek/Deepseek.php:102`, `:125`) and inherits the enrichment (`:71-86`); `DashScopeOpenAI` goes through the same enrichment (`src/Providers/Alibaba/DashScopeOpenAI.php:27-30`) but writes no stream metadata today, so `Deepseek` is the provider that leaks now, and any subclass that follows the documented `accumulateMetadata()` hook (`src/Providers/OpenAI/HandleStream.php:132`, `:166`) would leak the same way. A reproduction on one `Deepseek` instance confirmed it: after a `stream()` whose deltas carried reasoning, a later buffered chat returns a message whose `reasoning_content` metadata is the earlier stream's reasoning. `Anthropic` keeps `$stopReason` on the instance and does not reset it when a stream starts (`src/Providers/Anthropic/HandleStream.php:25`, `:45`, `:96-97`, `:126`), so a stream that ends without a `message_delta` event reports the previous stream's stop reason.

The cross-user impact appears whenever one provider instance serves more than one conversation, which is exactly what framework runtimes do by default: with a provider shared by a Laravel manager, a Symfony shared service or an Octane worker, one user's reasoning text is attached to another user's message, written to chat history and committed in the durable `inference` memo (`src/Agent/Nodes/ChatNode.php:54`). The minimal fix keeps stream state local to each `stream()` call, passes it explicitly to `enrichMessage()`, and adds a "stream, then chat on the same instance" regression test. The structural fix is C5.

### D2. SSEParser drops any data line that contains "DONE"

`SSEParser::parseNextSSEEvent()` returns null as soon as `str_contains($line, 'DONE')` (`src/Providers/SSEParser.php:35-37`). A reproduction confirmed that `data: {"choices":[{"delta":{"content":"Task DONE."}}]}` is discarded. Any chunk whose JSON contains the substring, whether in text, tool arguments or identifiers, disappears from the live stream and from the final message, and the truncated message is then memoized. The fix compares the payload with `[DONE]` exactly (C17).

### D3. HTTP errors can become durable answers

`AmpHttpClient::request()` and `stream()` return the response whatever its status (`src/HttpClient/Amp/AmpHttpClient.php:59-79`, `:132-148`), while `CurlHttpClient` raises `HttpException::statusError()` (`src/HttpClient/Curl/CurlHttpClient.php:135-136`, `:160-170`) and `src/HttpClient/AGENTS.md:18` promises that both methods throw for status 400 and above. `HttpResponse::json()` returns an empty array for a body that is not JSON (`src/HttpClient/HttpResponse.php:28-31`). The Anthropic and OpenAI Chat Completions stream loops have no branch for vendor error events (`src/Providers/Anthropic/HandleStream.php:47-73`, `src/Providers/OpenAI/HandleStream.php:66-115`), unlike Gemini (`src/Providers/Gemini/HandleStream.php:92-93`) and OpenAI Responses (`src/Providers/OpenAI/Responses/HandleStream.php:155`).

Together these swallow errors. Feeding a JSON error body to the OpenAI stream loop, as a status-blind client does, produces an `AssistantMessage` with no content blocks (reproduced). So with `AmpHttpClient`, a 429 or a 500 on the streaming path becomes an empty answer that `ChatNode` memoizes (`src/Agent/Nodes/ChatNode.php:54`) and replays forever, and the queue never retries because nothing was thrown; on its buffered path the failure surfaces as undefined-index warnings or a `TypeError` that carries no status code for a retry policy. With any client, including the default `CurlHttpClient`, a vendor error event sent mid-stream after a 200 is skipped by the Anthropic and Chat Completions loops, and the truncated answer is memoized the same way. The fix raises at header arrival in every client, refuses invalid JSON, and maps vendor error events to `ProviderException` (C17).

### D4. AzureOpenAI and HuggingFace cannot be constructed

Both classes redeclare `protected string $baseUri;` without a default (`src/Providers/OpenAI/AzureOpenAI.php:16`, `src/Providers/HuggingFace/HuggingFace.php:17`) and then read it in `sprintf()` (`AzureOpenAI.php:40`, `HuggingFace.php:41`). Instantiating either throws `Error: Typed property ...::$baseUri must not be accessed before initialization` (reproduced). Once the template is restored, the Azure URL is still wrong: the constructor appends `?api-version=...` to the base URI (`AzureOpenAI.php:41`) and `OpenAI::createChatHttpRequest()` appends `/chat/completions` after it (`src/Providers/OpenAI/OpenAI.php:69`), which puts the path after the query string. Config-driven factories offering `azure`, a common enterprise requirement, or `huggingface` fail at first resolution. The fix restores the templates, builds the Azure query last, and adds a smoke test that constructs every provider class with dummy configuration; C5's constructor convention makes the pattern uniform.

### D5. SQLMessageStore can lose messages silently

`serializeMessage()` calls `json_encode()` without `JSON_THROW_ON_ERROR` (`src/Chat/History/SQLMessageStore.php:184-185`). On invalid UTF-8, for example a tool result read from a legacy file, it returns `false`, which PDO binds as an empty string and which reads back as null content. The constructor does not check the PDO error mode (`SQLMessageStore.php:47-55`), unlike `DatabasePersistence` (`src/Workflow/Persistence/DatabasePersistence.php:65-67`), so a PDO configured with `ERRMODE_SILENT` drops failed writes without a trace. The fix always uses `JSON_THROW_ON_ERROR`, optionally combined with `JSON_INVALID_UTF8_SUBSTITUTE` as `SSEEncoder` does (`src/Workflow/Streaming/SSEEncoder.php:54`) when lossy substitution is preferred to a refused write, and the same error-mode check as `DatabasePersistence`; C13 caches that check per connection.

### D6. The SQL toolkits abort runs on driver errors

`Tool::execute()` passes an explicit null for every optional property the model omitted (`src/Tools/Tool.php:363-366`). `MySQLWriteTool::__invoke()` iterates `foreach ($parameters as ...)` without coalescing null (`src/Tools/Toolkits/MySQL/MySQLWriteTool.php:64-72`), while the three sibling tools do (`MySQLSelectTool.php:98`, `PGSQL/PGSQLSelectTool.php:109`, `PGSQL/PGSQLWriteTool.php:69`). A write without parameters therefore raises a PHP warning, which Laravel's error handler escalates to an `ErrorException`. None of the four tools catches `PDOException`; the branch that formats a database error for the model (`MySQLWriteTool.php:76-79`) is unreachable under `ERRMODE_EXCEPTION`, the PHP 8 default, and `ToolNode::handleError()` rethrows when no handler is configured (`src/Agent/Nodes/ToolNode.php:478-487`). A malformed query from the model fails the whole durable run instead of returning an error the model can correct. The fix coalesces the parameters and returns `ToolOutput::error()` with a redacted driver message.

### D7. ElasticsearchVectorStore re-sends earlier chunks

`addDocuments()` initializes the bulk parameters once, before the chunk loop, and calls `bulk($params)` after each chunk of 100 documents without resetting them (`src/RAG/VectorStore/ElasticsearchVectorStore.php:138-161`); the documents carry no `_id`. Indexing n chunks sends the first chunk n times, the second n-1 times, and so on, and every re-send creates new documents. The fix builds the parameters per chunk and sends `_id`, which C21's upsert contract requires anyway.

### D8. One nullable MCP schema breaks the connector, and isError is ignored

`McpConnector::tools()` maps every listed tool through `createTool()` (`src/MCP/McpConnector.php:112-122`), which passes the input schema to `ToolPropertyFactory::fromSchema()` (`:137`). `assertSupportedSchema()` throws on `anyOf`, `oneOf`, `allOf`, `$ref` and `prefixItems` (`src/Tools/ToolPropertyFactory.php:103-114`), so the shape Pydantic emits for optional parameters, `{"anyOf":[{"type":"string"},{"type":"null"}]}`, common in Python MCP servers, makes `tools()` throw for the whole server and the agent cannot start. `invokeTool()` returns `result.content` whatever `result.isError` says (`McpConnector.php:155-172`), so a tool-level failure is presented to the model as a successful result and memoized. The fix understands nullable `anyOf`, passes schemas it cannot represent through raw, and maps `isError` to `ToolOutput::error()` (C18).

### D9. TokenCounter fetches user-supplied URLs and paths

`TokenCounter::handleImageBlock()` calls `@getimagesize($input)` on any image content that is not base64 (`src/Chat/History/TokenCounter.php:94-125`, fetch at `:118`). On every `ChatHistory::addMessage()` (`src/Chat/History/ChatHistory.php:62`) the trimmer counts each message after the last assistant message that carries provider usage, and every message when none does (`src/Chat/History/HistoryTrimmer.php:154-169`, `:275`, `:291`), so an append to a conversation whose recent tail contains image URLs performs server-side HTTP fetches (with `allow_url_fopen`) or local file reads chosen by the user: a server-side request forgery vector, latency inside the step, and a probe of local paths. A base64 payload that is not an image makes `getimagesizefromstring()` return false, and the next line throws a `TypeError` (`TokenCounter.php:111-112`, reproduced). The fix never fetches: it estimates from declared dimensions or a fixed budget, and treats undecodable data as a fixed cost.

### D10. EvaluatorDiscovery skips final and readonly classes

`EvaluatorDiscovery::getClassesFromFile()` matches `/^class\s+(\w+)/m` (`src/Evaluation/EvaluatorDiscovery.php:86`), so `final class` and `readonly class` evaluators are never run and the evaluation reports success. The fix is token-based discovery (C23).

### D11. ParallelToolNode rebuilds child exceptions unsafely

A child failure is serialized as class, message and code (`src/Agent/Nodes/ParallelToolNode.php:145-153`) and rebuilt in the parent with `new $exceptionClass($message, (int) $code)` (`:165-172`). `HttpException` takes `?HttpRequest` as its second parameter (`src/Exceptions/HttpException.php:16-23`), so rebuilding it throws a `TypeError` that replaces the original failure; the same happens for every exception with a different constructor, such as `RunInFlightException`, and a `PDOException`'s SQLSTATE code is truncated by the cast. The fix carries child failures in one wrapper exception that holds the original class, message and code as data (C25).

### D12. StdioTransport hands the application environment to MCP servers

`StdioTransport::connect()` starts the server process with `array_merge(getenv(), $env)` (`src/MCP/StdioTransport.php:74-89`). Database passwords, Laravel's `APP_KEY`, Symfony's `APP_SECRET` and provider API keys present in the environment are handed to third-party MCP server processes. The fix is an environment allowlist (`PATH`, `HOME`, locale, `TMPDIR`) plus the configured variables, with an explicit opt-in to inherit everything (C18).

### D13. HttpException exposes credentials and full bodies

`HttpException` keeps `public readonly ?HttpRequest $request` (`src/Exceptions/HttpException.php:18`), whose public headers carry the provider key, for example `'Authorization' => 'Bearer ' . $this->key` (`src/Providers/OpenAI/OpenAI.php:59-63`). `statusError()` embeds the full response body in the message (`HttpException.php:25-33`), and the Guzzle client does the same (`src/HttpClient/Guzzle/GuzzleHttpClient.php:258-263`). Error trackers and debug pages that dump exception properties capture API keys, and logs capture bodies that may echo user prompts. The fix stores a redacted request, marks keys and tokens `#[\SensitiveParameter]`, and truncates bodies in messages (C17).

### D14. RunInFlightException gives reserved starts advice that cannot work

A reserved start, one whose run ID the caller supplies, never recovers a failed run and never sweeps a dead generation (`src/Workflow/WorkflowEngine.php:203`, `:214`, `:228-233`). Yet the refusal message for a failed run and for an expired lease tells the caller to "Retry the ignition" (`src/Exceptions/RunInFlightException.php:50-51`, `:70-71`), which can never succeed for the same reserved start. A queue job that uses its delivery ID as the reserved run ID and follows the message loops until its retry budget runs out. The fix names the verb that settles the state, `resume(expectedRunId:)` or `abandon()` (C6).

### D15. RetrievalTool bypasses the retrieval scope

`RAG` enforces its mandatory scope in `RetrievalNode`, which merges it with the filters of the event (`src/RAG/Nodes/RetrievalNode.php:41`, scope resolved in `src/RAG/ResolveRetrieval.php:23-37`). `RetrievalTool` calls `retrieve(new UserMessage($query))` with no filters (`src/Tools/Toolkits/RetrievalTool.php:36-39`). An application that isolates tenants with `setRetrievalScope()` and also gives an agent a `RetrievalTool` over the same retrieval exposes every tenant's documents through the tool. The fix makes the scope a mandatory constructor argument of the tool (C21).

### D16. Vector stores disagree on what a search returns

`MongoDBVectorStore` casts every metadata value to a string on read (`src/RAG/VectorStore/MongoDBVectorStore.php:181-186`). `MeilisearchVectorStore` caps the limit at 20 whatever `topK` says (`src/RAG/VectorStore/MeilisearchVectorStore.php:141`). `OpenSearchVectorStore` sets `k = max(50, topK * 4)` but no `size` (`src/RAG/VectorStore/OpenSearchVectorStore.php:201-236`), so the number of hits follows OpenSearch's default page size (10) rather than `topK`. The same retrieval configuration therefore returns different counts and types depending on the store, and typed filters and schema validation break after a store switch. Each store needs its fix, and `VectorStoreContractTestCase` (C13) keeps them aligned.

### D17. Documentation and skills drift

Several documents describe APIs that do not exist, and framework documentation copied from them would ship broken code:

- `skills/neuron-streaming/SKILL.md:120` names a `NativeAdapter`; the class is `AgentChunkAdapter` (`src/Agent/Adapters/AgentChunkAdapter.php:31`).
- `src/Tools/AGENTS.md:49`, `:64` and `src/Agent/Frontend/README.md:136` document a `DeferredTool` class; the class is `FrontendTool`, which implements `DeferredToolInterface`.
- `src/Agent/Frontend/README.md:51-53` documents a per-invocation `prepare` closure on `run()` and `events()`, which accept only an `ExecutionRequest` (`src/Workflow/Workflow.php:210`, `:258`); `src/Workflow/AGENTS.md:156` itself says there is no such callback.
- `src/Agent/AGENTS.md:16`, `:29` and `src/Providers/AGENTS.md:32` call `env()` inside hooks, which returns null once Laravel's configuration is cached.
- `skills/neuron-streaming/SKILL.md:142` calls `ob_flush()` without checking `ob_get_level()`; without an output buffer PHP emits a notice, which Laravel turns into an `ErrorException`.
- `src/Agent/AGENTS.md:269` says `resetConversation()` "frees the thread unconditionally", but it goes through `Workflow::abandon()` (`src/Agent/Agent.php:233`), which refuses a retained completion and a run under a fresh lease (`src/Workflow/WorkflowEngine.php:111-127`).

C25 covers the cleanup together with the removal of deprecated APIs.

### D18. Agent judges carry one conversation across every judgment

`AgentJudge::evaluate()` sends every judgment through the one judge agent it received, with `$this->judge->structured(new UserMessage($prompt), JudgeScoreOutput::class)` (`src/Evaluation/Assertions/AgentJudge.php:51-54`), and every built-in judge extends it (`CorrectnessJudge`, `FaithfulnessJudge`, `HelpfulnessJudge`, `RelevanceJudge` and `TaskCompletionJudge` in `src/Evaluation/Assertions/Judges`). `Agent::structured()` starts a new run on the instance's thread (`src/Agent/Agent.php:361-373`), which the first call binds to a generated ID (`src/Workflow/Workflow.php:266-273`), and every run reads that thread's history (`Agent.php:155-165`). A reproduction with `FakeAIProvider` confirmed it: the second judgment's request carries three messages, the first prompt, the first verdict and the second prompt. The judgments of one evaluator are therefore not independent: a verdict depends on the items judged before it, and the prompt grows with the dataset. `UserSimulator` already flushes its own history before every step for the same reason (`src/Evaluation/Conversation/UserSimulator.php:64-67`). The fix gives every judgment a fresh thread: a judge factory today, a handle bound with `for()` to a generated ID after C2.

## 5. Design proposals

The proposals are grouped by theme. IDs are stable across the three documents; priorities mean: P0 before 4.0 and blocking for the packages, P1 before the packages go stable, P2 soon after, P3 nice to have. Effort is S (days), M (one to two weeks) or L (several weeks, touching many classes). Each proposal gives the problem with evidence from the current source, a PHP sketch of the proposed API, what it buys each framework, the alternatives considered, the breaking impact, and where useful the interim approach the packages use against today's core.

### 5.1 Identity and configuration

#### C2. Shareable definitions, bound execution handles

**Problem.** A Workflow instance is at the same time a definition (the graph recipe, hooks and factories), its platform configuration and a one-way-bound address. The constructor takes the workflow ID (`src/Workflow/Workflow.php:52-59`), `make()` is a plain `new` (`:61-65`), `setWorkflowId()` binds once and refuses to re-point (`:122-130`), and an unbound start generates an ID and binds it on the receiver (`:266-273`). `Agent::setThreadId()` delegates to it (`src/Agent/Agent.php:311-314`). The instance also caches its persistence (`src/Workflow/HandleComponents.php:89-92`) and its dispatcher (`src/Workflow/HandleDispatcher.php:55-61`). `WorkflowInterface` has no binding verb at all (`src/Workflow/WorkflowInterface.php:18-77`), and the per-segment factories and hooks take no argument (`HandleComponents.php:128-190`, documented in `src/Workflow/AGENTS.md:160-165`), so a container closure has to capture a particular instance to learn the address it serves.

The natural container lifetime is therefore wrong. Symfony registers application classes as shared services by default, Laravel users reach for `singleton()`, and every Octane, FrankenPHP or Messenger worker keeps services alive across requests. A shared Agent either throws on the second thread (`setThreadId()` with another ID) or, if any request ran it unbound, keeps the generated ID so that every later unbound caller continues the first user's conversation. Today the only safe registration is a prototype plus a binding call right after resolution, which every package, and every application service that holds an agent, has to get right.

**Proposal.** Split the definition from the execution handle. `for()` returns a bound clone and never mutates the receiver; `make()` keeps the script ergonomics by returning a new instance bound to the ID its `workflowId()` hook declares, or to a fresh generated ID when it declares none; per-segment factories receive the segment's `ExecutionContext`.

```php
// Proposed API (C2): for() with closure rebinding, the context-aware factories and hooks, and UnboundWorkflowException.
interface WorkflowInterface
{
    /** A clone bound to $workflowId; the receiver is never modified. (proposed, C2) */
    public function for(string $workflowId): static;

    // run(), events(), inspect(), submitInputs(), acknowledge(), abandon(), retainCompletionUntilAcknowledged(),
    // getWorkflowId(), subscribe(), setEventDispatcher() are unchanged.
}

class Workflow implements WorkflowInterface
{
    /** Script entry point: a new instance bound to the ID its workflowId() hook declares, or to a fresh generated ID. */
    public static function make(mixed ...$arguments): static;

    /** Throws when the workflowId() hook declares a different ID. */
    public function for(string $workflowId): static
    {
        $declared = $this->workflowId();
        if ($declared !== null && $declared !== $workflowId) {
            throw new WorkflowException("The workflow declares workflow ID '{$declared}' and cannot be bound to '{$workflowId}'.");
        }

        $handle = clone $this; // __clone() stays the hook for subclass deep copies
        $handle->workflowId = $workflowId;

        // A factory closure bound to the definition (and only to it) must run against the handle.
        // __clone() cannot see the definition, so the rebinding happens here.
        $rebind = function (Closure $factory) use ($handle): Closure {
            $reflection = new ReflectionFunction($factory);

            return $reflection->getClosureThis() === $this
                ? Closure::bind($factory, $handle, $reflection->getClosureScopeClass()?->getName() ?? static::class)
                : $factory;
        };

        $handle->resources = $this->resources === null ? null : $rebind($this->resources);
        $handle->streamAdapter = $this->streamAdapter === null ? null : $rebind($this->streamAdapter);
        $handle->channel = $this->channel === null ? null : $rebind($this->channel);
        $handle->nodes = array_map(
            static fn (NodeInterface|Closure $node): NodeInterface|Closure => $node instanceof Closure ? $rebind($node) : $node,
            $this->nodes,
        );
        // Closures in $globalMiddleware and $nodeMiddleware are rebound the same way.

        return $handle;
    }

    /** @param Closure(ExecutionContext): WorkflowResources $factory */
    public function setResources(Closure $factory): static;

    /** @param (Closure(ExecutionContext): ?StreamAdapterInterface)|null $factory */
    public function setStreamAdapter(?Closure $factory): static;

    /** @param (Closure(ExecutionContext): ?StreamingChannelInterface)|null $factory */
    public function setChannel(?Closure $factory): static;

    protected function resources(ExecutionContext $context): WorkflowResources;
    protected function streamAdapter(ExecutionContext $context): ?StreamAdapterInterface;
    protected function channel(ExecutionContext $context): ?StreamingChannelInterface;
}

/** Any execution verb on a definition without an address. (proposed, C2) */
final class UnboundWorkflowException extends WorkflowException {}

// Removed: the constructor $workflowId parameter, setWorkflowId() and Agent::setThreadId().
```

`workflowId()`, the declared business-key hook, stays: a keyed workflow is bound by declaration, and `for()` with a different ID throws. Executing an unbound definition throws `UnboundWorkflowException` before admission and before any output; `events()` performs the check when it is called and returns the lazy traversal, so a controller learns about the mistake before it builds a response. Clones share configured services; the listener registry is already copy-on-write (`subscribe()` clones it, `HandleDispatcher.php:35-41`). `for()` rebinds only the factory closures whose bound `$this` is the definition itself, keeping their original scope, so a closure bound to another object, such as an outer workflow that configured this agent as one of its nodes, keeps its binding. Subclasses with their own mutable fields define `__clone()`, the same contract nodes and middleware already have. Usage becomes `$agent->for($conversation->id)->stream($message)`.

**Composition.** An Agent or a RAG used as a node of another workflow, which the framework's mental model advertises, needs an address of its own, and a node cannot compute one: `NodeContext` carries the resume payload, the memoizer, the dispatcher and the branch, but no `ExecutionContext` (`src/Workflow/NodeContext.php:28-35`). Under this proposal the child's ID therefore comes from the resources factory, which receives the context. The pattern binds the child there, with an ID derived from the parent's workflow ID, and the parent's node memoizes the child's result, so a recovery of the parent reuses the committed answer instead of running the child again.

```php
// Proposed (C2): the parent binds its child per segment.
$this->setResources(fn (ExecutionContext $context): ResearchResources => new ResearchResources(
    researcher: $this->researcher->for($context->workflowId.':research'),
));

// In the parent's node: a recovery after the memo commits reuses the child's answer.
$findings = $this->memoize('research', fn (): ?string => $resources->researcher
    ->chat(new UserMessage($question))
    ->getMessage()?->getContent());
```

Two limits remain, and section 8 lists them as an open design question. A synchronous child that completes deletes its partition at once, so a crash between the child's completion and the parent's memo commit runs the child again and repeats its inference and tool calls; retaining the child's completion under a reserved run ID derived from the parent's run and step, acknowledged only after the parent's memo commits, would close that window. And a child that suspends, for example on a tool approval, cannot suspend its parent: the parent's node receives a suspended state and has to open an interrupt of its own and route the answer back.

**Laravel benefit.** Agents and workflows become ordinary singletons registered from the discovery manifest. They can be injected into controllers, jobs, Livewire components and Octane-resident services, and each call binds with `for()` after authorization. There is no bind-only rule to teach, no `forgetInstance()` dance, and a queued job resolves the definition by name and binds it in one expression.

**Symfony benefit.** Definitions are regular shared services, Symfony's default, injectable into Messenger handlers, controllers and commands without `shared: false` or a service locator. FrankenPHP worker mode and `messenger:consume` cannot leak identity, because nothing is ever bound on the service, and service closures no longer capture a definition to learn the thread.

**Alternatives considered.** Keeping prototypes and adding only a `WorkflowFactory` or registry contract is simpler for core, but it pushes the misuse risk into every application: one mistaken injection of a prototype into a long-lived service throws late or leaks silently. This is a legitimate choice for the maintainer (section 8); the documents recommend C2. Keeping generate-and-bind on unbound starts preserves "chat twice on one object" but turns a shared definition into a cross-tenant leak; `make()` binding a fresh ID keeps the same ergonomics safely. A separate `WorkflowHandle` type carrying the verbs is the cleanest split on paper, but it duplicates every verb and every Agent and RAG customization. `withWorkflowId()` is the same design with a longer name. Rebinding the factory closures inside `Workflow::__clone()` was the first idea, but `__clone()` runs on the copy and has no reference to the original, so it cannot tell which closures are bound to the definition; `for()` performs the rebinding instead, and subclasses still use `__clone()` for their own mutable fields.

**Breaking impact.** The constructor ID parameter, `setWorkflowId()` and `setThreadId()` disappear; executing an unbound instance built with `new` throws (scripts use `make()`); hooks and factories gain the `ExecutionContext` parameter; subclasses with mutable fields add `__clone()`. Retrying on the same handle behaves as before. Core's own evaluation code relies on implicit binding and changes with it: `Conversation::soFar()` and `UserSimulator::nextTurn()` treat `getThreadId() === null` as "no turn yet" (`src/Evaluation/Conversation/Conversation.php:144-148`, `src/Evaluation/Conversation/UserSimulator.php:64-67`), and an evaluator that injects one definition and reuses it across dataset items chains the items into one conversation today and would throw after C2 (see also D18). Evaluation switches to empty-history checks, and evaluators bind container-built definitions once per dataset item with `for()` and a generated ID. Setters remain on definitions, so calling one on an injected definition at request time would change it for every later caller; the documented rule is: configure definitions at build time, customize handles per call. The packages may enforce the rule in debug mode (to decide).

**Interim against today's core.** Register definitions as prototypes (Laravel `bind()` or autowiring, Symfony `shared: false` through `_instanceof`), bind with `setWorkflowId()` or `setThreadId()` immediately after resolution, never execute an unbound container-built instance, and resolve through a locator in long-lived services.

**Priority and effort.** P0, M.

#### C3. Lowest-precedence defaults tier

**Problem.** The only way to configure a definition from outside is a setter, and setters always win over hooks: every getter resolves "setter, else hook" (`src/Workflow/HandleComponents.php:61-64`, `:89-92`, `:109-112`; `src/Workflow/Workflow.php:163-170`; `src/Agent/Agent.php:130-133`; `src/Agent/HandleProvider.php:40-43`; `src/RAG/ResolveVectorStore.php:25-28`). The persistence, serializer, message store and vector store getters also memoize the hook's result on the instance, while `getBranchRunner()`, `getLeaseTimeout()` and `getProvider()` call the hook again on every resolution. The hooks' own fallbacks are process-local: `InMemoryPersistence` (`HandleComponents.php:94-97`), `InMemoryMessageStore` (`Agent.php:119-122`), `MemoryVectorStore` (`ResolveVectorStore.php:20-23`), while `provider()` throws (`HandleProvider.php:30-35`). The PSR-14 forward target can only be set with `setEventDispatcher()` (`src/Workflow/HandleDispatcher.php:47-53`).

A package that applies the application's persistence, serializer, message store or provider through setters therefore silently overrides a subclass that declared its own `persistence()` hook; a package that does not apply them leaves definitions on in-memory backends, which silently breaks durability across processes. `Agent` extends `Workflow` directly (`Agent.php:62`), so there is no base-class slot where a package could put defaults, and today's workaround is a reflection check per hook ("does this class override `persistence()`?").

**Proposal.** One lowest-precedence tier, as a PSR-11 container keyed by interface FQCN, consulted only by the base hooks. The precedence becomes explicit setter, then class hook override, then defaults, then core fallback.

```php
// Proposed (C3).
use Psr\Container\ContainerInterface; // new requirement: psr/container ^2.0

trait HandleComponents
{
    protected ?ContainerInterface $defaults = null;

    /** Lowest-precedence collaborators, consulted only by the base hooks. (proposed, C3) */
    public function setDefaults(ContainerInterface $defaults): static
    {
        $this->defaults = $defaults;
        return $this;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T|null
     */
    final protected function fromDefaults(string $id): ?object
    {
        return $this->defaults?->has($id) ? $this->defaults->get($id) : null;
    }

    protected function persistence(): PersistenceInterface
    {
        return $this->fromDefaults(PersistenceInterface::class) ?? new InMemoryPersistence();
    }
}

// Agent
protected function provider(): AIProviderInterface
{
    return $this->fromDefaults(AIProviderInterface::class)
        ?? throw new AgentException('No AI provider configured: override provider(), call setAiProvider() or configure defaults.');
}

namespace NeuronAI;

/** Array-backed PSR-11 defaults for scripts and tests. (proposed, C3) */
final class Defaults implements ContainerInterface
{
    /** @param array<class-string, object|Closure(): object> $services */
    public function __construct(protected array $services = []) {}

    public function get(string $id): object;

    public function has(string $id): bool;
}
```

The key list is closed and documented per module: only the base hooks consult the tier, and only for the keys below, so it is not a general service locator.

| Module | Keys consulted by the base hooks | Core fallback |
|---|---|---|
| Workflow | `PersistenceInterface`, `Serializer`, `ClockInterface` (C10), `EventDispatcherInterface` (the forward target), `BranchRunner` | in-memory persistence, `PhpSerializer`, `SystemClock`, no forward, `SequentialBranchRunner` |
| Agent | `MessageStoreInterface`, `AIProviderInterface` (the default provider), `OutputMapperInterface` after C20 | in-memory store, exception, `OutputMapper` |
| RAG | `EmbeddingsProviderInterface`, `VectorStoreInterface` | exception, exception (C21) |

Handles made by `for()` carry the same defaults, because they are clones. `WorkflowEngine` and `Segment` never see the container: the Workflow resolves collaborators and passes them in, as it does today. `ClassifierInterface` is deliberately not a key: no base hook consults a classifier, and applications inject named classifiers into their resources, because a definition that classifies usually needs a specific one. Scalars such as the lease timeout and completion retention are not in the tier; they stay class-level hooks or setters, and packages set them explicitly per registered workflow from configuration, because explicit configuration is meant to win. PSR-11 does not require `get()` to return the same instance, so until C5 lands a package can register the default provider as non-shared behind the locator.

**Laravel benefit.** One `afterResolving(Workflow::class, fn (Workflow $workflow, $app) => $workflow->setDefaults($app->make(NeuronDefaults::class)))` wires every definition, subclasses with their own hooks included, with no reflection and no package base class. `Neuron::fake()` swaps individual entries of the defaults locator.

**Symfony benefit.** The bundle registers `neuron.defaults` as a `ServiceLocator` keyed by interface FQCN, the service subscriber idiom, and adds one `setDefaults` method call through `registerForAutoconfiguration(Workflow::class)`. Choices made in the configuration tree never override decisions made in a class, and the test container replaces entries one by one.

**Alternatives considered.** A typed `WorkflowRuntime` value object is typed and discoverable, but the Workflow module cannot name Chat, Providers or RAG interfaces without inverting module dependencies; it could carry only workflow-level services, which leaves the Agent and RAG half of the problem open. A package trait that overrides the hooks has to be added to every application class and conflicts with the class's own overrides. Reflection checks before calling setters work today and are the interim, but they are brittle and have to be repeated for every hook.

**Breaking impact.** Core requires `psr/container`. Hook bodies change but their signatures do not (C2 changes the per-segment hook signatures for its own reasons; C10 adds a `clock()` hook). Nothing changes for code that uses setters or overrides hooks.

**Interim against today's core.** Apply setters only where the class keeps the base hook, detected by reflection and cached at build time; always call `setEventDispatcher()`.

**Priority and effort.** P0, S.

#### C4. Constructor ownership and pure construction

**Problem.** `Workflow::__construct()` is the only initializer of three typed properties that have no default: the promoted `protected ?string $workflowId` (`src/Workflow/Workflow.php:53`; a promoted property never has a default, the `= null` belongs to the parameter), `protected ?WorkflowState $initialState;` (`:47`, set at `:52-59`) and `protected ExporterInterface $exporter;` (`src/Workflow/HandleComponents.php:49`). An autowired subclass whose constructor forgets `parent::__construct()` fails at the first `getWorkflowId()`, run or export with "must not be accessed before initialization", and the base constructor's `?WorkflowState $state` parameter takes part in autowiring decisions. `BaseEvaluator` has the same shape (`src/Evaluation/BaseEvaluator.php:15-18`).

Several components also do I/O while being constructed. `ChromaVectorStore`, `QdrantVectorStore` and `WeaviateVectorStore` call `initialize()` over HTTP (`src/RAG/VectorStore/ChromaVectorStore.php:58`, `QdrantVectorStore.php:51`, `WeaviateVectorStore.php:60`); `MeilisearchVectorStore` reads the index, creates and configures it (`MeilisearchVectorStore.php:40-65`) and polls tasks with `usleep()` (`:246-248`); `FileVectorStore` creates its directory and file (`FileVectorStore.php:52-57`); `AnthropicVertex` and `GeminiVertex` fetch an OAuth token and freeze it into their headers (`src/Providers/Anthropic/AnthropicVertex.php:42`, `:58`; `src/Providers/Gemini/GeminiVertex.php:36`, `:43`); `McpClient` opens its session (`src/MCP/McpClient.php:49-55`). Provisioning is spread across unrelated names: `setupTable()` (`MariaDBVectorStore.php:39`), `setupVectorIndex()` (`MongoDBVectorStore.php:40`) and `destroy()` (`ChromaVectorStore.php:114`, `QdrantVectorStore.php:73`, `WeaviateVectorStore.php:78`).

Resolving any of these from a container performs I/O. Cache warmers and any service that is built eagerly (listeners, commands, graph-validation checks) touch the network, unrelated routes fail when a store is down, frozen tokens expire inside long-lived workers, and provisioning cannot move to deploy time.

**Proposal.** Constructors store configuration only, as a coding standard enforced in review, and provisioning becomes one explicit interface.

```php
// Proposed (C4).
class Workflow implements WorkflowInterface
{
    protected ?string $workflowId = null; // bound by for() (C2)
    protected ?WorkflowState $initialState = null; // seeded with setState()
    protected ?ExporterInterface $exporter = null; // export() falls back to new ConsoleExporter()

    // No constructor: subclasses own theirs.
}

namespace NeuronAI;

/** (proposed, C4) */
interface ManagedStoreInterface
{
    /** Provision the table, collection or index. Idempotent. */
    public function setup(): void;

    /** Remove what setup() created. */
    public function drop(): void;
}
```

`BaseEvaluator` initializes its rule executor lazily. `ManagedStoreInterface` is implemented by the SQL persistence and message stores (C13) and by the self-provisioning vector stores: Chroma, Qdrant, Weaviate, Meilisearch, File, MariaDB (replacing `setupTable()`) and MongoDB (replacing `setupVectorIndex()`), with `drop()` replacing `destroy()`. Lookups such as Chroma's collection ID resolve lazily on first use. The Vertex providers take a credentials or token source and authorize each request lazily through its cache. `McpClient` opens its session on first use. The packages ship `neuron:setup`, which provisions every configured managed store, in the style of `messenger:setup-transports`.

The name collides with Symfony AI: `Symfony\AI\Store\ManagedStoreInterface` (`setup(array $options = [])`, `drop(array $options = [])`) and `Symfony\AI\Chat\ManagedStoreInterface` have the same short name and verbs as Neuron's interface. Generated code and documentation import `NeuronAI\ManagedStoreInterface` explicitly, and `neuron:setup` provisions only Neuron's stores, never Symfony AI's, which keep their own `ai:store:setup` and `ai:message-store:setup` commands.

**Laravel benefit.** Every Neuron service can be a plain singleton whose resolution performs no I/O, so nothing touches the network in `register()`, `boot()` or package discovery, and `php artisan neuron:setup` runs next to `migrate` in deploy scripts. Subclasses own their constructors outright, so constructor injection into agents needs no `parent::__construct()` ritual.

**Symfony benefit.** No `lazy: true` workaround is needed: cache warmers, event subscribers and commands that receive a Neuron service, and a warmer or CI check that instantiates every definition to validate its graph with `export()`, no longer touch the network. `bin/console neuron:setup` iterates the services autoconfigured through `ManagedStoreInterface`, and `kernel.reset` never has to refresh credentials.

**Alternatives considered.** Lazy proxies (Symfony lazy services, Laravel closures) hide the I/O without removing it: it still happens on first use inside a request, and provisioning still cannot move to deploy time. Subclassing providers to skip their constructors, as the Vertex tests do, is a test-only hack.

**Breaking impact.** Vector stores no longer create collections implicitly; applications run `setup()` or `neuron:setup` at deploy. `setupTable()`, `setupVectorIndex()` and `destroy()` are replaced by `setup()` and `drop()`. Vertex constructor signatures change. Subclass constructors stop calling `parent::__construct()`, and seed state moves to `setState()`.

**Interim against today's core.** Register stores and Vertex providers lazily and never resolve them during boot; subclasses call `parent::__construct()`; `neuron:setup` calls the existing provisioning methods.

**Priority and effort.** P1, M.

### 5.2 Durable execution contract

#### C1. Settle abandoned segments

**Problem.** A consumer can stop iterating `events()` before the segment ends: the client disconnects and the response stops pulling, Laravel's `response()->eventStream()` or Symfony's `EventStreamResponse` break out of the generator on `connection_aborted()`, user code breaks out of a loop, or an exception is thrown between two chunks. If PHP is still running, it then destroys the generator and runs its `finally` blocks, but not its `catch` blocks. It is only still running after a disconnect if `ignore_user_abort(true)` is set: under PHP's default `ignore_user_abort(false)`, the SAPI aborts the request at the first failed write, the same way a fatal error does, and no `finally` runs. `connection_aborted()`, and the loops that check it, are observable only with `ignore_user_abort(true)`. `Segment::run()` only reports `WorkflowEnd` in its `finally` (`src/Workflow/Executor/Segment.php:96-100`), and `fail()` runs only inside `execute()`'s `catch (Throwable)` (`:163-170`). Neither `settle()` nor `fail()` runs, so `__control` stays `running` under a fresh lease.

For the whole lease, 600 seconds for an Agent (`src/Agent/Agent.php:111-114`), the thread refuses a new start (`src/Workflow/WorkflowEngine.php:228-244` with `isDeadGeneration()` at `:254-260`), inputless recovery (`:375-387`) and `abandon()` (`:118-127`), and therefore `resetConversation()` too (`Agent.php:233`). A plain Workflow has no lease by default (`src/Workflow/Workflow.php:172-175`), so its run stays `running` until someone resumes it. On the most common integration path, a chat UI streaming over HTTP, a closed tab becomes a ten-minute outage for that conversation.

**Proposal.** The segment settles itself when its generator is destroyed before `settle()` or `fail()` ran, using the fenced, best-effort write `fail()` already performs through `markControlFailed()` (`Segment.php:212-223`). There is no public API change.

```php
// Proposed change to an internal class (C1); no public API changes.
final class Segment
{
    protected bool $settled = false; // set by settle() and fail()

    public function run(Closure $graph, Closure $adapter, Closure $channel, BranchRunner $branches, EventDispatcherInterface $dispatcher, object $source): Generator
    {
        $this->events = new SegmentEventDispatcher($dispatcher, $this->context, $source);
        $this->branches = $branches;

        try {
            return yield from $this->execute($graph, $adapter, $channel);
        } finally {
            if (!$this->settled) {
                // The consumer let go mid-step: release the claim instead of holding the lease.
                $this->fail(new SegmentAbandonedException($this->context->workflowId, $this->context->runId, $this->context->executionAttempt));
            }
            $this->report(new WorkflowEnd($this->state));
        }
    }
}
```

An abandoned segment then behaves exactly like a caught failure. Committed steps and memos stay; a non-recovering start such as `Agent::chat()` supersedes the failed generation at once (`WorkflowEngine.php:228-233`); a plain `run()` or a gateway recovery resumes from the last committed step. Because the write is fenced, a newer owner keeps the run. No frames are yielded: PHP forbids yielding from a `finally` block of a destroyed generator, and the pull consumer is gone. A configured channel, whose subscribers may still be listening, receives its `failed()` notification directly, since that call does not yield; clients that missed it reconcile through `inspect()`. `SegmentAbandonedException` is internal and only reaches listeners through `WorkflowError`. The mechanism is best effort by nature: fatal errors, a client abort while `ignore_user_abort` is off, `SIGKILL` and hard timeouts skip `finally` blocks, and the lease still covers those.

**Laravel benefit.** A response generator ended early while PHP keeps running (a response class that stops iterating on `connection_aborted()`, a user `break`, an exception between chunks) no longer locks the thread. The package's streaming response must still call `ignore_user_abort(true)`, because without it a disconnect aborts the request before any `finally` runs. Only draining after the disconnect, so that the answer is committed to history, becomes a user-experience choice.

**Symfony benefit.** `EventStreamResponse`, which stops iterating on `connection_aborted()`, becomes safe to use while `ignore_user_abort(true)` is in effect (the Runtime component's `FrankenPhpWorkerRunner` sets it, FPM does not); the bundle's `NeuronStreamResponse` keeps draining by default, and a `ServerEvent`-based variant over `SSEEncoder::payloads()` (C15) becomes acceptable once C1 lands.

**Alternatives considered.** Documenting `ignore_user_abort(true)` and draining alone leaves the trap in place for every response class a package does not control. A `cancel()` or `close()` verb on the stream would be forgotten by callers, and destruction is the real signal. A shorter Agent lease causes more false takeovers of slow provider calls. Marking the run `suspended` instead of `failed` would describe a pause with no interrupt to answer, while `failed` already has the right recovery semantics.

**Breaking impact.** Behavioural only: breaking out of `events()` now leaves the run `failed` (recoverable) instead of `running`. Tests that assert a running run after an early break must change.

**Interim against today's core.** Streaming endpoints call `ignore_user_abort(true)` and keep draining the generator after `connection_aborted()`; long turns go through the queue and a push channel; a thread killed mid-stream stays held until its lease expires.

**Priority and effort.** P0, S.

#### C6. Typed failure vocabulary with retry semantics

**Problem.** Only two refusals are typed: `RunInFlightException` (`src/Exceptions/RunInFlightException.php:24-37`) and `StaleWorkflowRunException` (`src/Exceptions/StaleWorkflowRunException.php:7-20`). Every other decision a gateway or an HTTP edge has to take depends on a plain `WorkflowException` message. The table lists the refusals that must be told apart, where they are thrown today (in `src/Workflow/WorkflowEngine.php` unless another file is named), and the type each one gets under this proposal.

| Situation | Thrown today | Proposed type |
|---|---|---|
| The run ID no longer matches | `:278-280`, `:103-105`, `:149-151`, `:400-402` (already typed) | `StaleWorkflowRunException`, reparented |
| The execution attempt no longer matches | `:282-290`, `:107-109`; the store fence `src/Workflow/Executor/WorkflowRunStore.php:293-296` when a segment's own commit loses it; stale completion `src/Workflow/Executor/Segment.php:197-200` | `StaleExecutionAttemptException` |
| The address is held | new start `:235-244` (already typed); abandon under a fresh lease `:118-127`; inputless continuation under a fresh lease `:375-387`; payload for a running run `:326-328`; abandon of a retained completion `:111-116`; acknowledge of a run that is not completed `:153-157` | `RunInFlightException` |
| No run in flight | `:404-406`; `src/Workflow/Workflow.php:234-236` | `NoRunInFlightException` |
| The input does not fit | `:305-308`, `:315-318`, `:323-325`; `Workflow.php:238-240`; conflicting answer `src/Workflow/Executor/ActiveInterrupt.php:33-35`; interrupt validation `src/Workflow/Interrupt/SleepUntilRequest.php:45-60`, `WaitForEventRequest.php:60-82`, `src/Agent/Interrupt/ToolResultsRequest.php:76-84` | `InvalidInputException` |
| A concurrent change won | ignition `:245-248`, abandon `:129-133`, acknowledge `:159-163`; admission commits that lose the store fence (`:346`, `:350`) | `ConcurrentUpdateException` |

The store fence throws the same message today for every lost conditional write. Under this proposal the store distinguishes the two cases by where the commit happens: a lost admission race is retried at once, and an obsolete segment is dropped. The segment's failed commit re-reads `__control` to learn the actual attempt, which may be absent after an abandon; when the re-read finds a different run ID, it throws `StaleWorkflowRunException` instead.

`InputTranslationException` extends `NeuronException`, not `WorkflowException` (`src/Exceptions/InputTranslationException.php:7`), and is used both for "no persisted run" and "no current interruption" (`src/Workflow/Workflow.php:234-240`). Core's own tests assert on message strings (`tests/Workflow/WorkflowDuplicateRequestTest.php:51`, `tests/Workflow/WorkflowIdentityTest.php:333`, `:513`, `tests/Workflow/WorkflowLeaseTest.php:94`, `tests/Workflow/WorkflowAbandonTest.php:125`), which is exactly what every package would have to do. Failures that are deterministic given committed data look transient to a queue: structured output that exhausted its attempt-indexed memos rethrows the last error (`src/Agent/Nodes/StructuredOutputNode.php:98-139`), and `ToolRunsExceededException` is thrown from memoized counts (`src/Agent/Nodes/ToolNode.php:450-467`), so a retry replays the same failure until the budget runs out. Finally, `WorkflowInterrupt`, the control-flow signal of a suspension, extends `WorkflowException` (`src/Workflow/Interrupt/WorkflowInterrupt.php:23`), so a `catch (WorkflowException)` inside node code swallows the suspension. D14 is the related message defect.

**Proposal.** A small vocabulary of types with structured fields, all under `WorkflowException` so existing catches keep working, plus two marker interfaces that carry retry semantics.

```php
namespace NeuronAI\Exceptions;

/** Clears by itself: deliver the same request again at or after retryAt(), in Unix seconds. (proposed, C6) */
interface RetryableException extends Throwable
{
    public function retryAt(): int;
}

/** Deterministic given committed data: recovery would replay the same failure. (proposed, C6) */
interface UnrecoverableException extends Throwable {}

/** The address is held. Existing fields kept; also thrown for continuation and abandon under a fresh lease. */
class RunInFlightException extends WorkflowException
{
    /**
     * The lease expiry while a live lease holds the run; null when there is none (no lease, or an
     * expired one, which the caller recovers with a fenced inputless continuation). (proposed, C6)
     */
    public function retryAt(): ?int;
}

/** "Your fence is obsolete, drop it." (proposed, C6) */
abstract class StaleRequestException extends WorkflowException
{
    // Initialized here: on PHP 8.1 to 8.3 a subclass cannot initialize a parent's readonly property.
    public function __construct(public readonly string $workflowId, string $message)
    {
        parent::__construct($message);
    }
}

class StaleWorkflowRunException extends StaleRequestException // existing class, reparented
{
    public function __construct(string $workflowId, public readonly string $expectedRunId, public readonly ?string $actualRunId)
    {
        parent::__construct($workflowId, "Stale continuation for workflow ID '{$workflowId}': expected run '{$expectedRunId}'.");
    }
}

class StaleExecutionAttemptException extends StaleRequestException
{
    public function __construct(string $workflowId, public readonly int $expected, public readonly ?int $actual)
    {
        parent::__construct($workflowId, "Stale execution attempt {$expected} for workflow ID '{$workflowId}'.");
    }
}

class StaleInterruptException extends StaleRequestException // used by C8
{
    public function __construct(string $workflowId, public readonly int $expected, public readonly ?int $actual)
    {
        parent::__construct($workflowId, "Stale answer to interrupt {$expected} for workflow ID '{$workflowId}'.");
    }
}

class NoRunInFlightException extends WorkflowException {}

/** The payload does not fit the current interrupt, or conflicts with an accepted answer. */
class InvalidInputException extends WorkflowException implements UnrecoverableException {}

class InputTranslationException extends InvalidInputException {} // reparented

/** A lost race: retry now. retryAt() returns the current time. */
class ConcurrentUpdateException extends WorkflowException implements RetryableException {}
```

`RetryableException` is also implemented by the retryable HTTP errors of C17. `UnrecoverableException` is implemented by `InvalidInputException`, by `ToolRunsExceededException`, and by `StructuredOutputException`, which `StructuredOutputNode` will throw once its retries are exhausted, with the last `AgentException` or `DeserializerException` as its previous exception (proposed, C6; today the node rethrows that last error unchanged). The marker describes the request against the state committed now. A signal refused because its wait is not current is the one legitimate exception: the node that opens the wait may not have committed yet, so the gateway answers it with a retry and the transport bounds those retries by a window measured from the first dispatch (C14). Bounded retries cover races, not buffering. Signals are neither broadcast nor queued for future or deferred waits (`src/Workflow/AGENTS.md:64`), so a signal that arrives while the run waits on another interrupt for longer than the window is discarded, for example a payment webhook that arrives while the run is still suspended on an approval. Applications that must not lose such events keep an inbox that the webhook writes and the node reads, inside `memoize()`, before it calls `awaitEvent()`. A durable per-run signal buffer, consumed by `awaitEvent()` before it suspends, is a candidate for the P3 backlog (section 7). For reserved starts, `RunInFlightException`'s message names the verb that settles the state instead of "retry the ignition" (D14). `WorkflowInterrupt` moves off the `WorkflowException` hierarchy to an internal base of its own, so `catch (WorkflowException)` and `catch (NeuronException)` in node code can no longer swallow a suspension. `UnboundWorkflowException` (C2), `DefinitionVersionException` and `IncompatibleRecordException` (C12) join the same hierarchy. With these types, the gateway's refusal mapping (C14) becomes a plain type switch.

**Laravel benefit.** The `InvokeWorkflow` job maps types to `release()`, a logged return or `fail()`, and the exception handler maps them to 404, 409 or 422 with `Retry-After` through `render()` callbacks, without parsing messages. `$maxExceptions` and the failed-jobs table count only real failures.

**Symfony benefit.** The Messenger handler maps types to an acknowledgement, a delayed redispatch or `UnrecoverableMessageHandlingException`, and one `kernel.exception` listener produces problem+json responses declaratively. Only real failures reach the failure transport.

**Alternatives considered.** The pragmatic option widens only the two existing types, using `RunInFlightException` for every "wait" decision and `StaleWorkflowRunException` for every "drop" decision. It adds no classes and covers the queue verbs, but it cannot distinguish 404 from 409 from 422 at the edge and has no notion of unrecoverable failures. An error code or reason enum on `WorkflowException` means fewer classes, but both frameworks configure exception handlers and retry policies by class, so each package would rebuild a lookup table. Keeping message strings is brittle across patch releases.

**Breaking impact.** `catch (WorkflowException)` keeps working. `InputTranslationException` moves under `WorkflowException` (a `catch (NeuronException)` still catches it). `submitInputs()` on a workflow with no persisted run throws `NoRunInFlightException` instead of `InputTranslationException`, so a `catch (InputTranslationException)` around it no longer covers that case. Message texts change, and tests switch from messages to types. `WorkflowInterrupt` leaves the hierarchy; it is internal, so only code that deliberately caught it is affected.

**Interim against today's core.** Classify only `RunInFlightException`, `StaleWorkflowRunException` and `InputTranslationException` by type; inspect the run before every continuation to decide instead of reading messages; apply bounded backoff to any other refusal.

**Priority and effort.** P0, M.

#### C7. Scheduling facts in core

**Problem.** A platform must compute when to wake a run, and three things get in the way. `InterruptRequest` has no deadline accessor (`src/Workflow/Interrupt/InterruptRequest.php:20-85`), so the deadline is read through `instanceof` ladders over `SleepUntilRequest::getWakeAt()` and `WaitForEventRequest::getExpiresAt()`, duplicated in the engine (`src/Workflow/WorkflowEngine.php:355-370`), in `RunInFlightException` (`src/Exceptions/RunInFlightException.php:74-89`), in the AG-UI translator (`src/Agent/Frontend/AGUIInputTranslator.php:118-119`) and in the AG-UI adapter (`src/Agent/Adapters/AGUIAdapter.php:706`). `WorkflowRunSnapshot` omits the lease expiry and whether the current interrupt already has an accepted input (`src/Workflow/WorkflowRunSnapshot.php:13-21`), although `WorkflowControl` carries `leaseExpiresAt` (`src/Workflow/Executor/WorkflowControl.php:29`) and `ActiveInterrupt` carries `input` (`src/Workflow/Executor/ActiveInterrupt.php:21`); a scheduler cannot tell "wait" from "recover" without them. And an inputless poll on a run that is already suspended commits its checkpoint again (`WorkflowEngine.php:339-347`), so every early or duplicate wake costs a conditional write.

The only lifecycle signal that looks like a scheduling hook, `WorkflowInterrupted`, is dispatched through `report()`, which swallows listener failures on purpose (`src/Workflow/Executor/Segment.php:190`, `src/Workflow/Executor/SegmentEventDispatcher.php:42-54`). A timer scheduled from a listener can therefore fail silently and leave a run suspended forever.

**Proposal.** Put the facts a scheduler needs on the public types, and state the reconciliation contract.

```php
// Proposed (C7).
abstract class InterruptRequest implements JsonSerializable
{
    /** From this time an inputless continuation resolves the wait; null when only input can. (proposed, C7) */
    public function deadline(): ?DateTimeImmutable
    {
        return null;
    }
}

class SleepUntilRequest extends InterruptRequest
{
    public function deadline(): DateTimeImmutable
    {
        return $this->wakeAt;
    }
}

class WaitForEventRequest extends InterruptRequest
{
    public function deadline(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }
}

// WorkflowEngine::dueInput(): one rule for every subtype
$deadline = $active->request->deadline();
if ($deadline === null || $deadline > $this->clock->now()) {
    return null;
}

return $active->request->type() === InterruptType::SleepUntil
    ? ResumeInput::timer($active->request)
    : ResumeInput::expired($active->request);

final class WorkflowRunSnapshot
{
    public function __construct(
        // runId, status, executionAttempt, interrupt, workflowId, startEvent (unchanged)
        public readonly ?int $leaseExpiresAt = null,   // (proposed, C7)
        public readonly bool $inputAccepted = false,   // (proposed, C7)
    ) {}
}
```

An idle inputless poll on a run that is already `suspended` with a checkpoint returns that checkpoint without writing, after re-reading `__control` to confirm it is unchanged (a re-read like the one `inspect()` performs, `WorkflowEngine.php:55-74`), and retries the read when it changed; today the conditional checkpoint write is also what keeps the returned checkpoint consistent with the control record. The write stays where the status actually changes, such as a failed run with an unanswered interrupt being re-suspended. The documented contract, in `src/Workflow/AGENTS.md`, becomes: reconcile timers and projections from the state `run()` returns, or from `inspect()`, before acknowledging the job; observability events are telemetry, never scheduling.

**Laravel benefit.** The `neuron_runs` projection and the `neuron:wake` sweep compute wake times with one call per run, and delayed `InvokeWorkflow` jobs use `deadline()` directly. A duplicate or early wake costs one read.

**Symfony benefit.** `DelayStamp::delayUntil($interrupt->deadline())` and the Scheduler sweep need no type switches, and an early wake costs one read.

**Alternatives considered.** A core `ExecutionOutcome` projection object would duplicate the snapshot. A due-index capability on `PersistenceInterface`, which today exposes only four atomic operations (`src/Workflow/Persistence/PersistenceInterface.php:31-69`), would break the rule that the engine has no index and no scheduler. Leaving the ladders to the packages repeats them for every future interrupt type.

**Breaking impact.** Additive: a method with a default and optional snapshot fields. Idle polls on suspended runs stop rewriting the checkpoint.

**Interim against today's core.** The packages keep their own ladder over the two concrete request types, call `inspect()` before any wake, and back off by a fixed lease-sized interval when a lease expiry is unknown.

**Priority and effort.** P1, S.

#### C8. Interrupt-fenced answers and wakes

**Problem.** Answers are fenced on the run ID and the execution attempt (`src/Workflow/Executor/ExecutionRequest.php:57-73`); `submitInputs()` captures the attempt it observed (`src/Workflow/Workflow.php:246-249`), and `src/Workflow/AGENTS.md:77` and `:83` prescribe both fences for delivery jobs. But attempts advance for reasons unrelated to the question being answered. Every recovery claims a new attempt (`src/Workflow/Executor/WorkflowControl.php:36-43`, `src/Workflow/WorkflowEngine.php:350`), and an idle poll on a failed run re-suspends it with `claim(null)->suspended()`, which also bumps the attempt (`WorkflowEngine.php:341`). The result:

- a queued approval retried after its own worker crashed is refused as stale, although the same interrupt is still waiting;
- an answer captured before an automatic recovery is refused;
- a duplicate answer that arrives while the first one executes gets "not suspended" (`WorkflowEngine.php:326-328`), which looks the same as a real conflict.

The interrupt ID is the natural fence. It is run-scoped and monotonic (`WorkflowControl::$nextInterruptId`, assigned at `src/Workflow/Executor/Segment.php:335`), and the engine already treats an identical re-submitted input as idempotent (`src/Workflow/Executor/ActiveInterrupt.php:25-42`).

**Proposal.** Answers, signals and timer wakes fence on `(runId, interruptId)`; recoveries keep fencing on `(runId, executionAttempt)`.

```php
final class ExecutionRequest
{
    public readonly ?int $interruptId; // (proposed, C8)

    /** @param array<string, mixed>|null $payload */
    public static function resume(
        ?array $payload = null,
        ?string $expectedRunId = null,
        ?int $expectedExecutionAttempt = null,
        ?int $expectedInterruptId = null,
    ): self;

    /** @param array<string, mixed> $payload */
    public static function signal(
        string $event,
        array $payload = [],
        ?string $expectedRunId = null,
        ?int $expectedExecutionAttempt = null,
        ?int $expectedInterruptId = null,
    ): self;
}

// WorkflowEngine::continueRun(), right after the run ID check
$current = $control->interrupt?->request->getId();
if ($request->interruptId !== null && $request->interruptId !== $current) {
    throw new StaleInterruptException($workflowId, $request->interruptId, $current);
}

// Workflow::submitInputs()
return new PendingExecution($this, ExecutionRequest::resume(
    $response,
    $run->runId,
    expectedInterruptId: $run->interrupt->getId(),
));
```

A redelivered answer after a worker crash then finds the same interrupt with its identical input already accepted. If the first delivery failed with an exception, the run is `failed`, which is an admissible status for a payload (`WorkflowEngine.php:326`), so the delivery recovers it. If the worker was killed (a job timeout, an OOM kill, a deploy), the run is still `running`. Once its lease has expired, the engine admits the identical payload as a takeover, under the same lease condition as an inputless continuation (`WorkflowEngine.php:375-387`); before that, the delivery is in flight (`RunInFlightException`, retrying at the lease expiry). The takeover replays the input already accepted with the claim (`:336`, `:350`); today the payload path refuses a running run whatever its lease (`:326-328`). A duplicate that arrives while the first answer executes is typed as in flight (`RunInFlightException`, C6). An answer to a question that has moved on raises `StaleInterruptException`, and a different answer to the same question remains a conflict (`InvalidInputException`). This needs verification in the engine before it is committed: recovery bumps the attempt, an idle poll on a failed run re-suspends it with a new attempt, a running run whose lease has expired must admit only the identical payload, and deferred interrupts queued in `pendingSteps` (`WorkflowControl.php:32`) must keep their own IDs across both paths.

**Laravel benefit.** Answer jobs are retried safely with plain backoff; double form submissions and Horizon retries are idempotent without `ShouldBeUnique`.

**Symfony benefit.** Messenger redeliveries of answer messages, from `redeliver_timeout` or failure-transport retries, are idempotent without `DeduplicateStamp` or locks.

**Alternatives considered.** Keeping attempt fences and letting each gateway re-fence after `inspect()` is correct, and is the interim, but it duplicates subtle logic in every platform and races idle polls. Not bumping the attempt on idle polls fixes one cause only, since recoveries still invalidate captured answers.

**Breaking impact.** `submitInputs()` fences on the interrupt instead of the attempt; `ExecutionRequest` gains a field and an optional argument; the duplicate-request section of `src/Workflow/AGENTS.md` is rewritten.

**Interim against today's core.** On a stale-attempt refusal, inspect the run; when the current interrupt is still the one being answered, re-fence with the new attempt and retry, otherwise discard.

**Priority and effort.** P1, S.

#### C9. Ignite without executing, and JSON-safe continuations

**Problem.** Four gaps block a clean queued start. `ExecutionRequest` stores its start event and its payload as PHP-serialized strings (`src/Workflow/Executor/ExecutionRequest.php:17-18`, `:30-31`), so a queued start carries class names and object graphs in the message instead of JSON. Admission happens only when `events()` is iterated (`src/Workflow/Workflow.php:258-296`, `admit()` at `:275-281`), so an edge that queues a turn cannot answer 409 before its 202: the conflict is discovered later by a worker, with nobody to tell. A retried reserved start that meets a failed run is refused forever (`src/Workflow/WorkflowEngine.php:203`, `:214`, `:228-233`, and D14). And `PendingExecution` keeps the fenced request it built to itself (`src/Workflow/PendingExecution.php:13-33`), so an endpoint that validated and translated an answer cannot hand it to a queue; it has to rebuild the resume from `inspect()` and re-run the translator in the worker. Payloads are already JSON-compatible by construction (`src/Workflow/Interrupt/ResumeInput.php:24-40`), so only the envelope is missing.

**Proposal.** Admission can happen without execution, and every queued message becomes a JSON-safe fenced continuation.

```php
enum WorkflowStatus: string
{
    case Pending = 'pending'; // (proposed, C9)
    // Running, Suspended, Completed, Failed unchanged
}

interface WorkflowInterface
{
    /**
     * Persist a new run as Pending without executing it. An inputless
     * continuation fenced by the returned run ID executes it. (proposed, C9)
     *
     * @throws RunInFlightException
     */
    public function ignite(?ExecutionRequest $start = null): WorkflowRunSnapshot;
}

final class ExecutionRequest
{
    /**
     * Continuations only: runId, executionAttempt, interruptId, JSON payload, signal. (proposed, C9)
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self;
}

final class PendingExecution
{
    public function request(): ExecutionRequest; // (proposed, C9)
}
```

`ignite()` writes `__ignition` and a `__control` record with status `pending`, attempt 0 and no lease, under the same sweep and refusal rules as a non-recovering start; `RunInFlightException` and its siblings therefore surface synchronously at the HTTP edge. An inputless continuation fenced on the returned run ID and attempt 0 claims the pending run and opens its segment; a continuation with a payload is refused as invalid input. A pending run is not a dead generation, so a new start cannot sweep it, but `abandon()` accepts it, which cancels the queued turn. Start events are serialized once, by the configured `Serializer`, into the workflow store; the message only carries names, identifiers, fences and JSON. The edge saves a `pending` projection from the snapshot `ignite()` returns, with `recheck_at` one sweep interval ahead, and dispatches after the commit. A dispatch lost between the commit and the queue is then found by the gateway's sweep, which inspects the run and re-dispatches it (C14). A crash between `ignite()` and the projection write leaves a pending run that nothing schedules; the next start on that thread meets it as a `RunInFlightException` with status `pending`, and the edge saves the missing projection before answering 409.

**Laravel benefit.** The job payload is a plain array that Horizon can display and `ShouldBeEncrypted` can protect. The controller returns 409 or 202 synchronously, and a user can cancel a queued turn.

**Symfony benefit.** Messages can use the Symfony Serializer (`serializer: messenger.transport.symfony_serializer`) instead of PHP serialization, so they are readable by dashboards and non-PHP consumers and never carry PHP objects. The reserved-start retry trap disappears, because workers never start runs; they only continue them.

**Alternatives considered.** Reserved-run-ID starts inside the job, switching to `resume()` when `RunInFlightException` names the job's own run, work today and are the interim, but the message is not JSON-safe and admission stays late. Igniting as `suspended` without an interrupt avoids a new enum case but reports a misleading status and misleading refusal guidance. A package-side table of pending inputs would be a second durable store to keep consistent with the first.

**Breaking impact.** A new `WorkflowStatus` case, so every exhaustive `match` over the enum adds it (for example `RunInFlightException::describeGeneration()`, `src/Exceptions/RunInFlightException.php:43-52`). `WorkflowInterface` gains `ignite()`. `ExecutionRequest` stores its payload as JSON.

**Interim against today's core.** The start message carries the start event encoded with the configured `Serializer` (base64) plus a reserved run ID minted at dispatch, and the edge records the reserved run ID in a `pending` projection row before dispatching. The shared gateway pre-release (C14) applies one rule to every delivery of a start, whether a redelivery after a crash, a delayed retry or a manual retry from a failed-jobs store:

- A start ignites, with `ExecutionRequest::start($event, runId: $reserved)`, only while `inspect()` finds no run and the row still names the reserved run with a non-terminal status. A `RunInFlightException` naming the reserved run switches it to `resume(expectedRunId: $reserved)`, which recovers a failed run, meets the lease of a live one or converges on a retained completion. Any other delivery is discarded.
- Before `acknowledge()`, the gateway marks the row terminal (status `completed`, which the due query never selects and which exists only for interim starts) and keeps it for the transport's retry window, after which the prune command removes it. A delivery after the acknowledgement therefore finds no run and a terminal row and is discarded, and a crash between the mark and the acknowledgement replays the retained outcome instead of igniting the run again.
- The write-ahead moves the row to `executing` without changing the run it names, so a worker that dies between the write-ahead and the ignition leaves a row that still admits the redelivered start, and the turn is not lost.
- The start event travels only in the message, so the sweep cannot re-dispatch a lost start. The pending row's recheck lies at the end of the retry window, and a row that comes due with no run marks a start that was lost or expired, and is forgotten.

The edge also does a best-effort `inspect()` before dispatching, accepting that it is racy. The integration documents reference this rule rather than restating their own; C9 removes it, because workers then only continue runs that `ignite()` already persisted.

**Priority and effort.** P1, M.

#### C10. PSR-20 clock

**Problem.** Leases, deadlines and interrupt validation read `time()` directly: `src/Workflow/WorkflowEngine.php:121`, `:259`, `:362`, `:366`, `:380`, `:423`; `src/Workflow/Executor/Segment.php:349`; `src/Workflow/Interrupt/SleepUntilRequest.php:54`; `src/Workflow/Interrupt/WaitForEventRequest.php:75`; `src/Exceptions/RunInFlightException.php:63`; `src/Agent/Frontend/AGUIInputTranslator.php:119`. The engine cannot be given a clock from outside either, because `getEngine()` is final and builds a new engine on every call (`src/Workflow/HandleComponents.php:75-78`), and core requires no clock abstraction (`composer.json` requires only PHP, `ext-curl` and `psr/event-dispatcher`). Laravel's `travel()` moves Carbon, not `time()`, and Symfony's `MockClock` cannot reach the engine, so neither framework can drive a sleep, an approval expiry or a lease takeover in a test; and a multi-host deployment cannot centralize its time source.

**Proposal.** The engine and the segment heartbeat use a PSR-20 clock that the Workflow gets from the defaults tier (C3).

```php
// Proposed (C10).
namespace NeuronAI\Workflow;

use DateTimeImmutable;
use Psr\Clock\ClockInterface; // new requirement: psr/clock ^1.0

/** (proposed, C10) */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}

class WorkflowEngine
{
    public function __construct(
        protected PersistenceInterface $persistence,
        protected Serializer $serializer = new PhpSerializer(),
        protected ClockInterface $clock = new SystemClock(), // (proposed, C10)
    ) {}
}

// Workflow
protected function clock(): ClockInterface
{
    return $this->fromDefaults(ClockInterface::class) ?? new SystemClock();
}
```

`getEngine()` passes the resolved clock, and `openSegment()` hands it to the `Segment` for the heartbeat. Interrupt due checks use the engine clock: timer and expiry inputs are produced only by the engine's `dueInput()`, so the duplicate `time()` checks inside `SleepUntilRequest::validate()` and `WaitForEventRequest::validate()` go away, and `RunInFlightException` receives the current time from the engine. The AG-UI translator reads `deadline()` (C7) against an injected clock.

**Laravel benefit.** The package binds a Carbon-backed clock, so `travel()`, `travelTo()` and `Date::setTestNow()` drive leases, sleeps and approval expiries deterministically.

**Symfony benefit.** The `clock` service (`symfony/clock` implements PSR-20) goes into `neuron.defaults`, and `MockClock` or `ClockSensitiveTrait` drive the same scenarios. The engine, the gateway and the sweep share one time source.

**Alternatives considered.** Namespace-level function overrides in the style of `ClockMock` are brittle, test-only and do not follow Carbon. A clock only on `WorkflowEngine` would be unreachable from a Workflow, because `getEngine()` is final. Real sleeps with near-future deadlines make tests slow and flaky.

**Breaking impact.** The engine constructor gains an optional argument; interrupt `validate()` methods stop checking time; core requires `psr/clock`, an interface-only package.

**Interim against today's core.** None for time travel: timer tests use deadlines a few seconds ahead and assert on the projection.

**Priority and effort.** P1, S.

#### C11. Durable side effects

**Problem.** Memo commits do not renew the lease: `WorkflowRunStore::memo()` commits without a control change (`src/Workflow/Executor/WorkflowRunStore.php:205-217`), and the heartbeat happens only at step commits (`src/Workflow/Executor/Segment.php:346-350`). `ToolNode` runs every local tool call of a model turn inside one step (`src/Agent/Nodes/ToolNode.php:86-87`, `:391`), so the lease must exceed the sum of all tools in a turn, or of a whole ingestion loop, instead of the slowest single operation; job and redelivery timeouts bound the whole invocation either way.

Memoization is at-most-once only after the commit. The operation runs before the memo write (`WorkflowRunStore.php:213-214`), so a crash in between repeats the side effect on recovery. `Node::memoize()` and `src/Workflow/AGENTS.md:129` tell callers to supply an idempotency key to the external system (`src/Workflow/Node.php:78-90`), but core gives them none, and `ToolNode`'s docblock claims that "side-effecting tools execute at most once" (`ToolNode.php:403-407`). Tools also run blind: the per-call clone receives only inputs and the call ID (`ToolNode.php:217-228`), so a tool resumed in a queue worker, where there is no authenticated user, cannot tell which workflow, run or tenant it serves.

**Proposal.** Memo commits carry the heartbeat, memoized operations receive a stable key, and tools receive a context.

```php
// Proposed (C11).
// WorkflowRunStore::memo(): when the segment holds a lease, the same conditional write renews it.
// The store receives the lease and the clock (C10) from the segment that opens it.
$this->commit(
    [$key => $value],
    $this->leaseTimeout === null ? null : $this->control()->heartbeat($this->now() + $this->leaseTimeout),
);

abstract class Node implements NodeInterface
{
    /**
     * The key is identical across every recovery of the run; zero-argument closures keep working.
     *
     * @template T
     * @param Closure(string): T $operation receives the idempotency key (proposed, C11)
     * @return T
     */
    protected function memoize(string $name, Closure $operation): mixed;
}

namespace NeuronAI\Tools;

/** (proposed, C11) */
final class ToolContext
{
    public function __construct(
        public readonly string $workflowId,
        public readonly string $runId,
        public readonly int $executionAttempt,
        public readonly ?string $callId,
        public readonly string $idempotencyKey,
    ) {}
}

interface ToolInterface
{
    /** Bound by ToolNode on the per-call clone. (proposed, C11) */
    public function setContext(ToolContext $context): static;
}
```

The idempotency key is a hash of the workflow ID, the run ID, the step and the memo name, the same inputs that already form the memo key (`WorkflowRunStore.php:312-320`), so it is identical on every recovery attempt. `ToolNode` binds the `ToolContext` on the per-call clone inside the memo closure; tools pass `idempotencyKey` to Stripe, SES or a database unique constraint, and read the thread identity, and any tenant derived from it, from the context's `workflowId`. `McpTool` forwards the key in the `_meta` of `tools/call`. The `ToolNode` docblock is corrected to "at-most-once after the commit, at-least-once inside the crash window", which is exactly-once in effect when the downstream system honours the key.

**Laravel benefit.** Tools in queue workers read the conversation and tenant from their context instead of `auth()`, and pass the key as a Stripe request option (for example through `Cashier::stripe()`) or to any HTTP API with idempotency support. The longest silent stretch shrinks to one tool call, so the lease no longer has to cover a whole tool loop. This matters most for synchronous streams. A queued job's `$timeout` still bounds the whole invocation, and since the job timeout must not exceed the lease, its lease stays at least that long.

**Symfony benefit.** The same holds in Messenger handlers, where there is no security token and no current request. Shorter leases also mean faster takeover after a consumer is killed.

**Alternatives considered.** Using `getCallId()` as the key works today, but the call ID can be null and is only unique within a provider response. An explicit `Node::heartbeat()` for long operations would be forgotten by callers, while memo commits are the natural progress points. Passing the key as an `__invoke()` parameter would collide with arguments derived from the tool schema.

**Breaking impact.** `ToolInterface` gains `setContext()`. Memo closures may take one argument, which is backward compatible. A memo write under a lease also rewrites the control record.

**Interim against today's core.** Pass the thread ID into tools from the `tools()` hook of the bound instance, use `getCallId()` as a partial key, and size leases above a whole tool loop.

**Priority and effort.** P1, M.

#### C12. Deploy-safe persisted records

**Problem.** Suspended runs outlive deploys, and several things break when code changes underneath them. Engine records are PHP object graphs written by `PhpSerializer` (`src/Workflow/Persistence/PhpSerializer.php:15-28`). `WorkflowControl` is final, with promoted readonly properties and no `__serialize()`/`__unserialize()` (`src/Workflow/Executor/WorkflowControl.php:20-34`), and neither `ActiveInterrupt` nor `ResumeInput` defines them, so a property added in a later release is uninitialized on old records; `Ignition` and `StepResult` already use explicit shapes (`src/Workflow/Executor/Ignition.php:26-41`, `src/Workflow/Executor/StepResult.php:65-80`), which is the precedent to extend. `PhpSerializer::unserialize()` calls `@unserialize()` without detecting incomplete classes (`PhpSerializer.php:20-28`): a renamed event, state or interrupt class becomes `__PHP_Incomplete_Class`, which PHP refuses to assign to the typed properties of the engine records. `loadControl()` and `loadIgnition()` then surface a raw `TypeError` such as "Cannot assign __PHP_Incomplete_Class to property ActiveInterrupt::$request" (`src/Workflow/Executor/WorkflowRunStore.php:98`, `:112`; `src/Workflow/Executor/Ignition.php:37-41`), and step, checkpoint and outcome records fail with a generic `PersistenceException` such as "Invalid step record" (`WorkflowRunStore.php:244-258`). None of them names the missing class. Records are unserialized without any integrity check, so write access to the store is object injection.

Step identity is the node class plus the traversal index (`src/Workflow/Executor/Segment.php:406-413`), so renaming a node class re-executes committed work on replay. Nothing records which definition version started a run (`Ignition.php:15-21` holds only the run ID and the start event), so rolling deploys with old and new workers side by side can neither route a run to a compatible worker nor refuse it cleanly.

**Proposal.** Versioned engine records, typed incompatibility, an optional integrity layer, an opt-in definition version and stable step keys.

```php
// Proposed (C12): versioned record shapes, typed incompatibility, signing, definition version, step keys.
final class WorkflowControl
{
    public function __serialize(): array
    {
        return ['v' => 1, 'runId' => $this->runId, 'status' => $this->status->value /* , ... */];
    }

    public function __unserialize(array $data): void
    {
        // Defaults for keys absent from older records.
        $this->nextInterruptId = $data['nextInterruptId'] ?? 1;
        $this->pendingSteps = $data['pendingSteps'] ?? [];
        // ...
    }
}
// ActiveInterrupt and ResumeInput follow the same pattern. The InterruptRequest base serializes
// get_object_vars($this) plus the version key and restores every key it receives, defaulting only
// its own fields, so subclass properties such as SleepUntilRequest::$wakeAt survive.

/** (proposed, C12) */
class IncompatibleRecordException extends PersistenceException
{
    public function __construct(public readonly string $missingClass, public readonly ?string $key = null) {}
}

/** HMAC over the inner bytes, keyed by APP_KEY or kernel.secret. (proposed, C12) */
final class SigningSerializer implements Serializer
{
    /**
     * @param list<string> $previousKeys verified but never used to sign
     * @param bool $acceptUnsigned transition mode: unsigned records are accepted and signed on their next write
     */
    public function __construct(
        protected Serializer $inner,
        #[\SensitiveParameter] protected string $key,
        #[\SensitiveParameter] protected array $previousKeys = [],
        protected bool $acceptUnsigned = false,
    ) {}
}

/** A record that fails signature verification. (proposed, C12) */
class RecordSignatureException extends PersistenceException
{
    public function __construct(public readonly ?string $key = null) {}
}

/** (proposed, C12) */
class DefinitionVersionException extends WorkflowException
{
    public function __construct(public readonly string $workflowId, public readonly ?string $recorded, public readonly string $current) {}
}

interface NodeInterface
{
    /** Stable identity of this node's steps, used by Segment::stepId(). (proposed, C12) */
    public function stepKey(): string;
}

abstract class Node implements NodeInterface
{
    public function stepKey(): string
    {
        return static::class;
    }
}
```

`PhpSerializer` and `IgbinarySerializer` set `unserialize_callback_func` for the duration of each call and restore it in `finally`. The callback throws `IncompatibleRecordException` naming the missing class; a check after decoding would come too late, because the typed engine properties reject `__PHP_Incomplete_Class` with a `TypeError` first. `WorkflowRunStore` adds the record key, as `loadRecord()` already does for decoding failures. An optional integrity layer closes the object-injection path: either an allowed-classes restriction on unserialization or the HMAC-signing decorator above. The signing decorator is safe with the fencing protocol because the conditional writes compare the raw bytes read (`WorkflowRunStore.php:277-297`), whatever encoding produced them. It signs with the current key and verifies against the current and previous keys (Laravel's `APP_PREVIOUS_KEYS`, a list of previous secrets in the bundle configuration), so a key rotation does not strand suspended runs. Turning signing on is itself a transition: on a store that already holds suspended runs, every unsigned record would fail verification at the next deploy. The `acceptUnsigned` flag verifies signed records and accepts unsigned ones, which are signed on their next write; a later deploy removes the flag once no run older than the switch remains. A record that fails verification throws `RecordSignatureException`, to which `WorkflowRunStore` adds the record key, never a generic decode error. A definition declares a version with `#[AsWorkflow(version: ...)]` (C19) or a `version()` hook; the version is stamped into `Ignition` at every start and exposed on the snapshot and on `ExecutionContext`, and a continuation whose recorded version differs from the running code throws `DefinitionVersionException` before any replay. Core stamps and refuses; routing old runs to old workers is platform policy.

**Laravel benefit.** Rolling deploys on Forge, Vapor or Kubernetes can route runs to per-version queues and drain old Horizon supervisors, and the package can offer a signing serializer keyed by `APP_KEY`. A renamed class produces a typed, actionable failure instead of a generic one.

**Symfony benefit.** Each deploy can get its own versioned Messenger transport, the signing key comes from `kernel.secret`, and a failed decode becomes a typed signal that policy can route to the failure transport.

**Alternatives considered.** JSON-encoding `__control` and `__ignition` while keeping PHP serialization for user state would make the engine records queryable and robust; it is a larger change that can follow. Temporal-style per-change patch markers are too heavy for Neuron's graph model. Documentation alone (drain before deploy, `class_alias` shims) is necessary but not sufficient.

**Breaking impact.** The format of engine records changes once, so pre-release 4.x stores are drained or abandoned before upgrading. Step identity goes through `stepKey()`, whose default preserves today's IDs; custom `NodeInterface` implementations that do not extend `Node` add the method. The version refusal applies only to classes that declare a version.

**Interim against today's core.** Drain or abandon suspended runs before renaming persisted classes or reordering nodes, ship `class_alias` shims for moved classes, and wrap the configured `Serializer` in a package-owned signing decorator.

**Priority and effort.** P1, M.

### 5.3 Storage

#### C13. Connection-resolving SQL stores, one canonical schema, shipped contract tests

**Problem.** The PDO-based stores capture one PDO at construction: `DatabasePersistence` (`src/Workflow/Persistence/DatabasePersistence.php:61-67`), `SQLMessageStore` (`src/Chat/History/SQLMessageStore.php:47-55`), `MariaDBVectorStore` (`src/RAG/VectorStore/MariaDBVectorStore.php:27-34`) and the SQL toolkits (`src/Tools/Toolkits/MySQL/MySQLToolkit.php:15`, `src/Tools/Toolkits/PGSQL/PGSQLToolkit.php:15`). `src/Workflow/AGENTS.md:121` leaves the PDO's lifetime, reconnection included, to the application. In queue workers, Messenger consumers and Octane the host replaces connections (`DB::reconnect()`, Messenger's `doctrine_ping_connection` middleware, tenancy switches), so a shared store keeps writing through a dead or wrong-tenant handle. The MySQL strict-mode check is cached per instance (`DatabasePersistence.php:53`, `:227-236`), while `EloquentPersistence` repeats it on every operation (`src/Workflow/Persistence/EloquentPersistence.php:76-83`).

`DatabasePersistence` joins a transaction the application opened on the same PDO as a savepoint (`DatabasePersistence.php:239-241`). Step commits and heartbeats are then invisible to other workers until the outer commit: competitors block on the locked control row (`DatabasePersistence.php:161-169`), and a rollback erases committed steps and memos whose side effects already happened, which quietly defeats durability. Schemas exist only as docblocks and disagree in shape: a composite primary key for the workflow store (`DatabasePersistence.php:29-45`) and a surrogate `id` with a unique index for messages (`SQLMessageStore.php:27-40`). The persistence contract test is a concrete test class (`tests/Workflow/Persistence/PersistenceContractTest.php:37`) in a directory excluded from the Composer archive (`.gitattributes`: `tests export-ignore`), so framework backends cannot prove conformance. Finally, `EloquentPersistence` and `EloquentMessageStore` live in core although `illuminate/database` is only a development requirement.

**Proposal.** Resolve connections per atomic operation, define one canonical schema per store, and ship the conformance suites.

```php
class DatabasePersistence implements PersistenceInterface, ManagedStoreInterface
{
    /** @param PDO|Closure(): PDO $connection resolved for every atomic operation (proposed, C13) */
    public function __construct(PDO|Closure $connection, string $table = 'workflow_store') {}
}

class SQLMessageStore implements MessageStoreInterface, ManagedStoreInterface
{
    /** @param PDO|Closure(): PDO $connection (proposed, C13) */
    public function __construct(PDO|Closure $connection, string $table = 'chat_messages') {}
}

// The same PDO|Closure signature for MariaDBVectorStore, MySQLToolkit and PGSQLToolkit.
// Error-mode and strict-mode checks are cached per PDO identity (a WeakMap), not per instance.

namespace NeuronAI\Testing\Contracts;

/** (proposed, C13) */
abstract class PersistenceContractTestCase extends TestCase
{
    abstract protected function persistence(): PersistenceInterface;
}
// Also: MessageStoreContractTestCase, VectorStoreContractTestCase,
// HttpClientContractTestCase (C17) and ProjectionStoreContractTestCase (C14).
```

Each store gets one canonical table, defined in one place in core and emitted by `setup()` (C4) for each driver. The encodings are part of the canonical definition, so that every backend, from core's PDO stores to the Laravel query builder and Doctrine DBAL, writes the same bytes, and a Laravel and a Symfony application that share a database can operate on the same runs.

| Store | Canonical shape | Encoding, lengths and collation |
|---|---|---|
| Workflow store | `id` primary key, `partition`, `key`, `value`, `created_at`, `updated_at`; unique (`partition`, `key`) | `partition` and `key` are hex-encoded, as `DatabasePersistence` stores them today (`src/Workflow/Persistence/DatabasePersistence.php:21-23`, `:295`): up to 255 bytes before encoding, so ASCII columns of 510 characters, with `ascii_bin` on MySQL and MariaDB, which also keeps the two-column unique index within InnoDB's key-length limit. `value` is the base64 of the serializer's bytes, in long text (ASCII on MySQL and MariaDB). |
| Message store | `id` primary key (insertion order), `thread_id`, `message_id`, `role`, `content`, `meta`, `archived_at`, `created_at`, `updated_at`; unique (`thread_id`, `message_id`) | `thread_id` (up to 255 characters), `message_id` (up to 64) and `role` (up to 32) are stored as given, with a binary collation (`utf8mb4_bin` on MySQL and MariaDB) so that identifiers compare byte for byte; `content` and `meta` are JSON in long text. |
| Run projection (C14) | `workflow_id` primary key, `workflow`, `run_id`, `attempt`, `status`, `interrupt_id`, `interrupt_type`, `event_name`, `deadline_at` (indexed), `recheck_at` (indexed), `definition_version`, `updated_at` | Identifiers and names are stored as given (`workflow_id` up to 255 characters) with a binary collation; times are UTC. |

The packages default to `neuron_`-prefixed table names. The contract suites live in `src/Testing` as abstract PHPUnit cases, loaded only by test suites, so the Laravel and Symfony adapters and any third-party backend extend them. Following the placement rule (section 6), `EloquentPersistence` and `EloquentMessageStore` move to the Laravel package, and the DBAL backends live in the Symfony bundle. `src/Workflow/AGENTS.md` replaces its savepoint guidance (`src/Workflow/AGENTS.md:121`) with an explicit rule (proposed, C13): never run a workflow inside an application transaction on the persistence connection; use a dedicated connection.

**Laravel benefit.** `new DatabasePersistence(fn () => DB::connection('neuron')->getPdo())` is a correct singleton under Octane and queue workers that follows reconnects and tenancy switches. The package migrations mirror the canonical DDL, and its query-builder and Eloquent backends prove conformance by extending the core suites.

**Symfony benefit.** The bundle's DBAL persistence, message store and projection store, which support non-PDO drivers and DBAL middlewares, extend the same suites; its `postGenerateSchema` listener mirrors the canonical DDL. Until they ship, a closure over `getNativeConnection()` works for `pdo_*` drivers.

**Alternatives considered.** Scoped or non-shared registration, rebuilt per job, works today but pays the reconstruction on every job and still breaks on a reconnect in the middle of a job. A `ConnectionProviderInterface` would be more API than a `Closure`. DBAL backends in core would serve Laminas and Mezzio users too, at the price of a heavy optional dependency; this is an open question in section 8.

**Breaking impact.** Constructor parameter types widen, which does not break PDO callers. The canonical schema changes the workflow store's table shape, so pre-release stores migrate once. The Eloquent adapters leave core.

**Interim against today's core.** The packages ship their own backends that resolve the connection per operation (query builder or Eloquent for Laravel, DBAL for Symfony) and vendor a copy of core's contract tests.

**Priority and effort.** P1, M.

### 5.4 Reference gateway

#### C14. Reference invocation gateway

**Problem.** The most delicate code of any integration is the loop that turns a queue message into a durable continuation: reconstruct the definition by name, bind it, run a fenced request, reconcile timers and projections from the returned state, acknowledge retained completions, classify refusals and converge after crashes. Core describes that loop only in prose, under "Platform-owned coordination" (`src/Workflow/AGENTS.md:79-83`: "A platform SDK is an invocation gateway"). The admission and refusal semantics it must mirror live in `WorkflowEngine::startRun()` and `continueRun()` (`src/Workflow/WorkflowEngine.php:196-252`, `:266-353`), and the recovery verbs exist only in exception messages (`src/Exceptions/RunInFlightException.php:39-89`). Without a reference implementation, the Laravel package and the Symfony bundle would each re-derive it, diverge in fence handling and test it twice, and every other host would do it a third time.

**Proposal.** A core leaf module, `NeuronAI\Gateway`, that depends on Workflow, Observability and PSR interfaces only; nothing in core depends on it. It is the reference gateway in the same sense that `Agent` is the reference composition: a consumer of the engine's public API, not a scheduler interface inside the engine.

```php
namespace NeuronAI\Gateway; // (proposed, C14)

enum InvocationKind: string
{
    case Start = 'start';   // interim transport only; with C9 no start crosses the wire
    case Resume = 'resume';
    case Answer = 'answer';
    case Signal = 'signal';
    case Wake = 'wake';
}

final class Invocation
{
    /** The fenced continuation of a run returned by ignite() (C9). */
    public static function resume(string $workflow, WorkflowRunSnapshot $run): self;
    /** From PendingExecution::request() (C9). */
    public static function answer(string $workflow, string $workflowId, ExecutionRequest $request): self;
    public static function signal(string $workflow, string $workflowId, string $name, array $payload): self;
    public static function wake(RunProjection $run): self;

    /** @return array<string, mixed> JSON-safe */
    public function toArray(): array;

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self;
}

final class Gateway
{
    public function __construct(
        protected ContainerInterface $workflows,          // PSR-11: definition name => definition
        protected WorkflowEngine $engine,                 // no verb uses it: see the algorithm and section 8
        protected ProjectionStoreInterface $projections,
        protected ClockInterface $clock,
        protected ?CompletionHandlerInterface $completions = null,
    ) {}

    public function invoke(Invocation $invocation): Disposition;

    /** Queued and synchronous callers alike reconcile from the state run() returned. */
    public function reconcile(string $workflow, WorkflowState $state): void;

    /**
     * The terminal-failure rule, called by the package once its transport gives up on an invocation:
     * reconciles the projection row from inspect() and parks it when the run is failed.
     */
    public function exhausted(Invocation $invocation): void;

    /**
     * @return iterable<Invocation> fenced wakes for due deadlines and expired executions,
     *                              and a resume at attempt 0 for pending runs whose dispatch was lost (C9)
     */
    public function due(int $limit): iterable;
}

enum DispositionKind
{
    case Done;
    case RetryAt;
    case Discard;
    case Fail;
}

final class Disposition
{
    public readonly DispositionKind $kind;
    public readonly ?int $retryAt;        // Unix seconds; set for RetryAt only
    public readonly ?string $reason;      // set for Discard only
    public readonly ?Throwable $error;    // set for Fail only

    public static function done(): self;
    public static function retryAt(int $timestamp): self;
    public static function discard(string $reason): self;
    public static function fail(Throwable $error): self;
}

interface ProjectionStoreInterface
{
    public function save(RunProjection $run): void;
    public function forget(string $workflowId, string $runId): void;
    public function find(string $workflowId): ?RunProjection;

    /** @return iterable<RunProjection> */
    public function due(DateTimeImmutable $now, int $limit): iterable;

    /**
     * Rows matching every criterion that is not null, least recently updated first; for operational
     * listings and retention.
     *
     * @return iterable<RunProjection>
     */
    public function list(
        ?string $workflow = null,
        ?string $status = null,
        ?string $definitionVersion = null,
        ?DateTimeImmutable $updatedBefore = null,
        int $limit = 100,
    ): iterable;
}

interface CompletionHandlerInterface
{
    /** At-least-once and before acknowledge(): implementations are idempotent by run ID. */
    public function completed(string $workflow, WorkflowState $state): void;
}
```

`RunProjection` mirrors the canonical projection table of C13. The module ships `InMemoryProjectionStore` for tests and `DatabaseProjectionStore`, over `PDO|Closure(): PDO` like the SQL stores of C13, for applications without a framework. `ProjectionStoreContractTestCase` covers every method, `find()` and `list()` included, because the packages read rows as well as write them: a signal endpoint checks that the row names the definition the signal addresses, version routing reads `definition_version` before dispatching, the interim start rule of C9 reads the row, and the operational commands list and prune runs from it.

`Gateway::invoke()` follows one algorithm for every host:

1. Resolve the definition by name and bind it with `for()` (C2); enable completion retention. Every later verb (`inspect()`, `run()`, `acknowledge()`, `abandon()`) goes through this handle, never through a shared engine, so that a definition whose own `persistence()` or `serializer()` hook selects another store is honoured (C3). An engine built from the package defaults would inspect the wrong store for such a definition, report no run and discard a live one.
2. For a wake, `inspect()` first, which writes nothing: a stale fence is discarded, and a deadline still in the future returns `retryAt(deadline)`.
3. Write the projection ahead: status `executing`, `recheck_at` = now + lease + sweep interval.
4. Run the fenced request.
5. Reconcile from the returned state: a suspension upserts the projection with the interrupt ID, type, event name, `deadline()` (C7) and definition version (C12) (when the package decorates the projection store, the same save dispatches a delayed wake); a completion calls the completion handler, then `acknowledge($runId)`, then forgets the projection.
6. Map refusals by type (C6). On a fence refusal, inspect: if the run is still this invocation's, converge (a failed run gets a fenced inputless recovery, a suspended run is reconciled, a completed run has its retained outcome recorded and acknowledged, a running run under a lease returns `retryAt(lease)`); otherwise discard.
7. Settle what the refusal mapping does not classify. Any other `Throwable` from a node leaves the run durably failed; the gateway saves the row as `failed` with a finite `recheck_at` (now + lease + sweep interval) and rethrows, so the transport's own backoff and retry budget apply and the sweep is the backstop for a lost retry. A signal refused because its wait is not open returns `retryAt`, and the transport bounds those retries by a signal window measured from the invocation's first dispatch, which the transport message carries; when the window has passed, the signal is discarded with a log entry, never failed, and the window stays below the transport's retry window. When the transport gives up on an invocation (a `fail` disposition it does not reroute, or an exhausted retry budget), the package calls `exhausted()`, which reconciles the row from `inspect()`: a failed run is parked (status `failed`, `recheck_at` null) until an operator recovers or prunes it, a suspended run keeps its row and its deadline so that an approval expiry or a timer still fires, a running run keeps the write-ahead's recheck, and a row whose run is gone is forgotten. A `DefinitionVersionException` never parks: the run is healthy, and its row keeps a finite recheck until a worker of its version picks it up.

The projection is an expiring hint, never the truth: it is derived only from returned state or `inspect()`, never from listeners. A package that wants timers faster than its sweep decorates `ProjectionStoreInterface` so that saving a suspended projection with a near deadline also dispatches a delayed wake; the sweep, which calls `due()` every minute under a lock, remains the backstop, and early or duplicate wakes are no-ops because every step inspects before it acts. Framework packages therefore only translate a `Disposition` into their queue verbs and provide storage, transports and schedulers.

The contract test cases prove each store in isolation, but the protocol is where two packages would diverge, so it gets an end-to-end suite of its own. C14 also ships `GatewayScenarioTestCase` in `src/Testing` (proposed), which a package runs through its real job or handler on an in-memory or database transport. It covers the same invocation delivered twice; a worker dying between `run()` and `acknowledge()` (a completion handler that throws once); a dispatch lost after `ignite()` and found by the sweep; a wake delivered early and then twice; a signal arriving before its wait; an answer redelivered after the attempt advanced (C8); a version mismatch (C12); and, until C9, a start redelivered after its acknowledgement. Each scenario asserts the final state, the provider call count (nothing is paid twice) and the projection row.

**Laravel benefit.** The package keeps an `InvokeWorkflow` job whose `handle()` maps `done`, `retryAt`, `discard` and `fail` to return, `release()`, a logged return and `fail()`, and whose `failed()` method hands the invocation to `exhausted()`; a projection store over its connection; and a scheduled `neuron:wake` command. All fence and retry logic is shared with Symfony and tested in core.

**Symfony benefit.** The bundle keeps a Messenger message and handler (`retryAt` becomes a redispatch with `DelayStamp::delayUntil()`, `fail` an `UnrecoverableMessageHandlingException`), a failure listener that calls `exhausted()` once Messenger will not retry, a DBAL projection store and a Scheduler task. Its behaviour is identical to Laravel's by construction.

**Alternatives considered.** Shipping the gateway as a separate package, `neuron-core/gateway`, pinned to the exact core minor version, keeps core library-only; it is acceptable if the maintainer prefers it, with the risk of drifting from the engine version whose semantics it encodes (section 8). Duplicating the gateway in each framework package is ruled out: divergent fence logic tested twice. Adding callbacks or a scheduler interface to the engine would violate the rule that core has no scheduler and no suspend, resume or complete callbacks (`src/Workflow/AGENTS.md:81`).

**Breaking impact.** Additive.

**Interim against today's core.** Until C14 ships, the gateway is written once, as an `@internal` pre-release of `neuron-core/gateway` that both packages depend on, with the surface above. In it, `inspect()` checks, the `start` kind with the start rule of C9's interim, and attempt re-fencing stand in for C6 to C9. It is replaced by `NeuronAI\Gateway` when C14 ships.

**Priority and effort.** P1, M.

### 5.5 HTTP, streaming and push

#### C15. HTTP edge contracts

**Problem.** Three pieces of every streaming endpoint are left to the integrator. Protocol headers exist only on two concrete adapters (`src/Agent/Adapters/AGUIAdapter.php:424-432`, `src/Agent/Adapters/VercelAIAdapter.php:357-365`), not on `StreamAdapterInterface` (`src/Workflow/Streaming/Adapter/StreamAdapterInterface.php:16-61`) and not on `AgentChunkAdapter`; `AGUIAdapter` also sends the hop-by-hop `Connection` header (`AGUIAdapter.php:429`), which is invalid over HTTP/2 and HTTP/3 and therefore behind Caddy and FrankenPHP. `SSEEncoder` yields only fully framed strings (`src/Workflow/Streaming/SSEEncoder.php:27-55`), while native SSE types such as Symfony's `ServerEvent` for `EventStreamResponse` want the payload and do the framing themselves; re-encoding loses the documented throw-back behaviour that lets the adapter close its protocol on an encoding failure. Laravel's `eventStream()` is unsuitable for other reasons: it appends `</stream>` and stops on disconnect. Admission is lazy (`src/Workflow/Workflow.php:258-296`), so a controller must prime the generator by hand to turn `RunInFlightException` into a 409 before the first byte. Priming is safe with `SSEEncoder::encode()` only because it drives the generator with `valid()`, `current()` and `next()` (`SSEEncoder.php:29-43`); a `foreach` consumer throws when admission returned a state without yielding (`Workflow.php:282-284`), a detail nobody should have to know.

The protocol endpoints themselves are duplicated. Deciding whether an AG-UI or Vercel request is a new turn or a continuation, and building the new turn's `UserMessage`, are written out in `skills/neuron-frontend-integration/SKILL.md:119-169` and again in `tests/Integration/Frontend/backend/router.php:71-115`. Both copies build the message from text only and drop attachments. The Frontend README states that the translators are continuation translators, "not HTTP servers or complete chat-request importers" (`src/Agent/Frontend/README.md:149`).

**Proposal.** Put headers on the adapter contract, expose payload-level encoding, ship a framework-neutral SSE stream, and add protocol request objects.

```php
interface StreamAdapterInterface
{
    // transform(), start(), end(), interrupt(), error() unchanged

    /** @return array<string, string> protocol headers, e.g. x-vercel-ai-ui-message-stream: v1 (proposed, C15) */
    public function headers(): array;
}

final class SSEEncoder
{
    /**
     * JSON payloads with encode()'s throw-back and return forwarding. (proposed, C15)
     *
     * @template TReturn
     * @param Generator<int, ProtocolEvent, mixed, TReturn> $events
     * @return Generator<int, string, mixed, TReturn>
     */
    public static function payloads(Generator $events): Generator;
}

namespace NeuronAI\Workflow\Streaming;

/** (proposed, C15) */
final class SSEStream
{
    /** Primes the generator, so admission runs and can throw before any header is sent. */
    public static function open(Generator $events, ?StreamAdapterInterface $adapter = null): self;

    /** @return array<string, string> SSE headers merged with the adapter's */
    public function headers(): array;

    /**
     * Frames; calls ignore_user_abort(true) and keeps draining after connection_aborted(). The workflow's
     * final state is forwarded as the generator's return value, so `$state = yield from $stream->frames()`.
     *
     * @return Generator<int, string, mixed, WorkflowState>
     */
    public function frames(): Generator;
}

namespace NeuronAI\Agent\Frontend;

/** (proposed, C15) */
final class AGUIRequest
{
    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self;

    public function threadId(): string;
    public function isContinuation(): bool;

    /** The new turn's message, attachments included; null for a continuation. */
    public function message(): ?UserMessage;

    /** @return array<string, mixed>|null the continuation payload for the translator */
    public function continuation(): ?array;

    /** @return list<FrontendTool> */
    public function tools(): array;
}
// VercelAIRequest::fromPayload() has the same shape without tools(): a Vercel request carries
// no tool catalog, so the backend declares its deferred tools itself.
```

`AgentChunkAdapter` returns plain SSE headers, and `AGUIAdapter` drops `Connection`. The request objects parse and validate; they never decide ownership: the thread ID they return is untrusted input, and the edge authorizes it before calling `for()` (C2).

**Laravel benefit.** The package's streaming `Responsable` is `response()->stream()` over a generator closure that delegates to `SSEStream::frames()` and hands the state it returns to `Gateway::reconcile()`, with `headers()` for any adapter and no `instanceof` checks; a conflict is rendered as a 409 by the exception handler because `open()` already admitted the run. The AG-UI and Vercel routes become thin wrappers over the request objects.

**Symfony benefit.** `NeuronStreamResponse` calls `open()` in its constructor and merges `headers()`; a `ServerEvent`-based variant built on `payloads()` keeps the throw-back semantics and, with C1 and `ignore_user_abort(true)`, is safe on `EventStreamResponse`. Responses stay valid over HTTP/2 and HTTP/3.

**Alternatives considered.** `method_exists()` checks on concrete adapters work today but break for custom adapters. Static per-class header constants would not let an adapter vary its headers. Making `events()` itself admit eagerly and return an admitted stream object changes the central verb of the Workflow for a concern that belongs to the edge. Writing the SSE stream and the request objects in each package duplicates the subtlest part of the protocol support.

**Breaking impact.** Custom adapters implement `headers()`; `getHeaders()` is renamed.

**Interim against today's core.** Prime with `$events->current()`, read headers from the concrete adapter and drop `Connection`, and parse protocol requests inside the package.

**Priority and effort.** P2, S.

#### C16. Push transports for the whole ecosystem

**Problem.** Core ships three channels: `CallbackChannel` (`src/Workflow/Streaming/Channel/CallbackChannel.php:17`), `PusherChannel`, typed on `Pusher\Pusher` (`PusherChannel.php:24-31`), and `RedisChannel`, typed on `\Redis` (`RedisChannel.php:10-16`); `RedisPersistence` also accepts only `\Redis` (`src/Workflow/Persistence/RedisPersistence.php:19-23`). There is no Mercure transport, although Mercure is Symfony's push path and FrankenPHP embeds a hub that any framework can use, and phpredis `RedisCluster` or Relay users cannot use the Redis backends. The streaming skill's Laravel example pushes through `CallbackChannel` with `Broadcast::private(...)->sendNow()` (`skills/neuron-streaming/SKILL.md:261-280`), which bypasses the envelope, fragmentation and sequencing that `AbstractChannel` owns (`AbstractChannel.php:32-36`), so an AG-UI snapshot or a tool output above the roughly 10 KB message limit of Pusher and Reverb is dropped.

**Proposal.** A core Mercure channel with no Symfony dependency, and Redis types widened behind a minimal internal adapter.

```php
namespace NeuronAI\Workflow\Streaming\Channel;

/** Publishes channel envelopes to a Mercure hub. (proposed, C16) */
final class MercureChannel extends AbstractChannel
{
    /** @param Closure(): string $jwt the publisher token, resolved per publish */
    public function __construct(
        protected HttpClientInterface $client,
        protected string $hubUrl,
        protected string $topic,
        protected Closure $jwt,
        protected bool $private = true,
    ) {}

    protected function deliver(string $batch): void; // form-encoded POST of topic, data and private
}

// RedisChannel and RedisPersistence accept \Redis|\RedisCluster (and Relay) through an internal adapter.
```

`PusherChannel` already works with Laravel Reverb through `Broadcast::connection('reverb')->getPusher()`. Its delivery always calls `triggerBatch()` (`PusherChannel.php:95`), and Laravel Reverb serves Pusher's batch endpoint (`POST /apps/{appId}/batch_events`, routed to its `EventsBatchController` in `src/Servers/Reverb/Factory.php`), so the channel works over Reverb unchanged, with its default batch size.

**Laravel benefit.** Reverb works through `PusherChannel` with full fragmentation, authorized in `routes/channels.php` by the same policy as the thread, and the documentation stops recommending `CallbackChannel` for broadcasts. Laravel applications served by FrankenPHP can use its hub through `MercureChannel`.

**Symfony benefit.** Mercure becomes a first-class push transport in core: the bundle derives the topic from the workflow ID, publishes private updates, and issues the subscriber JWT after the thread voter has authorized the user. Redis Cluster users get durable persistence and push channels.

**Alternatives considered.** A bundle-only channel over `symfony/mercure`'s `HubInterface::publish()` is the interim; it serves Symfony only and leaves FrankenPHP users of other frameworks without a path. A PHP consumer of the channel envelope, for SSE relays fed by `RedisChannel`, is useful and can follow. Predis support would need a second adapter and is left out.

**Breaking impact.** Additive; the Redis parameter types widen.

**Interim against today's core.** The Symfony bundle ships its own `MercureHubChannel` over `HubInterface::publish()`; Laravel uses `PusherChannel` over Reverb; Redis stays phpredis `\Redis` only.

**Priority and effort.** P2, S.

#### C17. HTTP layer contract

**Problem.** `HttpClientInterface` is the transport seam for providers, embeddings, vector stores, classifiers (`TypeSafeAI`), the reranking post-processors (`CohereRerankerPostProcessor`, `JinaRerankerPostProcessor`, `LocalAIRerankerPostProcessor`), toolkits and MCP, and its contract is not enforced. D2, D3 and D13 are its sharpest symptoms: `SSEParser` drops data containing "DONE", the Amp client never raises on error statuses, and `HttpException` exposes credentials. Beyond them, `HttpException` has no status accessor and always has code 0 (`src/Exceptions/HttpException.php:16-23`). The status is reachable only through the nullable public `response` property (`$e->response?->statusCode`). That property is null for network errors and never set on the Amp path (D3), and nothing types a failure as retryable or permanent, so every retry policy must dig into the response and re-implement the 408/425/429/5xx rule and the `Retry-After` parsing. Streams are never closed by the providers (no `close()` call anywhere in `src/Providers`), `CurlStream` has no destructor, and `StreamInterface` exposes neither status nor headers (`src/HttpClient/StreamInterface.php:14-39`) although `CurlStream` has both (`src/HttpClient/Curl/CurlStream.php:73-84`), so rate-limit headers are lost on the streaming path. Timeouts differ per client and mean different things: 300 seconds of total transfer for Curl (`CurlHttpClient.php:102`), 120 seconds for Guzzle (`GuzzleHttpClient.php:53`), 120 seconds of both transfer and inactivity for Amp (`AmpHttpClient.php:55`, `:126-127`), none of them related to the Agent's 600-second lease. The `onRequest()` and `onResponse()` hooks exist only on `CurlHttpClient` (`CurlHttpClient.php:193-228`), and `GuzzleHttpClient::stream()` ignores the configured options that `request()` merges (`GuzzleHttpClient.php:65-70` against `:89-94`), so proxy and TLS options silently do not apply to streaming, the main agent path. Finally, there is no adapter for the frameworks' HTTP stacks, so provider traffic is invisible to `Http::fake()`, Telescope, `MockHttpClient` and the Symfony profiler.

**Proposal.** One enforced contract, a typed error with retry metadata, and conformance tests that framework adapters also run.

```php
namespace NeuronAI\Exceptions;

class HttpException extends NeuronException
{
    public function statusCode(): ?int; // (proposed, C17)
}

/** 408, 425, 429 and 5xx; retryAt() from Retry-After (seconds or HTTP-date), otherwise now. (proposed, C17) */
final class RetryableHttpException extends HttpException implements RetryableException
{
    public function retryAt(): int;
}

namespace NeuronAI\HttpClient;

interface StreamInterface
{
    // eof(), read(), readLine(), close() unchanged
    public function statusCode(): int;       // (proposed, C17)

    /** @return array<string, string|string[]> */
    public function headers(): array;        // (proposed, C17)
}

/** The onRequest/onResponse taps, around any client. (proposed, C17) */
final class HookedHttpClient implements HttpClientInterface
{
    public function __construct(protected HttpClientInterface $inner) {}
    public function onRequest(callable $hook): static;
    public function onResponse(callable $hook): static;
}

namespace NeuronAI\HttpClient\Symfony;

/** Over symfony/http-client, a standalone library like Guzzle and Amp. (proposed, C17) */
final class SymfonyHttpClient implements \NeuronAI\HttpClient\HttpClientInterface
{
    public function __construct(protected \Symfony\Contracts\HttpClient\HttpClientInterface $client) {}
}
```

Every client raises `HttpException` for status 400 and above, and streams raise at header arrival. `SSEParser` matches `[DONE]` exactly, and every provider stream loop turns vendor error events into `ProviderException`. Providers close streams in `finally`. `HttpRequest` distinguishes an idle timeout from a maximum duration, with the same defaults in every client and documentation that the maximum stays below the lease. `HttpException` stores a redacted copy of the request (authorization and API-key headers masked), key and token constructor parameters carry `#[\SensitiveParameter]`, and bodies in messages are truncated. `GuzzleStream` becomes a generic `Psr7Stream`, since it only needs a PSR-7 stream (`src/HttpClient/Guzzle/GuzzleStream.php:23-26`). `HttpClientContractTestCase` (C13) checks status handling, incremental streaming, timeouts and header precedence. The contract binds every consumer of the seam, classifiers and rerankers included, so a framework adapter injected into all of them gives the same errors, fakes and telemetry everywhere. Following the placement rule, the Laravel adapter lives in the Laravel package. It sends each request through a fresh pending request of Laravel's HTTP client factory, so fakes registered at any time and `preventStrayRequests()` see the traffic, and the client dispatches the `ResponseReceived` and `ConnectionFailed` events that Telescope records.

**Laravel benefit.** `LaravelHttpClient` proves conformance with the core suite, `Http::fake()` works for provider calls, and a provider 429 becomes `retryAt(Retry-After)` on the job instead of a durable empty answer.

**Symfony benefit.** `SymfonyHttpClient` comes from core and wraps the `http_client` service, so `MockHttpClient`, the profiler's HTTP panel and scoped or retrying clients all see Neuron's traffic, under the same conformance suite.

**Alternatives considered.** Retries inside the HTTP client are rejected by core's design: transport retries belong to the platform, and durable recovery is the retry. A PSR-18 adapter alone cannot stream, which is the main path. Adding hooks to every client instead of one decorator multiplies the code paths that must stay identical.

**Breaking impact.** Amp users get exceptions where they used to receive error bodies. `StreamInterface` gains two methods, which custom streams implement. The Curl hooks move to the decorator, and `GuzzleStream` is renamed.

**Interim against today's core.** The package adapters raise and redact `HttpException` themselves, the Amp client is not offered in configuration, and D2 is a known risk until it is fixed.

**Priority and effort.** P1, M.

### 5.6 Providers

#### C5. Stateless providers

**Problem.** Calling a provider is a mutate-then-call protocol. `AIProviderInterface` declares `systemPrompt()`, `setTools()` and `setHttpClient()` as fluent mutators (`src/Providers/AIProviderInterface.php:21`, `:28`, `:60`); tools are stored on the instance and tool calls are validated against them (`src/Providers/HandleWithTools.php:22-28`, `:40-65`); the system prompt is stored too (for example `src/Providers/Anthropic/Anthropic.php:77`). Every caller mutates before it calls: `ChatNode` (`src/Agent/Nodes/ChatNode.php:78-98`), `StructuredOutputNode` (`src/Agent/Nodes/StructuredOutputNode.php:102-108`), `Summarization` (`src/Agent/Middleware/Summarization.php:157-160`) and `QueryTransformationPreProcessor` (`src/RAG/PreProcessor/QueryTransformationPreProcessor.php:41-45`). `structured()` rewrites instance state and restores it in `finally` (for example `src/Providers/Deepseek/Deepseek.php:45-64`), and stream state lives on the instance (`src/Providers/Anthropic/HandleStream.php:23-25`, `:45`; `src/Providers/OpenAI/HandleStream.php:25`, `:58`), which is the root of D1.

A provider therefore cannot be a shared container service: it keeps the previous caller's prompt and live tool objects across requests, and under `AsyncBranchRunner`, where branches interleave at every yielded chunk, one branch's stream accumulates into another branch's state and its tool calls are validated against another branch's tool list. Fluent `$this`-returning mutators also defeat decorators: a tracing or rate-limiting wrapper that delegates `systemPrompt()` returns the inner instance, and the following `chat()` bypasses the wrapper. Configuration is not uniform either: argument order and names vary (`Ollama(url, model)` at `src/Providers/Ollama/Ollama.php:39-44` against `OllamaEmbeddingsProvider(model, url)` at `src/RAG/Embeddings/OllamaEmbeddingsProvider.php:21-25`), the base URI is a fixed property on `Anthropic`, `OpenAI` and `Mistral` (`Anthropic.php:43`, `src/Providers/OpenAI/OpenAI.php:38`, `src/Providers/Mistral/Mistral.php:33`) so traffic cannot be pointed at a corporate gateway from configuration, and audio and image providers implement `AIProviderInterface` with meaningless tool methods (for example `src/Providers/OpenAI/Audio/OpenAITextToSpeech.php:32`), while `BedrockRuntime::setHttpClient()` is a no-op (`src/Providers/AWS/BedrockRuntime.php:97-102`).

**Proposal.** Per-call data travels in a request value; providers hold only immutable configuration and a shareable HTTP client. The repository already has the model this generalizes: the Classifier module's contract is `ClassifierInterface::classify(ClassificationRequest): ClassificationResult` (`src/Classifier/ClassifierInterface.php:13`), its guidance says "Definitions and results are immutable values. Reuse definitions across calls; do not store request input or questions on a provider instance" (`src/Classifier/AGENTS.md:16`), and `TypeSafeAI` takes its HTTP client in the constructor (`src/Classifier/TypeSafeAI/TypeSafeAI.php:43-57`). Classifiers are therefore safe to share today, and C5 gives chat providers the same shape.

```php
// Proposed API (C5): ProviderRequest and the new AIProviderInterface signatures.
namespace NeuronAI\Providers;

/** (proposed, C5) */
final class ProviderRequest
{
    /**
     * @param Message[] $messages
     * @param array<ToolInterface|ProviderToolInterface> $tools
     */
    public function __construct(
        public readonly array $messages,
        public readonly ?SystemMessage $instructions = null,
        public readonly array $tools = [],
    ) {}
}

interface AIProviderInterface
{
    public function getModel(): string;

    public function chat(ProviderRequest $request): ProviderResponse;

    /** @return Generator<int, StreamChunk, mixed, ProviderResponse> */
    public function stream(ProviderRequest $request): Generator;

    /**
     * @param class-string $class
     * @param array<string, mixed> $schema
     */
    public function structured(ProviderRequest $request, string $class, array $schema): ProviderResponse;
}

// One constructor convention for every HTTP chat and embeddings provider:
// __construct(string $key, string $model, array $parameters = [], string $baseUri = <vendor default>,
//             ?HttpClientInterface $httpClient = null, ...vendor extras by name)
```

`systemPrompt()`, `setTools()` and `setHttpClient()` leave the interface, and the HTTP client is set only through the constructor. Stream state is a local variable of each `stream()` call and is passed explicitly to enrichment; tool-call validation reads `$request->tools`. `ChatNode` builds `new ProviderRequest($messages, $request->instructions, $resources->tools->all())`, and middleware and processors build their own. Capability interfaces separate chat providers from audio and image providers, so a container can autoconfigure by capability. The constructor convention also fixes D4 structurally.

**Laravel benefit.** A manager with driver caching becomes correct: providers are singletons per configured name, injectable with the `#[NeuronProvider('fast')]` contextual attribute and shareable across Octane requests and concurrent branches; configuration maps one-to-one onto named arguments, `baseUri` included.

**Symfony benefit.** `neuron.provider.<name>` entries are ordinary shared services with `#[Target]` aliases, `Traceable` decorators for the profiler are trivial because there is no fluent chain to intercept, and the optional adapter that exposes Symfony AI Platform models as Neuron providers implements a four-method interface (three inference methods plus `getModel()`).

**Alternatives considered.** Immutable withers (`withInstructions()`, `withTools()` returning clones) are a smaller diff, but `structured()` still mutates internally, stream state still lives on the instance, and decorators must re-wrap every clone. Building a provider per segment and sharing only the HTTP client, today's workaround, is correct but wastes construction and leaves framework managers unsafe by default.

**Breaking impact.** `AIProviderInterface` changes for every built-in and custom provider. `FakeAIProvider` records `ProviderRequest` objects, and its assertions read them. Provider constructor parameter names and order are normalized.

**Interim against today's core.** Build providers per segment through a factory (a non-shared container service resolved inside the `provider()` hook, which today's core calls once per admitted segment, `src/Agent/HandleProvider.php:24-35`), and share only the HTTP client.

**Priority and effort.** P1, L.

### 5.7 Tools and MCP

#### C18. Tools and MCP as shareable prototypes

**Problem.** Attach-time configuration mutates the instance it is called on: `Tool::setName()`, `setMaxRuns()`, `visible()`, `requireApproval()` and `withApprovalPolicy()` (`src/Tools/Tool.php:81`, `:249`, `:255`, `:308-313`, `:330-335`), a toolkit's `exclude()`, `only()` and `with()` (`src/Tools/Toolkits/AbstractToolkit.php:30-49`), and the same three on `McpConnector` (`src/MCP/McpConnector.php:83-106`). An agent that calls `requireApproval(false)` on a container-shared tool switches the approval gate off for every other agent and every later request. The `ToolRegistry` constructor accepts duplicate names silently and `find()` returns the first match (`src/Tools/ToolRegistry.php:19-40`). Approval policies and the `ToolNode` error handler are closures (`Tool.php:330-335`, `src/Agent/Nodes/ToolNode.php:69-76`), which compiled containers and cached configuration cannot hold. A tool's schema is declared twice, in the `properties()` hook (`Tool.php:104-123`) and in the `__invoke()` signature, and nothing checks one against the other before `execute()` spreads inputs by property name (`Tool.php:363-368`). Toolkit inner tools bypass `isVisible()` (`src/Agent/Agent.php:193-194`, while standalone tools are filtered at `:207`), and the SQL write tools declare no approval policy.

MCP adds lifecycle problems. `McpClient` opens its session in its constructor (`src/MCP/McpClient.php:49-55`) and `McpConnector::tools()` lists tools on every call (`McpConnector.php:112-122`); since the `tools()` hook runs on every segment (`src/Agent/HandleTools.php:68-78`, called from `Agent::resources()` at `Agent.php:171-176`), the usual `McpConnector::make(...)->tools()` pays a session and a `tools/list` round trip per segment, and nothing closes a connector in a long-lived worker. The stdio transport leaks the environment (D12), hardcodes a 30-second receive timeout (`src/MCP/StdioTransport.php:149`), sleeps 500 ms on teardown (`:214`) and offers no working directory (`:83-89`). HTTP authentication is a static token or header set (`src/MCP/StreamableHttpTransport.php:196-198`). Results are not mapped to `ToolOutput` and `isError` is ignored, schemas with `anyOf` break the connector (D8), and tool annotations are captured (`src/MCP/McpTool.php:21`) but never used, so a server's `destructiveHint` does not trigger approval. Two servers exposing the same tool name collide.

**Proposal.** Configuration returns copies, policies can be services, names are validated, and MCP gets a real lifecycle.

```php
namespace NeuronAI\Tools;

interface ToolInterface
{
    // Attach-time configuration returns a configured copy; the receiver is unchanged. (proposed, C18)
    public function withApproval(bool|ApprovalPolicyInterface|Closure $policy = true): static;
    public function withMaxRuns(int $runs): static;
    public function withName(string $name): static;
    public function withVisibility(bool $visible): static;
}
// ToolkitInterface::only(), exclude(), with() and McpConnector::only(), exclude(), with() return copies.

/** (proposed, C18) */
interface ApprovalPolicyInterface
{
    /** True, false, or a reason string that counts as true. */
    public function decide(ToolInterface $tool): bool|string;
}

/** (proposed, C18) */
interface ToolErrorHandlerInterface
{
    /** A result settles the call; null lets the exception propagate. */
    public function handle(Throwable $error, ToolCall $call): string|ToolOutput|null;
}

/** For compiler passes and CI: signature, schema and attribute checks. (proposed, C18) */
final class ToolValidator
{
    /** @return list<string> violations */
    public static function validateClass(string $class): array;
}

namespace NeuronAI\Attributes;

/** Readable without instantiation. (proposed, C18) */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsTool
{
    public function __construct(public readonly string $name, public readonly string $description) {}
}
```

Duplicate tool names throw when a segment's registry is built, while `add()` stays idempotent for middleware that re-registers the same tool on every node run (`src/Agent/Middleware/ToolSearchMiddleware.php:57`, `:69`). Tools may define themselves by reflection: properties derived from the `__invoke()` signature (types, nullability, defaults, backed enums), with `#[SchemaProperty]` (`src/StructuredOutput/SchemaProperty.php`, extended to parameters) for descriptions and constraints; an explicit `properties()` still wins. Toolkit inner tools respect `isVisible()`, and the SQL write tools declare an approval policy.

For MCP, `McpClient` opens its session on first request instead of in its constructor (C4), the connector caches tool definitions per instance (optionally in a PSR-16 cache), and it gains `close()` for workers. The stdio transport gets an environment allowlist, a bounded teardown wait, and a configurable timeout and working directory. HTTP transports accept an authorization header resolver (`Closure(): array`) evaluated per request, for rotating service credentials; per-user tokens need a per-user connector, because the session and the cached tool list belong to one identity. Results are mapped to `ToolOutput`: content blocks, `isError` to `ToolOutput::error()`, and `structuredContent`. The raw `inputSchema` is passed through when the property types cannot represent it, and nullable `anyOf` is understood. Annotations such as `destructiveHint` map to a default approval policy; annotations can only raise the default (a `readOnlyHint` never waives an application-configured policy), and the policy states how absent annotations are treated (the specification's defaults make them destructive). A name prefix option keeps servers apart.

```php
McpConnector::make([
    'url' => 'https://crm.example.com/mcp',
    'headers' => fn (): array => ['Authorization' => 'Bearer '.$tokens->current()], // resolved per request (proposed)
    'prefix' => 'crm_',                                                            // (proposed)
    'timeout' => 30,                                                               // honoured by the HTTP transports today; C18 makes stdio configurable too
])->only(['search_contacts', 'create_ticket']);                                    // returns a copy (proposed)
```

**Laravel benefit.** Tools and toolkits are autowired singletons, discovered into a catalog but granted per agent, which configures its own copies. Approval policies and error handlers are container services. MCP connectors with static credentials are per-worker singletons closed on `WorkerStopping`; connectors that authenticate per user are `scoped()` to the request or job, because the session and the cached tool list belong to one identity. The package can expose Neuron tools and durable workflows as MCP tools through laravel/mcp.

**Symfony benefit.** Tools autoconfigured as shared services with the `neuron.tool` tag are safe in FrankenPHP and Messenger workers. Policies and error handlers are services implementing `ApprovalPolicyInterface` and `ToolErrorHandlerInterface`, so the compiled container holds no closures, and a compiler pass runs `ToolValidator` and the duplicate-name check at build time. Connectors with static credentials are shared services whose `close()` runs on `kernel.reset`; since `kernel.reset` runs after every request in worker mode and after every Messenger message, sessions are reopened lazily per request or message (per-worker reuse needs `messenger:consume --no-reset`, or closing on worker stop instead). Per-user connectors are built by a factory service per request or message. Tools and workflows can be exposed through symfony/mcp-bundle.

**Alternatives considered.** Registering every tool as non-shared, today's rule, still leaks when a toolkit is built once in an agent's constructor. Cloning inside `Agent::resolveTools()` on every segment hides the mutation but keeps a mutable API that behaves differently outside an agent.

**Breaking impact.** Mutators are replaced by withers; toolkit and connector configurators return copies; duplicate names throw; MCP results become `ToolOutput`; stdio servers no longer inherit the whole environment unless configured to.

**Interim against today's core.** Register tools as non-shared and clone them before configuring; prefer MCP HTTP transports, or wrap stdio commands in `env -i` with an explicit variable list; close a connector by dropping its last reference (the client disconnects in its destructor, `src/MCP/McpClient.php:57-63`), for example on `WorkerStopping` or `kernel.reset`.

**Priority and effort.** P1, M.

#### C19. Framework-neutral definition metadata

**Problem.** Integrations need stable names that can be read without instantiating anything. Queue messages must carry an alias rather than a class name that breaks on rename and leaks internals (C9, C14); compiled containers need names to build locators and to detect duplicates; the definition version must be declared somewhere (C12). Core has no such metadata: a workflow is known only by its class, and a tool's name and schema are instance state (`src/Tools/Tool.php:30-32`, `:104-123`), readable only after construction.

**Proposal.** Framework-neutral attributes in `NeuronAI\Attributes`, placed on Workflow, Agent and RAG classes, next to `AsTool` (C18).

```php
namespace NeuronAI\Attributes;

/** (proposed, C19) */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsWorkflow
{
    public function __construct(
        public readonly string $name,            // the stable alias that jobs and messages carry
        public readonly ?string $version = null, // stamped into Ignition (C12)
    ) {}
}

#[AsWorkflow(name: 'support')]
final class SupportAgent extends Agent {}
```

Discovery reads the attribute through reflection, without instantiating the class. Three short names are shared with the Symfony ecosystem. Symfony AI has an `AsTool` attribute (`Symfony\AI\Agent\Toolbox\Attribute\AsTool`); the open RFC for a generic Symfony Durable component (symfony/symfony#66257) also proposes an `#[AsWorkflow]` attribute; and Symfony AI's stores declare a `ManagedStoreInterface` (C4). Neuron's types live in their own namespaces, generated code and documentation always import `NeuronAI\Attributes\AsTool`, `NeuronAI\Attributes\AsWorkflow` and `NeuronAI\ManagedStoreInterface` explicitly, and `neuron:setup` never provisions Symfony AI stores.

**Laravel benefit.** The package builds a manifest from configured paths (by default `app/Neuron`), cached by `neuron:cache` and registered with `optimizes()`, and binds each definition under its name; an explicit configuration map remains available. Duplicate names fail the optimize step.

**Symfony benefit.** `registerAttributeForAutoconfiguration(AsWorkflow::class, ...)` tags definitions with their name; the bundle wires the gateway's `$workflows` argument with `tagged_locator('neuron.workflow', indexAttribute: 'name')`, the same locator application services receive through `#[AutowireLocator('neuron.workflow', indexAttribute: 'name')]`; and duplicate names fail container compilation.

**Alternatives considered.** Package-specific attributes would make classes non-portable between Laravel, Symfony and the core CLI. Configuration maps alone are explicit but verbose; they remain the fallback. A `name()` hook needs an instance and is unavailable at compile time; the `version()` hook of C12 remains available at run time but cannot feed compile-time checks. Class names on the wire break on every rename.

**Breaking impact.** Additive.

**Interim against today's core.** Names come only from the packages' explicit configuration maps; the Symfony bundle may ship its own attribute with the same shape until core has one.

**Priority and effort.** P2, S.

### 5.8 Structured output

#### C20. Structured output seam

**Problem.** Schema generation, deserialization and validation are hardwired static calls inside `StructuredOutputNode`: `JsonSchema::make()->generate()` (`src/Agent/Nodes/StructuredOutputNode.php:74`), `Deserializer::make()->fromJson()` (`:165`) and `Validator::validate()` (`:169`), and `Agent::nodes()` builds the node with `new StructuredOutputNode()` (`src/Agent/Agent.php:280`). Neither framework can plug in its own validator, serializer, translator or type system without subclassing the node and rebuilding the Agent's graph, and validation messages cannot be localized.

**Proposal.** One interface for the whole pipeline, delivered through the segment's resources and the defaults tier.

```php
namespace NeuronAI\StructuredOutput;

/** (proposed, C20) */
interface OutputMapperInterface
{
    /**
     * @param class-string $class
     * @return array<string, mixed> JSON schema
     */
    public function schema(string $class): array;

    /** @param class-string $class */
    public function map(string $json, string $class): object;

    /** @return list<string> violations */
    public function validate(object $output): array;
}

/** Today's JsonSchema, Deserializer and Validator behind the interface. */
final class OutputMapper implements OutputMapperInterface {}

// AgentResources gains: public readonly OutputMapperInterface $output
// Agent::outputMapper() consults the defaults tier (C3), then falls back to OutputMapper.
```

**Laravel benefit.** A `LaravelOutputMapper` bound in the defaults validates output DTOs with Laravel validation rules and localized messages.

**Symfony benefit.** When the components are installed, the bundle binds a `SymfonyOutputMapper` over the Serializer, Validator constraints and Translator, with TypeInfo for schemas, which is what Symfony AI users expect.

**Alternatives considered.** Three separate interfaces are finer-grained but need three defaults keys for one pipeline. Subclassing `StructuredOutputNode` is today's workaround.

**Breaking impact.** `StructuredOutputNode` reads `$resources->output`, and `AgentResources` gains a constructor parameter.

**Interim against today's core.** Subclass `StructuredOutputNode` and swap it in `Agent::nodes()`.

**Priority and effort.** P2, S.

### 5.9 RAG

#### C21. RAG composed for containers and durable ingestion

**Problem.** Ingestion is two imperative methods on a RAG instance, which is an Agent (`src/RAG/RAG.php:62-79`, `:87-109`), so a queued job, a model observer or a Doctrine listener must build a whole agent to index a record. `addDocuments()` validates chunk by chunk after earlier chunks were already written (`RAG.php:70-78`), and `reindexBySource()` deletes a source before its replacement has been validated or embedded (`:100-107`). Every `Document` gets a random UUID (`src/RAG/Document.php:41`) while several stores insert rather than upsert (`MongoDBVectorStore.php:101`, `TypesenseVectorStore.php:119`, `:180`, and Elasticsearch without `_id`, D7), so a retried job duplicates chunks, and a failure in the middle of a reindex leaves the source empty or partially indexed. The RAG nodes receive live services through their constructors (`RAG.php:49-57`), so building the graph, even for `export()`, resolves network-bound stores, and middleware cannot reach retrieval through the segment's resources. An unconfigured store silently falls back to `MemoryVectorStore` (`src/RAG/ResolveVectorStore.php:20-23`), which loses everything between processes.

The contracts are also thin for configuration-driven hosts. `EmbeddingsProviderInterface` exposes no dimensions (`src/RAG/Embeddings/EmbeddingsProviderInterface.php:14-22`), so migrations and vector-store schemas duplicate them without a check. Retrieved documents keep their embeddings, which then flow into memos, logs and tool output (`Document.php:179`). Schemas and filters cannot be built from configuration or carried in a message (no `fromArray()`). Data loaders return arrays (`src/RAG/DataLoader/DataLoaderInterface.php:14`) and readers are static functions of a file path (`src/RAG/DataLoader/ReaderInterface.php:9`), so they cannot read from Laravel's Storage or Flysystem and load large corpora at once. Filter compilers are per store (`src/RAG/VectorStore/Compilers`), with no reusable SQL compiler and no pgvector store. D15 is the scope bypass in `RetrievalTool`.

**Proposal.** A stateless indexer, an upsert contract with deterministic IDs, an optional durable ingestion workflow, and RAG nodes that read the segment's resources.

```php
namespace NeuronAI\RAG;

/** Stateless: one shared service per configured store. (proposed, C21) */
final class Indexer
{
    public function __construct(
        protected VectorStoreInterface $store,
        protected EmbeddingsProviderInterface $embeddings,
    ) {}

    /** Validates the whole batch before any write, embeds, then upserts by document ID. */
    public function index(iterable $documents, int $batchSize = 50): void;

    /** Upserts the source's new chunks, then deletes only its stale ones: no retrieval gap. */
    public function reindexSource(string $sourceType, string $sourceName, iterable $documents, int $batchSize = 50): void;
}

/** RAG nodes read retrieval, scope and processors from the segment. (proposed, C21) */
class RAGResources extends AgentResources {}

namespace NeuronAI\RAG\Embeddings;

interface EmbeddingsProviderInterface
{
    // embedText(), embedDocument(), embedDocuments() unchanged
    public function dimensions(): int; // (proposed, C21)
}
```

Splitters assign deterministic chunk IDs, a UUIDv5 of the source type, source name and chunk index. The index counts chunks across the whole source, not per input document. Documents that still carry the default `manual` source (`src/RAG/Document.php:28-30`; only `FileDataLoader` sets a real one) have no source identity: they keep random IDs and are not idempotent, because with upserts two unrelated documents sharing the default identity would overwrite each other. `VectorStoreInterface::addDocuments()` upserts by document ID in every store, enforced by `VectorStoreContractTestCase` (C13). An optional durable `IngestionWorkflow` commits one step per batch; its workflow ID, derived from the store and the source, doubles as a per-source mutex through `RunInFlightException`. `vectorStore()` throws when nothing is configured instead of falling back to memory. Retrieved documents drop their embeddings. `DocumentSchema::fromArray()` and `FilterExpression::fromArray()` make schemas configurable and filters transportable (`FilterExpression` is an interface today, `src/RAG/VectorStore/Filter/FilterExpression.php:7`, so it becomes an abstract base, or the factory lives on a concrete class, because a static method on an interface cannot carry the dispatch). Data loaders return iterables, and readers become instance services that read content or streams. Core adds a PDO pgvector store and a reusable SQL filter compiler contract, and `RetrievalTool` takes a mandatory scope.

**Laravel benefit.** An `Indexer` singleton per configured store backs ingestion jobs grouped with `Bus::batch()` and an `Embeddable` model trait that uses the model's class and key as the source identity; retries converge instead of duplicating. Loaders read from `Storage` disks, and pgvector pairs with Laravel's native vector columns and vector query clauses, available on both supported majors.

**Symfony benefit.** `neuron.indexer.<store>` services feed Messenger ingestion messages and Doctrine entity listeners, console commands call the same service, loaders read through Flysystem, and the graph builds with no I/O.

**Alternatives considered.** Keeping ingestion on `RAG` forces agent construction just to index. Relying on queue uniqueness (`ShouldBeUnique`, `DeduplicateStamp`) is best effort and does not fix partial writes. A pgvector store in each package would duplicate the SQL compiler work that core already does for MariaDB.

**Breaking impact.** Ingestion moves from `RAG::addDocuments()` and `reindexBySource()` to `Indexer`; stores switch to upsert semantics; chunk IDs become deterministic; RAG node constructors change; an unconfigured vector store throws; loader and reader signatures change; `RetrievalTool` requires a scope.

**Interim against today's core.** Build a RAG instance per ingestion job, assign deterministic IDs with `Document::setId()`, offer only stores that already upsert by ID (or delete and re-add under a lock), and ship pgvector inside the packages until core has it.

**Priority and effort.** P1 for the Indexer, the upsert contract, deterministic IDs, the memory fallback and the retrieval scope; P2 for the rest. M.

### 5.10 Observability

#### C22. Observability that cannot break or double-count execution

**Problem.** Events emitted by nodes go through `Node::emit()` (`src/Workflow/Node.php:205-217`) and `SegmentEventDispatcher::dispatch()`, which does not catch (`src/Workflow/Executor/SegmentEventDispatcher.php:28-35`); lifecycle events go through `report()`, which turns a listener failure into a `WorkflowError` (`:42-54`). A failing host listener, such as a metrics database that is down or a queued listener whose payload cannot be serialized, therefore fails an inference or tool step when the event comes from a node (`InferenceStart` and `InferenceStop` at `src/Agent/Nodes/ChatNode.php:45`, `:57`; `ToolCalling` and `ToolCalled` at `src/Agent/Nodes/ToolNode.php:419`, `:438`), and marks the durable run failed. Because `ToolCalled` is emitted in a `finally` block (`ToolNode.php:437-439`), a listener that throws there replaces the tool's own exception.

Events are live, mutable object graphs: `source` holds the emitting node or the workflow itself, closures included, and `execution` holds the context (`src/Observability/ObservabilityEvent.php:25-33`). Laravel queued listeners, Messenger messages routed to an asynchronous transport and profiler storage cannot serialize them. Replays re-emit `InferenceStart`/`InferenceStop` around `recallMemo('inference')` (`ChatNode.php:45-57`) and `ToolCalling`/`ToolCalled` around the tool memo (`ToolNode.php:419-439`) with nothing marking them as replays, so usage and billing listeners double count after every recovery. Inference events carry no provider class or model, and the provider calls made by middleware and processors emit nothing (`src/Agent/Middleware/Summarization.php:157-160`, `src/RAG/PreProcessor/QueryTransformationPreProcessor.php:41-45`). `LogListener` logs every event at one level (`src/Observability/LogListener.php:22-31`). `src/Testing` has no recording listener. The deprecated observer path still ships: `observe()` (`src/Workflow/HandleDispatcher.php:20-27`), `ObserverInterface`, `ObserverAdapter` and `LogObserver`.

**Proposal.** One isolation contract for every event, a serializable projection, replay and provider metadata, and the removal of the old path.

```php
// Proposed (C22).
// Node::emit() goes through the isolating path: a failing listener, local or the forwarded
// host dispatcher, becomes a WorkflowError(unhandled: false) and never fails the step.

namespace NeuronAI\Observability;

/** (proposed, C22) */
final class EventRecord implements JsonSerializable
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $class,
        public readonly string $name,
        public readonly array $data,
        public readonly ?string $workflowId,
        public readonly ?string $runId,
        public readonly ?int $attempt,
        public readonly ?string $branchId,
        public readonly DateTimeImmutable $occurredAt,
    ) {}
}

abstract class ObservabilityEvent
{
    public function record(): EventRecord; // (proposed, C22)
}

namespace NeuronAI\Agent\Observability;

use NeuronAI\Observability\ObservabilityEvent;

class InferenceStop extends ObservabilityEvent
{
    public bool $replayed = false; // (proposed, C22); the same flag on ToolCalled
}
```

Inference events carry the provider class and model, and events are emitted around the provider calls that middleware and processors make. `LogListener` chooses the level per event (errors as errors, streaming chatter as debug). `src/Testing` gains a public recording listener with assertions. An optional span-pairing interface on start and end events can follow for OpenTelemetry-style tracers. `observe()`, `ObserverInterface`, `ObserverAdapter` and `LogObserver` are removed.

**Laravel benefit.** `IlluminateEventBridge` forwards raw events to synchronous listeners and `EventRecord`s to queued listeners, Pulse recorders and broadcasts (a Nightwatch integration is to verify). A failing Telescope or metrics listener can no longer fail a step, and cost dashboards stay correct after recoveries.

**Symfony benefit.** The profiler `DataCollector` stores `EventRecord`s, which works because collector data must be serializable; asynchronous telemetry routing uses the same records, and tagged listeners cannot break an agent step. Replayed spans appear as such in the Stopwatch timeline.

**Alternatives considered.** Each bridge could wrap the forward in `try`/`catch`, which works today, but local `subscribe()` listeners and node-emitted events would keep two different semantics. A marker interface for catch-all listeners helps only interface-based matching; since Symfony matches exact event names, the bundle keeps Neuron's own instanceof registry either way.

**Breaking impact.** Listener exceptions on node-emitted events no longer propagate, which is a behaviour change. Two public flags appear. The deprecated observer API is removed.

**Interim against today's core.** Each package wraps its own listeners and its forward in `try`/`catch` and reports failures itself, builds records by hand from `toArray()` and the execution context, and documents the double counting after recovery.

**Priority and effort.** P1, S.

### 5.11 Console and evaluation

#### C23. Console, evaluation and stubs

**Problem.** `EvaluationCommand::run(array $args)` parses `argv` itself and writes with `echo` (`src/Console/Evaluation/EvaluationCommand.php:55`, `:130-209`), as does the console output driver (`src/Evaluation/Output/ConsoleOutput.php:43-81`). Artisan and `bin/console` can already pass a container resolver and a runner through the constructor (`EvaluationCommand.php:43-50`, precedence at `:137-138`), but they must synthesize an argv array to drive it and cannot render its output through their own writers. `ConfigLoader::load()` requires `evaluation.php` from the working directory again on every getter call (`src/Evaluation/Config/ConfigLoader.php:23-41`, getters at `:58-102`). `--cache` always builds a `FileEvaluationCache` (`EvaluationCommand.php:140-141`). Discovery is a regex that misses `final` and `readonly` classes (D10), with no way to pass an explicit list. The generator stubs are not reusable by framework generators: the agent stub constructs `new Anthropic(key: 'ANTHROPIC_KEY', ...)` inline in `provider()` (`src/Console/Make/Stubs/agent.stub`), and the documentation pattern it mirrors calls `env()` inside hooks (D17).

**Proposal.** A typed application service shared by all three CLIs, and framework-neutral stubs.

```php
namespace NeuronAI\Evaluation;

/** Shared by bin/neuron, artisan and bin/console. (proposed, C23) */
final class EvaluationService
{
    /** @param Closure(class-string): object $resolver evaluators and output drivers, usually the host container */
    public function __construct(
        protected Closure $resolver,
        protected EvaluatorRunner $runner,
        protected ?EvaluationCacheInterface $cache = null, // the existing cache contract
    ) {}

    /**
     * @param string|list<class-string> $evaluators a directory or an explicit class list
     * @param EvaluationOptions $options concurrency, verbosity, cache refresh (proposed)
     */
    public function run(string|array $evaluators, EvaluationOptions $options, OutputWriterInterface $writer): EvaluationReport;
}

/** Where progress and summaries go instead of echo. (proposed, C23) */
interface OutputWriterInterface
{
    public function write(string $text): void;
}
```

`ConfigLoader` loads once, the cache implementation is selected from configuration, and discovery is token-based and also accepts an explicit class list. Output drivers are still resolved after the runs; `ConsoleOutput`, and `JsonOutput` without a path, write through the `OutputWriterInterface` passed to `run()` instead of `echo`. The stubs use constructor injection, `#[AsWorkflow]` (C19), no `env()` and no inline `new Provider` in hooks.

Resolving evaluators from a host container raises one more question: the definitions they receive are wired to production persistence and history through the defaults tier (C3), so an evaluation would write runs and chat rows into production stores. The framework commands therefore run with an evaluation-scoped defaults locator, in-memory persistence and history with the real providers, unless the caller opts into persistence. `EvaluatorRunner`'s fork hooks (`beforeChild` and `afterChild`, `src/Evaluation/Runner/EvaluatorRunner.php:39-46`) reuse the connection callables the packages already use to make forked children safe.

**Laravel benefit.** `neuron:evaluate` wraps the service with the container as resolver and the command's output as writer, runs with the evaluation-scoped defaults locator (in-memory persistence and history, real providers) unless the caller opts into persistence, and passes a runner whose `beforeChild` hook purges and reconnects the database and Redis connections; `make:neuron-*` generators render the core stubs, so generated classes are the same in every framework.

**Symfony benefit.** The same service backs an `#[AsCommand]` command with Symfony Console output, with the same evaluation-scoped defaults locator unless the caller opts into persistence and the bundle's fork-safe `beforeChild` callable, which makes each forked child reconnect Doctrine on its own socket, and the MakerBundle makers render the same stubs.

**Alternatives considered.** Wrapping `EvaluationCommand` with output buffering works today but loses streaming progress and formatting. Package-owned stubs drift from core conventions over time.

**Breaking impact.** `EvaluationCommand`'s internals change behind the same `bin/neuron` interface; stubs change.

**Interim against today's core.** Wrap `EvaluationCommand` with a container resolver and buffer its output; ship package-owned stubs.

**Priority and effort.** P2, S.

### 5.12 Agent ergonomics

#### C24. Agent ergonomics for endpoints

**Problem.** Several small gaps push endpoints toward subclassing or concrete types. `ToolNode::buildApprovalRequest()` never passes an expiry (`src/Agent/Nodes/ToolNode.php:326-358`) although `ApprovalRequest` accepts one (`src/Agent/Interrupt/ApprovalRequest.php:44-51`), so "auto-reject after 24 hours" requires subclassing `ToolNode` and swapping it into `Agent::nodes()`. Today's `ToolNode` would ignore an expiry anyway: a timed-out resume yields no decisions, and the loop at `ToolNode.php:164-170` re-suspends with a new `ApprovalRequest`. `getChatHistory()` is final and builds `ChatHistory` with the default trimmer (`src/Agent/Agent.php:155-165`, `src/Chat/History/ChatHistory.php:32-37`), leaving an override of `resources()` that duplicates its body as the only seam for a tokenizer-based counter; a plain instance would not do anyway, because the trimmer is stateful (`src/Chat/History/HistoryTrimmer.php:31-34`). `chat()`, `stream()` and `structured()` accept `Message|array` but not a string (`Agent.php:330`, `:348`, `:361`; `src/Agent/AgentInterface.php:74-85`). `structured()` returns `$finalState->get('structured_output')` (`Agent.php:373`), which is null when the run suspended on an approval, so an endpoint cannot tell "paused" from "no output". And `AgentInterface` does not declare `pendingApprovals()`, `submitApprovalDecisions()` and `submitToolResults()` (`AgentInterface.php:19-86` against `Agent.php:389-422`), so generic controllers, decorators and fakes must type against the concrete class.

**Proposal.**

```php
// Proposed API (C24): the new setters and hooks, the widened signatures, the exception and the interface additions.
class Agent extends Workflow implements AgentInterface
{
    /** Relative approval deadline, memoized with the request so partial submissions do not extend it. (proposed, C24) */
    public function setApprovalTimeout(?DateInterval $timeout): static;
    protected function approvalTimeout(): ?DateInterval;

    /** @param Closure(): HistoryTrimmerInterface $factory a factory, since trimmers are stateful (proposed, C24) */
    public function setHistoryTrimmer(Closure $factory): static;
    protected function historyTrimmer(): HistoryTrimmerInterface;

    public function chat(string|Message|array $messages = []): AgentState;   // a string becomes a UserMessage
    public function stream(string|Message|array $messages = []): Generator;

    /** @throws StructuredOutputSuspendedException when the run suspends instead of producing output */
    public function structured(string|Message|array $messages = [], ?string $class = null, int $maxRetries = 1): mixed;
}

/** (proposed, C24) */
final class StructuredOutputSuspendedException extends AgentException
{
    public function __construct(public readonly InterruptRequest $interrupt) {}
}

interface AgentInterface
{
    public function chat(string|Message|array $messages = []): AgentState;
    public function stream(string|Message|array $messages = []): Generator;
    public function structured(string|Message|array $messages = [], ?string $class = null, int $maxRetries = 1): mixed;

    /** @return Action[] */
    public function pendingApprovals(): array;

    public function submitApprovalDecisions(array $decisions): PendingExecution;

    public function submitToolResults(array $results): PendingExecution;
}
```

When the approval deadline passes, `ToolNode` rejects every call still pending with a reason such as "Approval expired" and continues, the same way `AwaitToolResultsNode` settles expired tool results (`src/Agent/Nodes/AwaitToolResultsNode.php:57-63`). The absolute deadline is computed once from the workflow clock (C10) and memoized, so partial submissions do not extend it.

**Laravel benefit.** A controller passes the validated prompt string directly; an approval expiry becomes an ordinary interrupt deadline that the projection and the sweep deliver with no controller logic; a structured-output endpoint catches the typed exception and answers 202 with the interrupt; agent test doubles and decorators the package ships can implement `AgentInterface` completely.

**Symfony benefit.** The same through `#[MapRequestPayload]` DTOs, and service decorators and test doubles can target `AgentInterface`.

**Alternatives considered.** Documenting `run(ExecutionRequest::start(new AgentStartEvent($messages, new AgentRunOptions(outputClass: $class))))` and branching on the returned status works today and is the interim, but it is verbose for the common case. Returning a result object from `structured()` that carries either the output or the interrupt changes the return value for every caller.

**Breaking impact.** `AgentInterface` gains three methods and widens `chat()`, `stream()` and `structured()` to accept a string; `structured()` throws instead of returning null on suspension; an expired approval now rejects its pending calls.

**Interim against today's core.** Wrap input in `UserMessage`; subclass `ToolNode` to pass an expiry from `buildApprovalRequest()` and to reject still-pending calls when `resolveToolApprovals()` resumes on a timeout; override `resources()` to build the `ChatHistory` with a custom trimmer; run structured starts explicitly and branch on status.

**Priority and effort.** P2, S.

### 5.13 Hygiene

#### C25. Hygiene for the new major

**Problem.** The 4.x branch still ships APIs documented as "removed in the next major version": `observe()` (`src/Workflow/HandleDispatcher.php:20-27`) with `ObserverInterface`, `ObserverAdapter` and `LogObserver`, `Node::checkpoint()` (`src/Workflow/Node.php:115-124`), `SESTool` (`src/Tools/Toolkits/AWS/SESTool.php:24`) and the Zep toolkit (`src/Tools/Toolkits/Zep/ZepLongTermMemoryToolkit.php:12`); the Supadata toolkit carries the same notice (`src/Tools/Toolkits/Supadata/SupadataYouTubeToolkit.php:12`). About thirty classes declare `private` members despite the repository rule (for example `AzureOpenAI::setBaseUrl()` at `src/Providers/OpenAI/AzureOpenAI.php:37`, `OpenSearchVectorStore::mapVectorDimension()`, `GuzzleStream::$stream`), which blocks exactly the subclassing framework packages rely on. D17 lists the documentation drift. `AsyncBranchRunner` imports `Amp\async` and `Amp\Future` (`src/Workflow/Executor/AsyncBranchRunner.php:7-12`), but `amphp/amp` is only a development requirement and is not suggested in `composer.json`. `ParallelToolNode` forks the host process whenever `pcntl` and `spatie/fork` are present (`src/Agent/Nodes/ParallelToolNode.php:70-82`), which is unsafe inside FPM, Octane or Swoole request workers, and it rebuilds child exceptions unsafely (D11).

**Proposal.** Remove the deprecated APIs; change `private` to `protected`; fix the documentation and skills; add `amphp/amp` to `suggest`, or make `AsyncBranchRunner` fail fast with a clear message when it is missing. Parallel tool calls stay an explicit opt-in (`Agent::parallelToolCalls()`, `src/Agent/Agent.php:84-98`), and `ParallelToolNode` refuses to fork outside the CLI SAPI, which covers FPM and FrankenPHP. Octane on Swoole or RoadRunner also runs in the CLI SAPI, so the Laravel package additionally refuses parallel tool calls inside Octane workers. Child failures travel in one wrapper exception that carries the original class, message and code as data. The Workflow and Agent `AGENTS.md` files are updated with every proposal that changes a documented contract (C1, C2, C3, C6, C7, C8, C9, C10, C11, C12, C13, C24).

**Laravel benefit.** The package never has to hide or wrap APIs that are about to disappear, and `parallelToolCalls()` cannot fork an FPM or FrankenPHP worker, while the package's own guard keeps it out of Octane workers.

**Symfony benefit.** The same for FrankenPHP workers, where forking is refused, while `messenger:consume` remains the supported place for parallel tool calls with child hooks that reconnect Doctrine connections; bundle classes can extend core classes where the documentation says they may.

**Alternatives considered.** Keeping the deprecated APIs through 4.x costs every package a compatibility layer for a major that explicitly drops compatibility.

**Breaking impact.** The removals are breaking by definition; visibility changes are not. Behaviour also changes: `ParallelToolNode` no longer forks outside the CLI SAPI, and tool error handlers receive the wrapper exception instead of the child's original class; the Supadata toolkit goes with the other deprecated toolkits.

**Interim against today's core.** The packages never expose `observe()` or `Node::checkpoint()`, and document that parallel tool calls are for queue workers only.

**Priority and effort.** P2, S.

## 6. Placement rule and what moves out of core

Core owns contracts, schemas and conformance tests; adapters live where their conventions live. An adapter belongs in core when it wraps a standalone library for which core already has precedent; it belongs in a package when it depends on a framework's conventions, lifecycle or configuration. The rule gives the following split.

| Where | What lives there |
|---|---|
| Core, `neuron-core/neuron-ai` | Contracts, the engine and the reference gateway (C14); canonical schemas (C13) and the contract test cases in `src/Testing`; adapters for standalone libraries with precedent in core: the Curl, Guzzle and Amp HTTP clients plus `SymfonyHttpClient` (C17), storage over PDO and Redis, push over Pusher, Redis and Mercure (C16), the PDO pgvector store (C21); the framework-neutral attributes (C18, C19) |
| Laravel package, `neuron-core/neuron-laravel` (namespace `NeuronAI\Laravel`, `config/neuron.php`, `illuminate/*` ^12.47 or ^13, since 12.47 is the first 12.x release with the vector schema and query methods the package uses, PHP ^8.2, Testbench 10 and 11) | Eloquent and query-builder persistence, message store and projection store with their migrations; `LaravelHttpClient`; `IlluminateEventBridge`; the `InvokeWorkflow` job and the sweep command; Responsables, form requests, policies and the `channels.php` stub; `Neuron::fake()`; artisan commands, generators, `AboutCommand` entries and Boost resources |
| Symfony bundle, `neuron-core/neuron-bundle` (namespace `NeuronAI\Symfony`, `NeuronBundle extends AbstractBundle`, configuration key `neuron`, Symfony 7.4 LTS on PHP 8.2 and 8.x on PHP 8.4) | DBAL persistence, message store and projection store with a `postGenerateSchema` listener; the Messenger message and handler; the Scheduler sweep task; the stream response and argument value resolvers; `ThreadVoter`; the profiler data collector, Stopwatch integration and `Traceable` decorators; `#[AsCommand]` commands and MakerBundle makers; `NeuronAssertionsTrait` and the Flex recipe; optional interop with the Symfony AI Platform (a Neuron provider over `PlatformInterface`) and symfony/mcp-bundle |

Two things move out of core as a consequence: `EloquentPersistence` and `EloquentMessageStore` go to the Laravel package (C13), and `illuminate/database` leaves core's development requirements with them. Nothing framework-specific moves in: `SymfonyHttpClient` and `MercureChannel` depend only on standalone libraries or on Neuron's own HTTP client. One placement is open: DBAL adapters could live in core instead of the bundle, which would serve Laminas and Mezzio applications that use Doctrine without Symfony. Those applications can already use `DatabasePersistence` with a closure over `getNativeConnection()` once C13 lands, so the question is about convenience, not capability (section 8).

## 7. Roadmap and dependency graph

The defects in section 4 come first and are independent of every decision below; D1, D2, D3, D4, D8, D12 and D13 in particular should be fixed on the branch before the packages publish anything. The proposals then fall into three waves that match their priorities.

1. **4.0 (P0): C1, C2, C3, C6.** These change the shape of the application-facing API (identity, configuration precedence, exception types) and the one behaviour every streaming endpoint depends on. The packages can ship experimental releases against today's core, but they should not go further without them.
2. **4.x, before the packages go stable (P1): C4, C5, C7 to C14, C17, C18, the P1 part of C21, C22.** These complete the durable execution contract (scheduling facts, interrupt fences, ignite, clock, side effects, deploy safety), the storage contract and the gateway, and make providers, tools and HTTP safe to share.
3. **Soon after (P2): C15, C16, C19, C20, the rest of C21, C23, C24, C25.** These improve the edge, push, discovery, structured output, RAG ergonomics and the developer experience; the packages have interim solutions for all of them.

Nothing in the catalog is P3. The optional items mentioned inside proposals, such as span pairing in C22, JSON-encoded engine records (C12's alternative), a PHP consumer of the channel envelope (C16's alternative) and a durable per-run signal buffer consumed by `awaitEvent()` before it suspends (C6), are the P3 backlog.

The gateway sits at the end of the critical path, because it is where the other contracts meet:

```
D1..D18 fixes ───────────────────────────────────────────► experimental package releases

C2 for() ───────────────────────────────┐
C3 defaults ──► C10 clock ──────────────┤
C6 failures ──┬► C8 interrupt fences ───┤
              └► C9 ignite, JSON ───────┤
C7 deadline(), snapshot ────────────────┼──► C14 gateway ──► stable package releases
C4 setup() ──► C13 connections, schema ─┤
C19 names (soft: config maps meanwhile) ┘
```

The full dependency table maps each proposal to what it needs and what it unblocks in the packages.

| Proposal | Priority, effort | Depends on | Unblocks in the packages |
|---|---|---|---|
| C1 Settle abandoned segments | P0, S | none | Streaming endpoints that cannot lock a thread; the `EventStreamResponse` variant |
| C2 Shareable definitions | P0, M | none | Definitions as singletons or shared services; handles from `for()` |
| C3 Defaults tier | P0, S | none | Defaults applied once; fakes that swap single entries |
| C4 Pure construction | P1, M | none | Singletons whose resolution does no I/O; `neuron:setup` |
| C5 Stateless providers | P1, L | D1 fixed | Shared providers per name; `Traceable` decorators; the Symfony AI Platform adapter |
| C6 Typed failures | P0, M | none | Job verbs and HTTP statuses by type |
| C7 Scheduling facts | P1, S | none (C10 for the clock) | Timers from `deadline()`; single-read wakes |
| C8 Interrupt fences | P1, S | C6 | Answer redelivery that converges |
| C9 Ignite and JSON | P1, M | C6 | 409 before 202; JSON-only messages; cancellable queued turns |
| C10 Clock | P1, S | C3 | `travel()` and `MockClock` in tests |
| C11 Durable side effects | P1, M | none (pairs with C18) | `ToolContext` in workers; leases no longer sized to a whole tool loop |
| C12 Deploy-safe records | P1, M | C6, C19 for the attribute form | Version routing; signed records; typed incompatibility |
| C13 SQL stores and schema | P1, M | C4 | Connection closures; migrations and schema listeners; contract tests |
| C14 Reference gateway | P1, M | C2, C6, C7, C8, C9, C10, C13 (C19 soft) | Thin `InvokeWorkflow` job and Messenger handler |
| C15 HTTP edge contracts | P2, S | C1, C2 | Generic streaming responses; protocol routes |
| C16 Push transports | P2, S | C17 | Mercure; Reverb guidance; Redis Cluster |
| C17 HTTP contract | P1, M | C6, C13 | Framework HTTP adapters; `retryAt` from `Retry-After` |
| C18 Shareable tools and MCP | P1, M | C11 (C19 soft: C18 introduces the `NeuronAI\Attributes` namespace with `AsTool`) | Shared tools; policy services; MCP lifecycle and exposure |
| C19 Definition metadata | P2, S | none | Discovery manifest and autoconfiguration |
| C20 Structured output seam | P2, S | C3 | Framework validators and serializers |
| C21 RAG for containers | P1-P2, M | C3, C4, C13 | Indexer services; idempotent ingestion; pgvector |
| C22 Observability | P1, S | none | Queued listeners; profiler collector; correct cost metrics |
| C23 Console and evaluation | P2, S | C3, C4, C19 | `neuron:evaluate`; shared stubs |
| C24 Agent ergonomics | P2, S | C6 (C7, C10 and C14 for approval deadlines) | String input; approval deadlines; structured endpoints |
| C25 Hygiene | P2, S | none | A clean API surface for the new major |

Each wave deletes interim code in the packages rather than changing their application-facing API. The one exception is identity: applications that bound definitions with `setThreadId()` after resolution switch to `for()` once, in 4.0.

## 8. Decisions needed from the maintainer

Eight decisions shape the rest; the documents recommend one side of each and describe the consequence of the other.

| Decision | Recommendation | If the other way |
|---|---|---|
| `for()` handles or prototypes (C2) | `for()`: definitions become shareable, and misuse fails fast | Prototypes plus a `WorkflowFactory` contract; the packages keep prototype registration and locators permanently, and every long-lived application service needs a locator |
| PSR-11 defaults tier (C3) | A closed list of interface keys in a PSR-11 container | A typed `WorkflowRuntime` for workflow-level services only; the Agent and RAG keys stay on reflection-checked setters |
| Gateway in core or separate (C14) | Core leaf module `NeuronAI\Gateway` | `neuron-core/gateway`, pinned to the exact core minor version; never duplicated in the two packages |
| The gateway's `WorkflowEngine` argument (C14) | Remove it before C14 ships: every verb runs on the bound handle, so the argument has no remaining purpose, and its presence invites a verb that bypasses a definition's own `persistence()` or `serializer()` hook | The argument stays, documented as never used to inspect, continue, acknowledge or abandon a run, and every host keeps building an engine the gateway ignores |
| `ignite()` and the `pending` status (C9) | Add both | Starts stay inside workers with reserved run IDs; admission errors cannot reach the HTTP edge before a 202 |
| DBAL placement (C13) | In the Symfony bundle | In core as an optional adapter, serving Laminas and Mezzio users at the price of an optional dependency |
| New requirements `psr/container` and `psr/clock` (C3, C10) | Accept: both are interface-only packages | Core defines its own locator and clock interfaces, and every host writes an adapter to them |
| Definition version refusal policy (C12) | Opt-in versions; a mismatch refuses the continuation before replay, and routing stays platform policy | Warn and continue, which risks replaying steps against a changed graph, or refuse all unversioned runs after a deploy, which breaks applications that never declared versions |

One design question is open rather than decided: composition under shared definitions and durability (C2). An Agent or a RAG used as a node gets its address from the parent's resources factory and its result memoized by the parent, which covers recovery after the parent's memo commits. Two limits remain. A crash between a synchronous child's completion, which deletes its partition, and the parent's memo commit runs the child again and repeats its inference and tool calls; retaining the child's completion under a reserved run ID derived from the parent's run and step, acknowledged only after the parent's memo commits, would close that window. And a child that suspends cannot suspend its parent, so interrupts raised inside a nested agent, such as a tool approval, have no propagation path yet. Both need a design before the documents recommend nested durable agents beyond the memoized pattern.

Some details remain to verify during implementation. C8 must be checked against the engine: the interplay of interrupt fences with attempt bumps on recovery, with idle polls on failed runs, with the takeover of a running run whose lease has expired and with deferred interrupts in `pendingSteps`. For C1, a local reproduction on the PHP 8.4 CLI confirmed that generator `finally` blocks run after a client abort only with `ignore_user_abort(true)`; how each runtime reports the abort and whether it keeps the script running (FPM, FrankenPHP worker mode, Octane on Swoole or RoadRunner) should still be tested, since the lease remains the only guarantee where it does not. And whether `AsyncBranchRunner` coexists with Swoole's coroutine runtime and behaves in long-running workers (RoadRunner, FrankenPHP worker mode) decides whether the packages allow it outside plain CLI workers.

