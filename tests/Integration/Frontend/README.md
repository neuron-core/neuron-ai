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

## Tested versions

| Package | Version |
|---|---|
| @ag-ui/client | 0.0.59 |
| ai / @ai-sdk/react | 7.0.98 / 4.0.101 |
| @copilotkit/react-core / runtime | 1.71.0 |
| react | 19.3.0 |
| @playwright/test | 1.63.0 (Chromium headless shell 1243) |
| node | 22 |

## Status

Phase 1 of the design: one deferred-tool round trip per integration.

- AG-UI: passing through the official `HttpAgent`.
- Vercel and CopilotKit: fixture and spec written; the bridge-to-PHP leg is verified over HTTP. The browser journeys still need a Chromium run.

Every test uses one shared database per Playwright run with a unique thread per test;
a database per test is deferred until a scenario needs it.
