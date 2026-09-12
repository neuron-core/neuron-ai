# Frontend protocol integration suite

Executable evidence that Neuron's frontend-tool flow works with real clients:
the official AG-UI client, Vercel AI SDK `useChat`, and CopilotKit's hooks through
its runtime bridge. The design is in `frontend-protocol-integration-testing.md`
at the repository root.

## Layout

| Path | Role |
|---|---|
| `backend/router.php` | Example application endpoint for the built-in PHP server: `/agui`, `/vercel`, and test-only `/_test/*` routes |
| `Stub/Fixture.php` | SQLite-backed application state: workflow persistence, chat history, thread→scenario binding, audit reads |
| `Stub/ScenarioProvider.php` | Deterministic provider choosing replies from the inference input; every invocation is persisted |
| `fixtures/vercel/` | React app on `useChat` with a browser-side `read_title` handler |
| `fixtures/copilotkit/` | React app on `useFrontendTool` + `CopilotChat`, and `server.mjs`, the CopilotKit runtime bridge that drives `/agui` through `HttpAgent` |
| `specs/<client>/` | Playwright specs, one project per client |
| `support/backend.ts` | Thread registration and audit observation helpers |

Each Playwright run gets its own SQLite file; each test registers a unique thread
bound to a scenario through `POST /_test/threads`. Protocol requests carry only
protocol fields: the thread id is AG-UI's `threadId` or the Vercel chat `id`, and the
page title under test arrives through the fixture page URL.

## Running

Requires PHP with `pdo_sqlite` and Node 22.

```bash
cd tests/Integration/Frontend
npm ci
npx playwright install --with-deps chromium
npx playwright test                 # all projects
npx playwright test --project=agui  # SDK contract tests, no browser needed
```

Playwright starts the PHP server (8787), the CopilotKit runtime bridge (4000), and
the Vite dev server (5173) itself; Vite proxies `/api/*` to PHP and `/copilotkit` to
the bridge so the browser fixtures stay same-origin.

## Version compatibility

The suite is the compatibility statement for Neuron's frontend integrations. Each
client stack is fixed to its current major line in `package.json`; patch and minor
releases within that line are accepted, a new major requires a deliberate review
and a new run of this suite. The lockfile records the exact versions the suite last
passed with. There is no automatic upgrade process.

| Stack | Supported line | Last verified |
|---|---|---|
| AG-UI (`@ag-ui/client`, `@ag-ui/core`) | 0.0.x | 0.0.59 |
| Vercel AI SDK (`ai`, `@ai-sdk/react`) | 7.x / 4.x | 7.0.98 / 4.0.101 |
| CopilotKit (`@copilotkit/react-core`, `@copilotkit/runtime`, v2 API) | 1.x | 1.71.0 |
| React | 19.x | 19.3.0 |
| Playwright | 1.x | 1.63.0, Chromium headless shell 1243 |
| Node | 22 LTS | 22.18 |
| PHP | 8.1+ (`pdo_sqlite`) | 8.4 |

A passing run supports the combination in the lockfile. It says nothing about
earlier releases of these clients, other browsers, or application endpoints that
differ from the fixture's.

## Scenarios

Each thread is bound to one scenario of the provider's plan (`Stub/ScenarioProvider.php`).
The final answer echoes every result the model received, so the UI text and the
audit can both be checked. All scenarios run through all three clients.

| Scenario | Proves |
|---|---|
| `deferred-title` | One frontend tool round trip |
| `approval-title` | Approval gate: dispatch only after approval, handler runs once; rejection never runs the handler |
| `mixed` | Backend tool executes once on the server; the browser executes only its own tool |
| `same-name` | Two calls of one tool stay distinct by call id |
| `structured` | Objects, arrays, `false`, `0`, `null` across the boundary |
| `handler-error` | A throwing browser handler |
| `backend-error` | A failing backend tool on the wire (AG-UI `error` representation) |
| `two-steps` | Automatic continuation over two inference steps without a submission loop |
| `broken` | An unregistered scenario: the provider fails after streaming started |

Beyond scenarios, the specs cover partial and out-of-order results, repeated,
conflicting and stale submissions, a backend restart on the same database, browser
reload, dynamic tool registration and client-generated schemas, fragmented
multi-byte delivery, and errors before and after the response headers.

## Verified behaviour and client limitations

- **AG-UI error field.** Neuron adds `error` to `TOOL_CALL_RESULT` for failed results.
  The AG-UI core schema for that event is passthrough, so the field reaches
  subscribers on the frame, but the official client's reducer builds message state
  from `content` alone: `agent.messages` carries no `error`. A rejection is stamped as
  a plain instruction string and carries no `error` on purpose.
- **Structured results.** Vercel passes `false`, `0`, `null` and structured values, which
  Neuron normalizes to their JSON text. AG-UI tool messages are text by protocol, so
  the client sends JSON text. CopilotKit serializes handler values itself: objects and
  arrays as JSON, `false`/`0` as text, `null`/`undefined` as an empty string.
- **Throwing handlers.** Vercel reports `output-error` and Neuron receives an error
  result. AG-UI tool messages carry `error`. CopilotKit has no error channel: a
  throwing handler becomes a tool message whose content starts with `Error:` and, by
  CopilotKit's own follow-up rule, no continuation run is started, leaving the Neuron
  run suspended until the application acts.
- **Backend tool calls on AG-UI.** Calls executed on the server are published to the
  client together with their result; a frontend executes only calls it owns that have
  no result yet.
- **Partial results.** The engine accumulates results across submissions: the same
  result again is accepted, a different outcome for a settled call is refused (HTTP 409
  from the endpoint), and a submission after the run completed or from a previous
  turn is refused (HTTP 400) instead of starting a new turn. A Vercel client only
  submits partially if `onToolCall` starts the work without awaiting it, since
  `useChat` awaits the callback, and its predicate must remember what it already sent:
  a partial answer leaves the client state unchanged, so "any output available"
  resubmits the same batch after every response. CopilotKit always follows up once
  with all results.
- **Submission predicates must be step-scoped.** After the final answer the earlier
  tool parts still exist on the assistant message; a `sendAutomaticallyWhen` that does
  not restrict itself to the last step submits them again and hits a stale-continuation
  error. Both fixture predicates slice after the last `step-start`.
- **Reload.** Vercel restores from the fixture's session storage copy of `messages`;
  CopilotKit restores the thread, including the open interrupt, from its runtime
  bridge's in-memory thread store. Neither replays SSE frames; a browser side effect
  in flight during a reload is the application's responsibility.
- **Backend restart.** A second PHP process on the same SQLite file continues the
  persisted wait; the backend tool already executed is not rerun.
- **Dynamic registration.** Tools declared through `useFrontendTool` follow component
  lifecycle: an unmounted tool is absent from `RunAgentInput.tools`, and a model call to
  it fails the run (`RUN_ERROR`, run status `failed`). Nested objects, arrays,
  optional and nullable properties generated from zod are accepted by the converter.
- **Errors.** Before headers: an HTTP error the clients surface as a failed request.
  After headers: `RUN_ERROR` (AG-UI, the official client resolves the run and reports
  it through the subscriber) or an `error` part (Vercel); the run is recorded as failed
  and nothing is left waiting.
- **Long multi-byte results.** Survive fragmented SSE on all three clients; the only
  change observed is `document.title` trimming surrounding whitespace.

## Status

Phases 1 to 3 of the design are implemented and passing through all three clients
(`composer test:frontend` from the repository root).

Every test uses one shared database per Playwright run with a unique thread per test;
a database per test is deferred until a scenario needs it.
