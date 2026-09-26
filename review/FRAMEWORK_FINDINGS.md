# Framework findings from the unit test review

This document collects the framework problems found while reviewing and strengthening the unit tests on branch `claude/unit-tests-review-c3dpwx`. The framework code was deliberately left untouched, so every entry is a proposal to discuss. Each finding was reported by a review agent while writing tests, then handed to a second, skeptical agent that reproduced it against the current code in an isolated copy of `src/`, judged whether the behaviour could be intended, and in most cases validated the suggested fix there against the repro and the module's existing tests.

The same review grew the test suite from 3,051 to 7,175 tests and line coverage of `src/` from 71.7% to 97.2%, and mutation-tested every module, Tests asserting the correct behaviour for the defects below could not join the suite while the framework stays unchanged, so each one is kept as a repro test instead.

Every entry links to the source line and, where one exists, to a repro test in [`review/repro/`](repro/). The repro tests sit outside the PHPUnit suite and assert the correct behaviour, so they fail on the current code; run one with `vendor/bin/phpunit review/repro/<Module>/<Test>.php`. Once a fix lands, the matching repro can move into `tests/` as its regression test. Severity is the verifier's assessment, which is sometimes lower than the reporter's.

There are **319 confirmed findings**: 1 critical, 19 high, 143 medium and 156 low. Another 16 suspicions were investigated and rejected; they are listed at the end so nobody re-investigates them.

## Summary

The review confirmed 319 problems: 1 critical, 19 high, 143 medium and 156 low. Most are narrow, and nearly every one comes with a small fix that was already checked in a sandbox against its repro. The foundation holds up well. Workflow has 27 findings and none is rated above medium. They are edge cases in persistence backends, pairing of observability events and interrupt envelopes, and the executor still checks every resume against the persisted interrupt ([WORKFLOW-16](#workflow-16)). The one serious Workflow item is the unstated trust assumption behind `PhpSerializer` ([WORKFLOW-05](#workflow-05)). Agent looks much the same: its findings concern adapter input validation and duplicate call IDs, with [AGENT-01](#agent-01) (hidden toolkit tools are still offered to the model and executed) as the exception that matters. Several findings describe the same defect from two sides ([TOOLS-09](#tools-01) is merged into [TOOLS-01](#tools-01), and [EVALUATION-20](#evaluation-20)/27, [EVALUATION-10](#evaluation-10)/26, [EVALUATION-21](#evaluation-21)/25, [PROVIDERS-14](#providers-14)/36 and [RAG-29](#rag-29)/32 overlap), so the number of distinct fixes is smaller than the count suggests. Sixteen further suspicions were examined and rejected.

The real risk sits in four places. First, the SQL toolkits' "read-only" select tools can be made to write. [TOOLS-01](#tools-01) (critical) and [TOOLS-04](#tools-04) to [TOOLS-08](#tools-08) show bypasses through comment markers, stacked statements, executable comments, `SELECT ... INTO`, missing whitespace and side-effecting functions, and several of them deleted or updated rows on a real PostgreSQL server. Second, several provider integrations are broken outright rather than degraded. HuggingFace and AzureOpenAI cannot be constructed ([PROVIDERS-03](#providers-03), [PROVIDERS-06](#providers-06)), ElevenLabs speech-to-text never succeeds ([PROVIDERS-02](#providers-02)), Cohere and Mistral tool calling crashes or drops calls ([PROVIDERS-01](#providers-01), [PROVIDERS-04](#providers-04)), and Ollama loses tool calls from history ([PROVIDERS-05](#providers-05)). On top of that, a substring check silently drops any streamed event containing "DONE" in most SSE-based providers ([PROVIDERS-07](#providers-07)). Third, RAG can lose indexed data during ingestion and storage, often without any error ([RAG-02](#rag-02), [RAG-03](#rag-03), [RAG-23](#rag-23), [RAG-24](#rag-24), [RAG-53](#rag-53), [RAG-31](#rag-31), [RAG-52](#rag-52)). Fourth, outbound requests are not safe by default. Token counting fetches user-supplied image URLs ([CHAT-01](#chat-01)). The default curl client serves `file://` ([HTTPCLIENT-05](#httpclient-05)), sends API-key headers on to other hosts after a redirect ([HTTPCLIENT-06](#httpclient-06)) and allows header injection ([HTTPCLIENT-08](#httpclient-08)). The MCP SSE transport has the same kinds of gaps ([MCP-12](#mcp-12), [MCP-13](#mcp-13)).

### Where to start

1. **Make the SQL select tools genuinely read-only:** [TOOLS-01](#tools-01), [TOOLS-04](#tools-04), [TOOLS-05](#tools-05), [TOOLS-06](#tools-06), [TOOLS-07](#tools-07), [TOOLS-08](#tools-08), plus [TOOLS-26](#tools-26). The regex fixes close the verified bypasses. Only the read-only transaction from [TOOLS-08](#tools-08) avoids depending on SQL parsing, so do both. This comes first because prompt injection can destroy data through a tool advertised as safe.
2. **Close the injection and exposure paths:** [RAG-01](#rag-01) (Cypher injection), [CHAT-01](#chat-01) (its fix also removes [CHAT-08](#chat-08); add the [CHAT-26](#chat-26) guard alongside), [DOCS-02](#docs-02) (documented memory leaks conversations across threads), [AGENT-01](#agent-01) with [DOCS-01](#docs-01), [STRUCTUREDOUTPUT-02](#structuredoutput-02) (model output writes non-public and static properties) and [TOOLS-10](#tools-10) (approval policies bypassed on nested fields).
3. **Harden the HTTP layer once:** [HTTPCLIENT-05](#httpclient-05), [HTTPCLIENT-06](#httpclient-06), [HTTPCLIENT-07](#httpclient-07), [HTTPCLIENT-08](#httpclient-08), [MCP-12](#mcp-12), [MCP-13](#mcp-13) and [MCP-14](#mcp-14), together with [HTTPCLIENT-04](#httpclient-04), which hides 4xx/5xx errors on the default streaming path. Most of the work lands in `CurlHttpClient` and `SseHttpTransport`.
4. **Stop silent data loss:** [RAG-02](#rag-02), [RAG-03](#rag-03), one `FileVectorStore` pass covering [RAG-22](#rag-22), [RAG-23](#rag-23), [RAG-24](#rag-24), [RAG-48](#rag-48) and [RAG-53](#rag-53), the Typesense pair [RAG-31](#rag-31) and [RAG-52](#rag-52), [CHAT-06](#chat-06) and [CHAT-09](#chat-09). [AGENT-02](#agent-02) needs a decision on an atomic history `replace()` API.
5. **Repair the broken providers:** [PROVIDERS-03](#providers-03), [PROVIDERS-06](#providers-06) with [PROVIDERS-25](#providers-25), [PROVIDERS-02](#providers-02), [PROVIDERS-01](#providers-01), [PROVIDERS-04](#providers-04) and [PROVIDERS-05](#providers-05). Then fix the SSE parser ([PROVIDERS-07](#providers-07), [PROVIDERS-31](#providers-31), [PROVIDERS-44](#providers-44)) and [PROVIDERS-12](#providers-12), which currently lets a stream that failed partway through be stored as a complete answer. [PROVIDERS-28](#providers-28), a cross-request reasoning leak, fits in the same pass.
6. **Let the model correct its own mistakes:** [TOOLS-02](#tools-02), [TOOLS-12](#tools-12), [TOOLS-13](#tools-13), [STRUCTUREDOUTPUT-01](#structuredoutput-01), [STRUCTUREDOUTPUT-03](#structuredoutput-03), [STRUCTUREDOUTPUT-04](#structuredoutput-04) and [STRUCTUREDOUTPUT-05](#structuredoutput-05), plus [TOOLS-03](#tools-03) (BashTool hangs forever on large stderr). Today these either abort whole runs on routine model output or accept wrong data without a retry.

### Recurring patterns

**Untrusted input escaping as engine errors.** A malformed value from the model or a client often ends up as a PHP `TypeError`, `ValueError` or `Error`. The run then aborts instead of producing an `InvalidToolInput`, a `DeserializerException` or an input-translation error that could be corrected: [TOOLS-02](#tools-02), [TOOLS-12](#tools-12), [TOOLS-13](#tools-13), [TOOLS-14](#tools-14), [TOOLS-17](#tools-17), [TOOLS-25](#tools-25), [TOOLS-39](#tools-39), [TOOLS-45](#tools-45), [STRUCTUREDOUTPUT-01](#structuredoutput-01), [STRUCTUREDOUTPUT-06](#structuredoutput-06), [STRUCTUREDOUTPUT-14](#structuredoutput-14), [EVALUATION-04](#evaluation-04), [EVALUATION-06](#evaluation-06), [AGENT-05](#agent-05), [AGENT-08](#agent-08), [AGENT-12](#agent-12). `catch (Exception)` also misses `Error` ([AGENT-14](#agent-14), [TOOLS-45](#tools-45)). The remedy is to check the shape once at each boundary (`Tool::setInputs()`, the `Deserializer`, the input translators) and raise the domain exception there.

**Remote response shapes are never validated.** JSON-RPC `error` members, vendor error events and unexpected bodies are indexed as if they were successes: [MCP-01](#mcp-01), [MCP-02](#mcp-02), [MCP-09](#mcp-09), [MCP-10](#mcp-10), [HTTPCLIENT-11](#httpclient-11), [PROVIDERS-12](#providers-12), [PROVIDERS-16](#providers-16), [PROVIDERS-17](#providers-17), [PROVIDERS-22](#providers-22), [PROVIDERS-33](#providers-33), [PROVIDERS-43](#providers-43), [RAG-31](#rag-31), [RAG-36](#rag-36), [RAG-42](#rag-42), [TOOLS-31](#tools-31). Checking for errors explicitly and accepting only an array or object at decode time removes most of these.

**`json_encode()` failures and invalid UTF-8.** An unchecked `false` becomes an empty body, a blank line or a lost record: [CHAT-06](#chat-06), [HTTPCLIENT-07](#httpclient-07), [RAG-24](#rag-24), [RAG-52](#rag-52), [TOOLS-11](#tools-11), [STRUCTUREDOUTPUT-21](#structuredoutput-21). Elsewhere it becomes a crash: [CHAT-21](#chat-21), [EVALUATION-21](#evaluation-21), [EVALUATION-25](#evaluation-25), [TOOLS-49](#tools-49). The rule should be `JSON_THROW_ON_ERROR` for storage and wire formats, and `JSON_INVALID_UTF8_SUBSTITUTE` only where lossy output is acceptable. Multibyte text is mishandled in several places ([STRUCTUREDOUTPUT-08](#structuredoutput-08), [TOOLS-23](#tools-23), [PROVIDERS-44](#providers-44), [EVALUATION-12](#evaluation-12), [RAG-46](#rag-46), [TOOLS-15](#tools-15)).

**Guards that do not match their claim.** Several regexes use `$` without the `D` modifier ([CHAT-18](#chat-18), [RAG-45](#rag-45), [WORKFLOW-27](#workflow-27)), and text-based checks are unsound or overbroad (the [TOOLS-01](#tools-01) family, [TOOLS-54](#tools-54), [EVALUATION-05](#evaluation-05)). Use `\z` or `D` everywhere, and prefer structural enforcement such as transactions or tokenizers over pattern matching.

**Identifiers, paths and URLs interpolated raw.** Values reach Cypher, URL paths, query strings, headers and file names without encoding: [RAG-01](#rag-01), [PROVIDERS-15](#providers-15), [TOOLS-30](#tools-30), [TOOLS-56](#tools-56), [HTTPCLIENT-08](#httpclient-08), [RAG-48](#rag-48), [EVALUATION-16](#evaluation-16), [CONSOLE-02](#console-02), plus file names that collide or overflow ([WORKFLOW-04](#workflow-04), [CHAT-13](#chat-13)). Non-HTTP schemes such as `file://` are not refused ([HTTPCLIENT-05](#httpclient-05), [MCP-13](#mcp-13), [STRUCTUREDOUTPUT-28](#structuredoutput-28), [TOOLS-25](#tools-25)). Filesystem walks follow symlinks without containment or cycle checks ([RAG-05](#rag-05), [RAG-08](#rag-08), [TOOLS-20](#tools-20), [TOOLS-22](#tools-22), [RAG-53](#rag-53)). The remedy is to encode or whitelist at the point of interpolation, keep one http/https allow-list, and resolve paths with `realpath()` plus a visited set.

**PHP value semantics.** `"0"` is treated as false ([PROVIDERS-13](#providers-13), [PROVIDERS-22](#providers-22), [CHAT-09](#chat-09)). `isset()` and `??` treat a stored null as missing ([WORKFLOW-01](#workflow-01), [WORKFLOW-26](#workflow-26)). Arrays that are not lists and numeric-string keys cause further bugs ([PROVIDERS-20](#providers-20), [MCP-05](#mcp-05), [RAG-15](#rag-15), [RAG-41](#rag-41), [RAG-44](#rag-44), [EVALUATION-15](#evaluation-15), [WORKFLOW-21](#workflow-21)). Comparing explicitly, using `array_values()` and casting keys back to string fix all of these.

**Hidden side effects and divergent paths.** Objects owned by the caller are modified in place ([PROVIDERS-19](#providers-19), [PROVIDERS-27](#providers-27), [CHAT-02](#chat-02), [WORKFLOW-25](#workflow-25)). `chat()` and `stream()` disagree on reasoning, stop reasons and content ([PROVIDERS-29](#providers-29), [PROVIDERS-32](#providers-32), [PROVIDERS-37](#providers-37), [PROVIDERS-38](#providers-38), [PROVIDERS-42](#providers-42), [PROVIDERS-45](#providers-45)). Configuration is silently ignored ([RAG-09](#rag-09), [RAG-13](#rag-13), [HTTPCLIENT-09](#httpclient-09), [RAG-21](#rag-21), [RAG-27](#rag-27), [RAG-30](#rag-30), [TOOLS-32](#tools-32)). Anonymous class names leak a NUL byte and the absolute source path ([AGENT-09](#agent-09), [WORKFLOW-11](#workflow-11), [PROVIDERS-46](#providers-46)). Retrieved or judged text can close its prompt delimiters ([RAG-14](#rag-14), [EVALUATION-10](#evaluation-10), [EVALUATION-26](#evaluation-26)). Each of these can be fixed once with a shared helper and a parity test.

## Priorities at a glance

All critical and high severity findings.

| ID | Severity | Module | Finding |
|---|---|---|---|
| [TOOLS-01](#tools-01) | critical | Tools | Naive comment stripping lets write statements pass the read-only SQL check |
| [CHAT-01](#chat-01) | high | Chat | TokenCounter opens user-supplied image URLs and paths while counting tokens |
| [PROVIDERS-01](#providers-01) | high | Providers | Cohere crashes on v2 tool-call responses and streaming keeps only the first tool call |
| [PROVIDERS-02](#providers-02) | high | Providers | ElevenLabsSpeechToText sends requests to a non-existent endpoint with invalid field and headers |
| [PROVIDERS-03](#providers-03) | high | Providers | HuggingFace provider throws in its constructor because `$baseUri` has no template |
| [PROVIDERS-04](#providers-04) | high | Providers | Mistral loses chunked reasoning answers, crashes on null tool-call content, drops streamed tool calls |
| [PROVIDERS-05](#providers-05) | high | Providers | Ollama message mapper drops assistant tool calls and replays reasoning as visible text |
| [PROVIDERS-06](#providers-06) | high | Providers | AzureOpenAI throws in its constructor and builds its chat URL in the wrong order |
| [PROVIDERS-07](#providers-07) | high | Providers | SSE parsers drop any event whose JSON payload contains the substring `DONE` |
| [TOOLS-02](#tools-02) | high | Tools | Non-object input for a class-mapped ObjectProperty escapes setInputs() and aborts the run |
| [TOOLS-03](#tools-03) | high | Tools | BashTool deadlocks when a command writes more than a pipe buffer to stderr |
| [TOOLS-04](#tools-04) | high | Tools | MySQL read-only tool accepts stacked statements not on its forbidden list |
| [TOOLS-05](#tools-05) | high | Tools | MySQL executable comments are stripped before the forbidden-keyword scan but executed |
| [TOOLS-06](#tools-06) | high | Tools | PGSQL read-only tool allows SELECT ... INTO, which creates and populates tables |
| [TOOLS-07](#tools-07) | high | Tools | PGSQL write-keyword patterns require trailing whitespace, so UPDATE"users"SET passes |
| [TOOLS-08](#tools-08) | high | Tools | Text-based read-only checks let side-effecting SQL functions run through select tools |
| [TOOLS-09](#tools-01) | high | Tools | Naive comment stripping lets write statements pass the read-only SQL check |
| [RAG-01](#rag-01) | high | RAG | Cypher injection through the relation string in Neo4jGraphStore upsert() and delete() |
| [RAG-02](#rag-02) | high | RAG | reindexBySource deletes a source before validating or embedding its replacement |
| [RAG-03](#rag-03) | high | RAG | Elasticsearch addDocuments re-sends all earlier chunks in each bulk request |

## Findings by module

| Module | Critical | High | Medium | Low |
|---|---|---|---|---|
| [Agent](#module-agent) | 0 | 0 | 4 | 14 |
| [Workflow](#module-workflow) | 0 | 0 | 5 | 22 |
| [Chat](#module-chat) | 0 | 1 | 12 | 13 |
| [Providers](#module-providers) | 0 | 7 | 26 | 13 |
| [Tools](#module-tools) | 1 | 8 | 24 | 26 |
| [RAG](#module-rag) | 0 | 3 | 31 | 19 |
| [Structured output](#module-structuredoutput) | 0 | 0 | 16 | 14 |
| [HTTP client](#module-httpclient) | 0 | 0 | 9 | 2 |
| [MCP](#module-mcp) | 0 | 0 | 6 | 9 |
| [Evaluation](#module-evaluation) | 0 | 0 | 8 | 19 |
| [Console](#module-console) | 0 | 0 | 1 | 2 |
| [Classifier](#module-classifier) | 0 | 0 | 0 | 1 |
| [Exceptions](#module-exceptions) | 0 | 0 | 0 | 1 |
| [Documentation](#module-docs) | 0 | 0 | 1 | 1 |

## <a id="module-agent"></a>Agent

Agent has 18 findings, and none is rated above medium. The most important is [AGENT-01](#agent-01): tools hidden inside a toolkit are still sent to the model and executed, so hiding a dangerous capability does not protect it. [AGENT-02](#agent-02) (summarization rewrites durable history non-atomically) and the ParallelToolNode issues [AGENT-03](#agent-03) and [AGENT-04](#agent-04), which bypass the configured tool error handler, come next. The rest are adapter input checks on client payloads ([AGENT-05](#agent-05), [AGENT-08](#agent-08), [AGENT-12](#agent-12)) and corner cases with duplicate or missing call IDs ([AGENT-15](#agent-15) to [AGENT-17](#agent-17)).

### <a id="agent-01"></a>AGENT-01 · Tools hidden inside a toolkit are still offered to the model and executable

**medium** · security · [`src/Agent/Agent.php:193`](../src/Agent/Agent.php#L193) · repro [`ToolkitHiddenToolTest`](repro/Agent/ToolkitHiddenToolTest.php) · fix validated

`resolveTools()` in `Agent` filters standalone tools with `isVisible()`, but merges a toolkit's inner tools unfiltered. A tool hidden with `$toolkit->with(X::class, fn ($t) => $t->visible(false))` is therefore still sent to the provider, named in the `TOOLS-GUIDELINES` system block, placed in the `ToolRegistry`, and runs when the model calls it. A hidden standalone tool, by contrast, is withheld and raises `ToolException` if called. Developers who hide a dangerous toolkit capability (file writes, shell, DB mutations) to keep it from the model are not protected, and a prompt-injected or misbehaving model can invoke it.

Suggested fix: in `src/Agent/Agent.php::resolveTools()`, filter `$tool->tools()` with `array_filter(..., fn (ToolInterface $t) => $t->isVisible())` (wrapped in `array_values`) before merging the inner tools and before building the guidelines list. This was validated in a sandbox against the repro and the module's tests.

### <a id="agent-02"></a>AGENT-02 · Summarization rewrites history non-atomically and re-summarizes on stale cumulative token usage

**medium** · design · [`src/Agent/Middleware/Summarization.php:92`](../src/Agent/Middleware/Summarization.php#L92) · repro [`SummarizationDurabilityTest`](repro/Agent/SummarizationDurabilityTest.php)

`Summarization::summarizeHistory()` rewrites the thread by calling `flushAll()` and then `addMessage()` once per retained message, from middleware and without a memo. With a durable store (SQL, Eloquent, File), a store error or worker kill between the clear and the re-adds leaves an empty or partial thread, losing the conversation. Separately, retained assistant messages keep the provider's cumulative `inputTokens`, so after the rewrite `calculateTotalUsage()` still reads above `maxTokens`. The next inference of a tool loop, or a crash-replay of the same step, summarizes again: one extra paid provider call, and the fresh summary is replaced by a summary of the summary. The history trimmer can also archive the retained messages on the inflated total.

Suggested fix: for the repeated summarization, add a protected `discountSummarizedTokens()` in `Summarization` that subtracts the cutoff message's `inputTokens` from each retained message's usage before the re-add, mirroring `HistoryTrimmer::normalizeCheckpoints()`. Atomicity needs a new API, such as an atomic `replace()` on `MessageStoreInterface` (a transaction for SQL/Eloquent, temp file plus rename for `FileMessageStore`), exposed through `ChatHistory` and used instead of `flushAll()`. That API change needs your approval.

### <a id="agent-03"></a>AGENT-03 · ParallelToolNode lets an exceeded tool run limit bypass the configured error handler

**medium** · bug · [`src/Agent/Nodes/ParallelToolNode.php:114`](../src/Agent/Nodes/ParallelToolNode.php#L114) · repro [`ParallelToolLimitErrorHandlerTest`](repro/Agent/ParallelToolLimitErrorHandlerTest.php) · fix validated

In sequential mode, `ToolNode::executeSingleTool()` calls `checkToolRuns()` inside its try/catch, so a `ToolRunsExceededException` goes to the `toolErrorHandler` and becomes a tool result. `ParallelToolNode` calls `checkToolRuns()` in a bare loop before forking. With `parallelToolCalls(true)`, when the model requests more calls to one tool than `toolMaxRuns` allows, the whole run aborts even though a handler is configured, and the results of the calls within the limit are discarded. A `ToolException` raised during run accounting, such as for an unknown tool name, skips the handler in the same way. The same agent configuration behaves differently depending on execution mode.

Suggested fix: in `src/Agent/Nodes/ParallelToolNode.php`, move the accounting loop ahead of the fork, wrap each `checkToolRuns()` in try/catch that calls `handleError()`, record the settled call in `$executedCalls` at its original index and remove it from `$runnable`. Without a handler, `handleError()` rethrows, so fail-fast behaviour is unchanged. This was validated in a sandbox against the repro and the module's tests.

### <a id="agent-04"></a>AGENT-04 · ParallelToolNode crashes rebuilding child exceptions whose constructors are not (message, code)

**medium** · bug · [`src/Agent/Nodes/ParallelToolNode.php:169`](../src/Agent/Nodes/ParallelToolNode.php#L169) · repro [`ParallelCustomExceptionTest`](repro/Agent/ParallelCustomExceptionTest.php) · fix validated

A tool failure in a forked child is serialized as class, message and code, then rebuilt in the parent with `new $exceptionClass($message, (int) $code)`. Any exception with a different constructor signature, such as Guzzle `RequestException` or domain exceptions with required context arguments, throws a `TypeError` or `ArgumentCountError` in the parent before `handleError()` runs. The configured `toolErrorHandler` is skipped and the run aborts with an error pointing at framework internals, hiding the real tool failure. A constructor with compatible types but different meanings silently builds an exception with the wrong state. The same tool in sequential mode reaches the handler normally.

Suggested fix: in `src/Agent/Nodes/ParallelToolNode.php`, add a protected `rebuildChildException()` that creates the original class with `ReflectionClass::newInstanceWithoutConstructor()` and sets `message` and `code` via reflection on the `Exception` or `Error` base, falling back to `ToolException` for unknown classes. Properties set by the original constructor remain lost across the process boundary. This was validated in a sandbox against the repro and the module's tests.

### <a id="agent-05"></a>AGENT-05 · AGUIAdapter constructor crashes or drops messages on malformed client-supplied seed messages

**low** · input-validation · [`src/Agent/Adapters/AGUIAdapter.php:113`](../src/Agent/Adapters/AGUIAdapter.php#L113) · repro [`AGUIAdapterSeedReproTest`](repro/Agent/AGUIAdapterSeedReproTest.php) · fix validated

The README tells applications to pass `$payload['messages']` from the client straight into `new AGUIAdapter(...)`, but the constructor indexes `$message['id']`, iterates `$message['toolCalls']`, and reads `$call['id']` and `$message['toolCallId']` without checks. A missing key produces `Undefined array key` warnings (an `ErrorException` under strict handlers), an array `id` a `TypeError`, a string `toolCalls` a `foreach` warning. With default warning settings, every ID-less message is stored under the same key, so the returned `MESSAGES_SNAPSHOT` silently drops messages. This happens in the stream adapter factory, outside the Workflow's error handling, so the client gets a 500. Only the sender's own response is affected.

Suggested fix: in `src/Agent/Adapters/AGUIAdapter.php`, validate each seeded identifier in the constructor through a protected `seedId()` helper that requires a non-empty string, check that `toolCalls` is an array, and throw `StreamAdapterException` otherwise, matching how `AGUIInputTranslator` rejects the same payload. This was validated in a sandbox against the repro and the module's tests.

### <a id="agent-06"></a>AGENT-06 · AGUIAdapter::interrupt() emits RUN_FINISHED with a null runId

**low** · api-inconsistency · [`src/Agent/Adapters/AGUIAdapter.php:485`](../src/Agent/Adapters/AGUIAdapter.php#L485) · repro [`AGUIInterruptRunIdReproTest`](repro/Agent/AGUIInterruptRunIdReproTest.php) · fix validated

When an `AGUIAdapter` has no `runId` (none passed to the constructor and `start()` never called), `end()` suppresses `RUN_FINISHED` through its `runId !== null` guard, but `interrupt()` has no such guard and publishes `RUN_FINISHED` with `runId` null and no preceding `RUN_STARTED`. AG-UI requires a string `runId`, so a strict client such as CopilotKit may reject the frame or leave the run inconsistent. The Workflow always calls `start()` first, so only applications that drive the adapter directly (custom workflows, test harnesses, reload tooling) are affected, but the two terminal methods disagree on the same state.

Suggested fix: in `src/Agent/Adapters/AGUIAdapter.php::interrupt()`, wrap the final `RUN_FINISHED` in the same `if ($this->runId !== null)` guard that `end()` uses. The alternative of generating a `runId` would still emit `RUN_FINISHED` without `RUN_STARTED`. This was validated in a sandbox against the repro and the module's tests.

### <a id="agent-07"></a>AGENT-07 · AG-UI RUN_ERROR exposes the raw exception code, bypassing errorMessage() redaction

**low** · security · [`src/Agent/Adapters/AGUIAdapter.php:775`](../src/Agent/Adapters/AGUIAdapter.php#L775) · repro [`AGUIErrorCodeRedactionTest`](repro/Agent/AGUIErrorCodeRedactionTest.php) · fix validated

The module guidance says failures reach the client only through the neutral text of the protected `errorMessage()` hook, yet `AGUIAdapter::error()` adds `'code' => (string) $error->getCode()` for any non-zero code. The browser then receives values such as a PDO SQLSTATE (`HY000`, `42S02`), an upstream HTTP status (`503`, `429`) or a driver errno, revealing the database engine, error class or upstream availability. An application that redacts messages via `errorMessage()` cannot suppress the code without copying the whole `error()` method, which also manages the frame lifecycle. The Vercel and native adapters emit no code. No secrets or message text leak, so the disclosure is minor.

Suggested fix: in `src/Agent/Adapters/AGUIAdapter.php`, have `error()` take the code from a new protected `errorCode(Throwable $error): ?string` hook that returns `null` by default, and replace the test that pinned the old behaviour. This adds a protected hook to the adapter API and needs your confirmation. It was validated in a sandbox against the repro and the module's tests.

### <a id="agent-08"></a>AGENT-08 · VercelAIAdapter constructor crashes on client parts with a non-string toolCallId

**low** · input-validation · [`src/Agent/Adapters/VercelAIAdapter.php:74`](../src/Agent/Adapters/VercelAIAdapter.php#L74) · repro [`VercelAIAdapterSeedReproTest`](repro/Agent/VercelAIAdapterSeedReproTest.php) · fix validated

The README and the integration router pass the client's last assistant message parts straight into `new VercelAIAdapter($last['id'], $last['parts'])`. The constructor only checks `isset($part['toolCallId'])` and uses the value as an array key. An array `toolCallId` raises `TypeError: Cannot access offset of type array on array` inside the stream adapter factory, so a malformed request becomes a 500 rather than an ignored part. A float is truncated to an int key with a deprecation notice. `VercelAIInputTranslator` already ignores such parts, so this is the only place that fails. There is no data loss, cross-user effect or authorization impact.

Suggested fix: in `src/Agent/Adapters/VercelAIAdapter.php`, skip parts whose `toolCallId` is not a string (`is_string` check with `continue`), as `VercelAIInputTranslator` does. This was validated in a sandbox against the repro and the module's tests.

### <a id="agent-09"></a>AGENT-09 · Toolkit guidelines omit the first tool's bullet and leak anonymous class paths

**low** · bug · [`src/Agent/Agent.php:197`](../src/Agent/Agent.php#L197) · repro [`ToolkitGuidelinesListTest`](repro/Agent/ToolkitGuidelinesListTest.php) · fix validated

`Agent::resolveTools()` appends tool names as `PHP_EOL . implode(PHP_EOL.'- ', $names)`, so the first tool has no `- ` prefix (`get_weather\n- search`). This affects every toolkit with guidelines, including the built-in Calculator, FileSystem, TodoPlanning and SQL toolkits, though the effect is only formatting. The heading uses `ReflectionClass::getShortName()`, which for an anonymous toolkit contains a NUL byte and the absolute path of the declaring file. That string is sent to the provider in the system prompt and to any logging of prompts, a minor information disclosure, and the NUL byte may be rejected or mangled by strict JSON or text handling.

Suggested fix: in `src/Agent/Agent.php::resolveTools()`, map each tool to `'- '.$tool->getName()` and join with `PHP_EOL`, and derive the heading from the part of `$tool::class` before `@anonymous` (its basename), then remove the unused `ReflectionClass` import. This was validated in a sandbox against the repro and the module's tests.

### <a id="agent-10"></a>AGENT-10 · AgentState::getToolRuns() throws TypeError when called without a tool name

**low** · bug · [`src/Agent/AgentState.php:67`](../src/Agent/AgentState.php#L67) · repro [`AgentStateToolRunsTotalTest`](repro/Agent/AgentStateToolRunsTotalTest.php) · fix validated

`AgentState::getToolRuns(?string $toolName = null): int` returns the whole counter array when `$toolName` is null, violating its declared `int` return type. Any call without an argument fails with `TypeError: Return value must be of type int, array returned`, even on a fresh state. Middleware, observability code or custom nodes that want the total number of tool runs hit this. The framework's own run-limit check always passes a key, so it is unaffected, and no persisted state is damaged.

Suggested fix: in `src/Agent/AgentState.php`, return `array_sum($attempts)` in the null branch. Alternatively, since no caller uses the null path, make the parameter required and delete the branch; you should choose between the two. The `array_sum` version was validated in a sandbox against the repro and the module's tests.

### <a id="agent-11"></a>AGENT-11 · ContentHelper::removeDelimitedContent throws TypeError on outputs around 1MB or larger

**low** · bug · [`src/Agent/ContentHelper.php:21`](../src/Agent/ContentHelper.php#L21) · repro [`ContentHelperLargeInputTest`](repro/Agent/ContentHelperLargeInputTest.php) · fix validated

`ContentHelper::removeDelimitedContent()` uses the lazy pattern `/open.*?close/s`, which spends backtracking steps per character. On about 1MB of text, either one well-formed closed block or an unterminated opening tag followed by long text, `preg_replace()` hits the PCRE backtrack limit and returns `null`, and the `string` return type turns that into a `TypeError`. User code that strips `<think>` or other reasoning blocks from long model output crashes instead of getting cleaned text. No code in `src/` calls the helper, so framework flows are unaffected.

Suggested fix: in `src/Agent/ContentHelper.php`, replace the regex with a linear `strpos` scan that keeps the same semantics (leftmost open tag, nearest close tag, unclosed blocks kept), plus a guard that returns the text unchanged when both delimiters are empty. This was validated in a sandbox against the repro and the module's tests.

### <a id="agent-12"></a>AGENT-12 · AGUIInputTranslator::tools() lets unsupported client schemas escape as ToolException or TypeError

**low** · bug · [`src/Agent/Frontend/AGUIInputTranslator.php:84`](../src/Agent/Frontend/AGUIInputTranslator.php#L84) · repro [`AGUIToolCatalogReproTest`](repro/Agent/AGUIToolCatalogReproTest.php) · fix validated

`AGUIInputTranslator::tools()` only checks that `parameters` is an array before `new FrontendTool()` runs it through `ToolPropertyFactory::fromSchema()`. Client catalogs using an unknown type, `$ref` or `anyOf` (which zod-to-json-schema and CopilotKit emit routinely) raise `ToolException`; malformed ones such as a string property definition, a list of properties or a string `required` raise a PHP `TypeError` whose message includes absolute server paths. An endpoint that follows the README and maps `InputTranslationException` to a 400 returns a 500 instead. A string `properties` is accepted with only a warning, and the tool keeps its malformed raw schema. Input is rejected, never executed.

Suggested fix: in `src/Agent/Frontend/AGUIInputTranslator.php::tools()`, wrap the `FrontendTool` construction and convert `ToolException`, `ArrayPropertyException` and `TypeError` into an `InputTranslationException` naming the tool, keeping the original as previous; and in `src/Tools/ToolPropertyFactory.php::fromSchema()`, reject a non-array `properties` with `ToolException`. This was validated in a sandbox against the repro and the module's tests.

### <a id="agent-13"></a>AGENT-13 · AG-UI resolved resume with an empty result map is accepted for a deferred batch

**low** · input-validation · [`src/Agent/Frontend/AGUIInputTranslator.php:132`](../src/Agent/Frontend/AGUIInputTranslator.php#L132) · repro [`AGUIEmptyResumeReproTest`](repro/Agent/AGUIEmptyResumeReproTest.php) · fix validated

In `AGUIInputTranslator::translateResume()`, a resolved resume of a `ToolResultsRequest` takes the generic branch and only calls `validateResults($answers)`, skipping the `inputs()` guard that rejects an empty answer set. A payload of `{}` is therefore accepted and `translate()` returns `[]`, while the AG-UI messages form, the Vercel translator and native `submitToolResults()` reject the same input with 'The payload contains no matching continuation input.'. The accepted resume is not a no-op: the run executes, consumes an execution attempt and re-suspends with a new interrupt ID, so a later correct resume targeting the old ID is rejected as stale. Nothing is corrupted or leaked.

Suggested fix: in `src/Agent/Frontend/AGUIInputTranslator.php::translateResume()`, return `$this->inputs($request, $answers)` for a `ToolResultsRequest` instead of calling `validateResults()` directly. This was validated in a sandbox against the repro and the module's tests.

### <a id="agent-14"></a>AGENT-14 · Summarization throws TypeError instead of using its placeholder when the summary has no text

**low** · bug · [`src/Agent/Middleware/Summarization.php:162`](../src/Agent/Middleware/Summarization.php#L162) · repro [`SummarizationEmptySummaryTest`](repro/Agent/SummarizationEmptySummaryTest.php) · fix validated

`Summarization::generateSummary(): string` returns `$response->message()->getContent()`, which is `?string`. When the summarizing provider returns no text (an empty completion, a safety-filtered or reasoning-only reply, or thinking that used the output budget), a `TypeError` is raised. Because it is an `Error`, the `catch (Exception)` fallback meant to degrade to a placeholder summary never runs, and the inference aborts. The threshold is checked before every inference, so if the provider keeps returning no text, every turn of the thread fails until the history shrinks or the middleware is removed. No history is lost, since `flushAll()` runs only after the summary is generated.

Suggested fix: in `src/Agent/Middleware/Summarization.php::generateSummary()`, return `getContent() ?? $this->placeholderSummary($messages)`, extracting the existing placeholder text into a protected `placeholderSummary()` helper shared with the catch branch. This was validated in a sandbox against the repro and the module's tests.

### <a id="agent-15"></a>AGENT-15 · Deferred tool calls sharing a call ID collapse, and one result settles both

**low** · bug · [`src/Agent/Nodes/AwaitToolResultsNode.php:50`](../src/Agent/Nodes/AwaitToolResultsNode.php#L50) · repro [`DuplicateDeferredCallIdTest`](repro/Agent/DuplicateDeferredCallIdTest.php) · fix validated

`AwaitToolResultsNode` keys pending calls and results by call ID. When two deferred (frontend) calls in one batch share an ID, the later call overwrites the earlier one, so the `ToolResultsRequest` advertises only the second call. The client's single result is then written onto both calls, and the model is told the first call, with different inputs, returned the second call's output, although it was never shown to the client or executed. There is no error or observability signal. The trigger requires a non-conforming provider that emits duplicate or null IDs; no built-in provider does. Local execution handles shared IDs correctly because its memo key includes the position.

Suggested fix: in `src/Agent/Nodes/ToolNode.php::__invoke()`, track the call IDs already dispatched in the deferred loop and settle any later call with a duplicate ID locally with a `ToolOutput::error` result instead of dispatching it, as run-limit failures are handled today; `ParallelToolNode` inherits this. This was validated in a sandbox against the repro and the module's tests.

### <a id="agent-16"></a>AGENT-16 · Duplicate call IDs on approval-gated tools leave a dangling ToolCallMessage in history

**low** · design · [`src/Agent/Nodes/ToolNode.php:158`](../src/Agent/Nodes/ToolNode.php#L158) · repro [`DuplicateGatedCallIdTest`](repro/Agent/DuplicateGatedCallIdTest.php) · fix validated

`ToolNode::resolveToolApprovals()` writes the `ToolCallMessage` to history before building the `ApprovalRequest`. If two approval-gated calls share a call ID, the `ApprovalRequest` constructor throws `WorkflowException('Duplicate approval action id')` after that write, leaving history ending in a tool call with no result. `abandon()` then refuses with a misleading 'unanswered tool call' message, there is no approval request to submit, and only `resetConversation()`, which deletes the whole history, frees the thread. Built-in providers supply unique IDs (Gemini now synthesizes one), so only a custom or buggy provider triggers this.

Suggested fix: in `src/Agent/Nodes/ToolNode.php::resolveToolApprovals()`, call the side-effect-free `buildApprovalRequest()` once before the pre-suspend history write, so a duplicate-ID batch fails before anything is persisted; `ParallelToolNode` inherits the fix. This was validated in a sandbox against the repro and the module's tests. A separate issue, a trailing committed `UserMessage` after any failed tool cycle, is not addressed by this change.

### <a id="agent-17"></a>AGENT-17 · An approval-gated tool call without a call ID can never be approved

**low** · correctness · [`src/Agent/Nodes/ToolNode.php:334`](../src/Agent/Nodes/ToolNode.php#L334) · repro [`NullCallIdApprovalTest`](repro/Agent/NullCallIdApprovalTest.php) · fix validated

`ToolNode::buildApprovalRequest()` uses `$call->getCallId() ?? uniqid('tool_')` as the action ID, but `applyDecisions()` only matches calls by their real, non-null call ID. `submitApprovalDecisions()` accepts a decision for the advertised ID and resumes, the decision is dropped, the call stays pending, and the run suspends again under a new random ID. Neither approval nor rejection can ever settle the call, so the run deadlocks silently until abandoned; the tool never runs, so approval is not bypassed. Built-in providers that lack IDs synthesize them, so this needs a custom provider, a malformed streaming response or a hand-built `ToolCallMessage`.

Suggested fix: in `src/Agent/Nodes/ToolNode.php::resolveToolApprovals()`, assign a gated call without an ID a deterministic ID derived from the message's durable ID and the call's batch index before the history write, then drop the `uniqid` fallback. This was validated in a sandbox against the repro and the module's tests.

### <a id="agent-18"></a>AGENT-18 · ToolRunsExceededException message embeds the raw model-generated tool arguments

**low** · security · [`src/Agent/Nodes/ToolNode.php:466`](../src/Agent/Nodes/ToolNode.php#L466) · repro [`ToolRunsExceededRedactionTest`](repro/Agent/ToolRunsExceededRedactionTest.php) · fix validated

`ToolNode::checkToolRuns()` builds the `ToolRunsExceededException` message with `json_encode($call->getInputs())` appended, unbounded. Tool arguments often quote user content such as PII, pasted credentials or query text. Error trackers and generic exception logging capture the message without passing through any listener, and the `WorkflowError` log record carries it as a free-form string that a `LogListener` redaction subclass cannot filter. The extra exposure is narrow, because the same inputs already appear in `ToolCalling`, `ToolCalled` and `InferenceStop` records and in chat history, and it only occurs when a run limit is exceeded.

Suggested fix: in `src/Agent/Nodes/ToolNode.php::checkToolRuns()`, drop the arguments from the message, keeping the tool name and run count; the inputs remain available through the `ToolCalling` event and the `ToolCall` passed to error handlers. Tests pinning the old wording in `tests/Tools/ParallelToolsTest.php` and `tests/Agent/ToolRunLimitTest.php` need updating. This was validated in a sandbox against the repro and the module's tests.

## <a id="module-workflow"></a>Workflow

Workflow has 27 findings and none is rated above medium, which matches how solid the engine proved under mutation testing. The medium items are semantic edge cases worth fixing before they bite: a branch that finished with a null result reported as missing ([WORKFLOW-01](#workflow-01)), nested fork branches starting from the top-level state instead of their parent branch ([WORKFLOW-02](#workflow-02)), a node that waits twice without memoizing receiving the second answer at the first wait ([WORKFLOW-03](#workflow-03)), case-insensitive filesystem collisions in `FilePersistence` ([WORKFLOW-04](#workflow-04)), and the unstated trust assumption behind `PhpSerializer` ([WORKFLOW-05](#workflow-05)). The low items tighten validation at graph build and in interrupt envelopes, and align exceptions across persistence backends.

### <a id="workflow-01"></a>WORKFLOW-01 · `ParallelEvent::hasResult()` reports a branch that finished with a null result as missing

**medium** · bug · [`src/Workflow/Events/ParallelEvent.php:71`](../src/Workflow/Events/ParallelEvent.php#L71) · repro [`ParallelEventNullResultTest`](repro/Workflow/ParallelEventNullResultTest.php) · fix validated

`hasResult()` uses `isset()`, which is false for a key whose value is `null`. `Segment::branch()` records `StopEvent::getResult()` for every completed branch, and that value is `null` for the common `new StopEvent()`. A join node that checks `hasResult()` therefore treats a finished branch as unfinished, even though `getAllResults()` contains its key, which can cause skipped merges, false "missing branch" errors or waits that never finish. The Sequential and Async runners also rely on `hasResult()`, so after a deferral such a branch is traversed again. Its BranchStart/BranchEnd events are re-emitted and its memoized steps replayed, although node side effects do not run twice.

Suggested fix: in `src/Workflow/Events/ParallelEvent.php`, replace `isset($this->results[$branch])` with `array_key_exists($branch, $this->results)`, which was validated in a sandbox against the repro and the module's tests. Optionally, `getResult()` could throw a `WorkflowException` for an unknown branch instead of reading an undefined array key.

### <a id="workflow-02"></a>WORKFLOW-02 · Nested fork branches start from the top-level state, not their parent branch's state

**medium** · bug · [`src/Workflow/Executor/Segment.php:109`](../src/Workflow/Executor/Segment.php#L109) · repro [`NestedForkStateTest`](repro/Workflow/NestedForkStateTest.php) · fix validated

`Segment::branch()` always builds a branch from `clone $this->state`, and `$this->state` only tracks the top-level path. When a node inside a parallel branch writes to `WorkflowState` and then returns a `ParallelEvent`, the inner branches start from the top-level state as it was when the outer fork began, so they do not see anything the parent branch wrote. This happens with both runners. Nodes inside the inner fork read missing or stale values and produce wrong results without any error. The join node after the inner fork does see the parent branch's values, so state is inconsistent within a single branch and hard to debug.

Suggested fix: in `src/Workflow/Executor/Segment.php`, have `fork()` receive the state it was reached with, store it in a protected `forkStates` map keyed by fork step ID for the duration of the fork, and clone that entry in `branch()`. This keeps the `BranchRunner` interface unchanged and was validated in a sandbox against the repro and the module's tests. The simpler alternative, passing the state through `BranchRunner::run()`, changes a public interface.

### <a id="workflow-03"></a>WORKFLOW-03 · A node that waits twice without memoizing gives the second answer to the first wait

**medium** · design · [`src/Workflow/Node.php:62`](../src/Workflow/Node.php#L62) · repro [`ReplayedAnswerTest`](repro/Workflow/ReplayedAnswerTest.php) · fix validated

A resumed node runs again from the top, and the first `interrupt`/`awaitEvent` call it reaches consumes the current interruption's payload, whichever interruption that payload answers. If a node calls `awaitEvent('first')` and then `awaitEvent('second')` without wrapping the first in `memoize()`, the answer to interrupt #2 is returned to the first wait, overwriting its state, and the second wait raises a new interrupt #3. Every later answer is misrouted the same way, so the run never completes and nothing reports an error, even when the caller used the name-checked `signal()`. A multi-step approval node can record the approval of step 2 as the decision for step 1. The engine knows which request is being answered but never checks it against the consuming wait.

Suggested fix: add an `answering` request to `NodeContext`, set it in `Segment::runNodeStep()` when resuming, and have `Node::interruptIf()` throw a `WorkflowException` when the replayed wait's class or event name does not match the interruption being answered; this was validated in a sandbox against the repro and the module's tests, but does not catch two waits with the same class and event name. A larger alternative is to persist each answer keyed by the wait's position within the node. In both cases, document in `src/Workflow/AGENTS.md` that earlier answers must be memoized.

### <a id="workflow-04"></a>WORKFLOW-04 · `FilePersistence` partitions whose names differ only by case collide on case-insensitive filesystems

**medium** · bug · [`src/Workflow/Persistence/FilePersistence.php:229`](../src/Workflow/Persistence/FilePersistence.php#L229) · repro [`FilePersistenceCaseInsensitiveFilesystemTest`](repro/Workflow/FilePersistenceCaseInsensitiveFilesystemTest.php) · fix validated

`filePath()` builds the file name as `rawurlencode($partition) . '.store'`, which keeps letter case. On case-insensitive filesystems (default macOS APFS/HFS+, Windows NTFS), workflow IDs such as `Order:A` and `order:a` map to the same file. The second workflow's `initializeIfAbsent` reports it as already existing, writes can overwrite the other workflow's control, steps and checkpoints, and deleting one deletes the other. No error is raised, and the Linux CI filesystem is case-sensitive, so the tests cannot catch it. `FileMessageStore` has the same issue for chat thread IDs. After a fix, existing partitions whose names contain uppercase letters map to new file names, which needs a changelog note.

Suggested fix: in `src/Workflow/Persistence/FilePersistence.php::filePath()`, replace `rawurlencode` with a `preg_replace_callback` that percent-escapes every byte outside `[a-z0-9._~-]`, so uppercase letters are escaped too and all-lowercase names keep their current file names. Apply the same change to `FileMessageStore::path()`. This was validated in a sandbox against the repro and the module's tests.

### <a id="workflow-05"></a>WORKFLOW-05 · `PhpSerializer` unserializes persisted records with no class restriction or authenticity check

**medium** · security · [`src/Workflow/Persistence/PhpSerializer.php:21`](../src/Workflow/Persistence/PhpSerializer.php#L21) · repro [`PhpSerializerAllowedClassesTest`](repro/Workflow/PhpSerializerAllowedClassesTest.php) · fix validated

`PhpSerializer::unserialize()` calls `@unserialize($data)` without `allowed_classes`, and every record the engine reads (control, ignition, steps, checkpoints, memos) comes from an external store. Anyone who can write to that store, such as a shared or unauthenticated Redis, a shared SQL table or DB user, SQL injection elsewhere in the app, or a writable `FilePersistence` directory, can plant a serialized object. The next `inspect()`, resume or continuation instantiates it and runs its magic methods before the engine's type checks reject the record. With a gadget chain in the app's dependencies, that is remote code execution in the worker. Apps using `InMemoryPersistence`, or a store only they can write to, are not exposed. The framework offers no mitigation and does not document the trust requirement.

Suggested fix: add an opt-in `bool|array $allowedClasses = true` constructor parameter to `src/Workflow/Persistence/PhpSerializer.php` and pass it to `unserialize()`; this was validated in a sandbox against the repro and the module's tests, but a complete allow-list must also cover engine internals, which is burdensome. A serializer-agnostic HMAC decorator (for example a `SignedSerializer`) may be the better long-term option. Both add public API, and at minimum `src/Workflow/AGENTS.md` should state that the store must be as trusted as the code.

### <a id="workflow-06"></a>WORKFLOW-06 · A retried answer with identical content but different key order is refused as conflicting

**low** · design · [`src/Workflow/Executor/ActiveInterrupt.php:27`](../src/Workflow/Executor/ActiveInterrupt.php#L27) · repro [`ReorderedAnswerTest`](repro/Workflow/ReorderedAnswerTest.php) · fix validated

`ActiveInterrupt::withInput()` decides whether a new answer repeats the accepted one by comparing raw `json_encode()` strings, and PHP arrays keep insertion order. If a platform retries a resume after a run failed with its accepted input still unsettled, and the payload was re-encoded with object keys in another order (by a different client, serializer or runtime), the retry fails with "already has an accepted input; its answer cannot change". A delivery worker can then escalate or dead-letter a valid delivery. The refusal is fail-safe: the accepted answer is kept, and an inputless `ExecutionRequest::resume()` still recovers the run.

Suggested fix: in `src/Workflow/Executor/ActiveInterrupt.php`, compare a canonical form that decodes the payload with `assoc=false`, sorts object members recursively and keeps list order, so type differences, extra fields and list order still conflict. This was validated in a sandbox against the repro and the module's tests.

### <a id="workflow-07"></a>WORKFLOW-07 · Workflow traversal has no step limit, so a routing cycle runs and persists forever

**low** · design · [`src/Workflow/Executor/Segment.php:238`](../src/Workflow/Executor/Segment.php#L238) · repro [`WorkflowCycleBudgetTest`](repro/Workflow/WorkflowCycleBudgetTest.php) · fix validated

`Segment::traverse()` loops until a `StopEvent`, `InterruptEvent` or `BranchPausedEvent`, committing a step record with a full state snapshot on every iteration. In a plain Workflow composition with a cycle that never stops, caused by a node bug or a model that keeps choosing the same route, the run spins forever and storage grows without bound. `FilePersistence` rewrites the whole partition on every step, and with a lease configured each commit renews the lease, so the run never looks dead and cannot be taken over. The only way out is to kill the worker. Agent's tool loop has its own limit, so this applies to custom workflows; loops are a documented feature, which is why this is a missing guard rather than a broken contract.

Suggested fix: add an opt-in `setMaxSteps(?int)` on `Workflow` (with a `maxSteps` property in `HandleComponents`) and pass it to `Segment::run()`, whose `traverse()` throws a `WorkflowException` once the step index reaches the budget, so the run is marked failed and its lease cleared. This was validated in a sandbox against the repro and the module's tests, but it is new public API.

### <a id="workflow-08"></a>WORKFLOW-08 · Node and middleware start events have no end event when a node suspends or fails

**low** · design · [`src/Workflow/Executor/Segment.php:390`](../src/Workflow/Executor/Segment.php#L390) · repro [`NodeSpanPairingTest`](repro/Workflow/NodeSpanPairingTest.php) · fix validated

`Segment::runNode()` reports `WorkflowNodeStart` and `MiddlewareStart`, but `WorkflowNodeEnd` and `MiddlewareEnd` are only reported on the success path. When a node suspends (including every agent tool-approval interrupt and every `awaitEvent`/`interrupt` call), when a node throws, or when a middleware throws in `before()` or `after()`, the matching end event is never sent, also inside parallel branches. Listeners that pair start and end events into spans, such as APM integrations, tracing exporters and duration metrics, are left with open spans, and the node looks like it is still running. `WorkflowEnd` and `BranchEnd` are already sent from `finally` blocks, so this is an inconsistency. Execution and persistence are not affected.

Suggested fix: in `src/Workflow/Executor/Segment.php::runNode()`, report `MiddlewareEnd` and `WorkflowNodeEnd` from `finally` blocks, as is done for `WorkflowEnd` and `BranchEnd`. This was validated in a sandbox against the repro and the module's tests. An outcome field on `WorkflowNodeEnd` is a possible follow-up.

### <a id="workflow-09"></a>WORKFLOW-09 · A missing deferred step record fails the resume with a null-dereference `Error`

**low** · bug · [`src/Workflow/Executor/Segment.php:400`](../src/Workflow/Executor/Segment.php#L400) · repro [`MissingDeferredStepTest`](repro/Workflow/MissingDeferredStepTest.php) · fix validated

`settleInterrupt()` calls `$this->store->loadStep($nextStepId)->getEvent()` directly, and `loadStep()` returns `null` when the record is absent. When a parallel run has several interrupted branches queued in `pendingSteps` and the backend loses the deferred step record (partial replication, Redis eviction or TTL, manual cleanup), the resume fails with "Call to a member function getEvent() on null". This happens after the answered node has run its side effects and before its step is committed. Control is not advanced, so no committed data is lost, but callers that catch `PersistenceException` or `WorkflowException` to detect corrupted state miss it, and operators get a stack trace instead of the name of the missing record.

Suggested fix: in `src/Workflow/Executor/Segment.php`, load the deferred step through a protected helper that throws a `PersistenceException` naming the missing record when `loadStep()` returns `null`. This was validated in a sandbox against the repro and the module's tests. The same check could instead live in `WorkflowRunStore` to keep key formatting in one place.

### <a id="workflow-10"></a>WORKFLOW-10 · An event instance as a parallel branch crashes graph export with an `Error`

**low** · bug · [`src/Workflow/Exporter/WorkflowGraphBuilder.php:152`](../src/Workflow/Exporter/WorkflowGraphBuilder.php#L152) · repro [`ParallelBranchInstanceTest`](repro/Workflow/ParallelBranchInstanceTest.php) · fix validated

`WorkflowGraphBuilder::addParallelTransition()` guards against non-string branches, but builds the exception message as `$event . ' must implement ' . Event::class`. When a developer passes an event instance, such as `new TextProcessEvent()` instead of its class name, in a `ParallelTransition` returned from `DescibeExporterTransitions::describe()`, the concatenation itself throws `Error: Object of class ... could not be converted to string` before the `InvalidArgumentException` is created. An array branch emits an "Array to string conversion" warning. Code that catches `InvalidArgumentException` around export does not catch the error, and the message does not explain that a class-string is expected. Workflow execution is not affected.

Suggested fix: in `src/Workflow/Exporter/WorkflowGraphBuilder.php` line 152, build the message with `(is_string($event) ? $event : get_debug_type($event))`. This was validated in a sandbox against the repro and the module's tests.

### <a id="workflow-11"></a>WORKFLOW-11 · Graph export labels anonymous classes with a NUL byte and absolute file path

**low** · security · [`src/Workflow/Exporter/WorkflowGraphBuilder.php:233`](../src/Workflow/Exporter/WorkflowGraphBuilder.php#L233) · repro [`AnonymousNodeExportTest`](repro/Workflow/AnonymousNodeExportTest.php) · fix validated

`WorkflowGraphBuilder::shortName()` uses `ReflectionClass::getShortName()`, which for an anonymous class returns a name like `Node@anonymous\0/abs/path/file.php:LINE$0`. `ConsoleExporter` and `MermaidExporter` print it as is. When a workflow uses anonymous classes as nodes or events, the export contains a raw NUL byte, which makes the Mermaid or console output malformed and can truncate it in C-string based tools. It also discloses the server's absolute source path wherever the diagram is published, such as docs, dashboards or issue reports. Named classes are not affected.

Suggested fix: in `src/Workflow/Exporter/WorkflowGraphBuilder.php::shortName()`, keep only the part of the short name before the NUL byte (`explode("\0", ...)[0]`), which yields `Node@anonymous` and leaves normal class names unchanged. This was validated in a sandbox against the repro and the module's tests.

### <a id="workflow-12"></a>WORKFLOW-12 · Middleware registered for a non-existent class is accepted and never runs

**low** · robustness · [`src/Workflow/Graph.php:68`](../src/Workflow/Graph.php#L68) · repro [`UnknownMiddlewareTargetTest`](repro/Workflow/UnknownMiddlewareTargetTest.php) · fix validated

`addMiddleware()` stores any string as a node class key, and `Graph::middlewareFor()` applies it with `$node instanceof $class`, which is simply false for a name that is not a class or interface. A guard or approval middleware registered with a typo, a stale `use` import after a class rename (PHP resolves `::class` without autoloading), a trait name or a runtime-built string never wraps any node, and nothing reports it. For a guard, the protected node runs without the check. PHPStan catches the common `::class` form but not dynamic or config-driven keys or the `middleware()` hook.

Suggested fix: in the `src/Workflow/Graph.php` constructor, throw a `WorkflowException` for any middleware key where both `class_exists()` and `interface_exists()` are false. This covers both `addMiddleware()` and the `middleware()` hook, and was validated in a sandbox against the repro and the module's tests.

### <a id="workflow-13"></a>WORKFLOW-13 · Middleware registration keeps earlier valid entries when a later entry is rejected

**low** · bug · [`src/Workflow/HandleMiddleware.php:53`](../src/Workflow/HandleMiddleware.php#L53) · repro [`MiddlewareRegistrationAtomicityTest`](repro/Workflow/MiddlewareRegistrationAtomicityTest.php) · fix validated

`addGlobalMiddleware()` and `addMiddleware()` validate and append each entry in the same loop. When a list contains an invalid entry after valid ones, the valid ones are already registered when the `WorkflowException` is thrown. For `addMiddleware()` with several node classes, only the classes handled before the failure get the middleware. A caller that catches the exception and keeps using the workflow, such as a configuration loader that skips a bad bundle, is left with middleware it believes was rejected, running on every node or on some of them. Callers that let the exception propagate are not affected.

Suggested fix: in `src/Workflow/HandleMiddleware.php`, validate the whole list first through a protected `validatedMiddleware()` helper, then append the result in `addGlobalMiddleware()` and for each class in `addMiddleware()`. This was validated in a sandbox against the repro and the module's tests.

### <a id="workflow-14"></a>WORKFLOW-14 · Subclass metadata can overwrite `interruptId` and `type` in the interrupt JSON envelope

**low** · design · [`src/Workflow/Interrupt/InterruptRequest.php:79`](../src/Workflow/Interrupt/InterruptRequest.php#L79) · repro [`InterruptEnvelopeIdentityTest`](repro/Workflow/InterruptEnvelopeIdentityTest.php) · fix validated

The final `InterruptRequest::jsonSerialize()` returns `array_merge(['interruptId' => ..., 'type' => ...], coordinationData(), metadata())`, so later keys win. A custom `InterruptRequest` subclass whose `metadata()` or `coordinationData()` contains `interruptId` or `type`, deliberately, by accident or by copying input data, replaces the ID and category that platforms and adapters use for correlation and routing. Delivery jobs can then target the wrong interrupt ID or capability, `fromArray()` rebuilds a different ID, and the run can stall when resume checks reject the input. The persisted request is not changed, and no built-in request type uses these keys.

Suggested fix: in `src/Workflow/Interrupt/InterruptRequest.php::jsonSerialize()`, merge the identity block both first and last, which keeps its position at the front and makes it win. This was validated in a sandbox against the repro and the module's tests; merging it only last breaks three existing key-order tests.

### <a id="workflow-15"></a>WORKFLOW-15 · `fromArray()` defaults a missing wake time to now and leaks raw date exceptions

**low** · design · [`src/Workflow/Interrupt/SleepUntilRequest.php:77`](../src/Workflow/Interrupt/SleepUntilRequest.php#L77) · repro [`SleepEnvelopeWakeTimeTest`](repro/Workflow/SleepEnvelopeWakeTimeTest.php) · fix validated

`SleepUntilRequest::fromArray()` calls `new DateTimeImmutable((string) ($data['wakeAt'] ?? 'now'))`. An envelope without `wakeAt` becomes a timer that is already due, relative strings such as `yesterday` are accepted although the envelope format is ATOM, and an unparseable value throws the raw `DateMalformedStringException` instead of a `WorkflowException`. `WaitForEventRequest::fromArray()` behaves the same for `expiresAt` and turns a missing `eventName` into an empty string. This affects schedulers and coordinators that rebuild requests from the JSON envelope, which may act on invented data. Nothing in `src/` calls these methods and resume validation uses the persisted request, so engine integrity is not affected.

Suggested fix: add a protected static `parseTimestamp()` helper to `src/Workflow/Interrupt/InterruptRequest.php` that accepts only a strict ATOM string and otherwise throws a `WorkflowException`, use it in both `fromArray()` methods, and reject a missing or empty `eventName` in `WaitForEventRequest`. This was validated in a sandbox against the repro and the module's tests; the strict round-trip rejects a `Z` suffix, which may need relaxing.

### <a id="workflow-16"></a>WORKFLOW-16 · Interrupt request `fromArray()` methods silently coerce malformed `interruptId` values

**low** · input-validation · [`src/Workflow/Interrupt/WaitForEventRequest.php:107`](../src/Workflow/Interrupt/WaitForEventRequest.php#L107) · repro [`InterruptEnvelopeIdTest`](repro/Workflow/InterruptEnvelopeIdTest.php) · fix validated

`WaitForEventRequest::fromArray()` and `SleepUntilRequest::fromArray()` apply the ID with `(int) $data['interruptId']`, so `'7abc'`, `'7'`, `true` and `2.9` become 7, 7, 1 and 2 without error. `ResumeInput::fromArray()` in the same module requires `is_int()`. Code that rebuilds envelopes from external JSON therefore hides integration bugs and can address a resume to a different interrupt ID. The executor still checks every resume against the persisted active interrupt, so a mismatched ID is rejected downstream and state cannot be corrupted or bypassed.

Suggested fix: in both `fromArray()` methods, throw a `WorkflowException` when `interruptId` is set but not an integer, matching `ResumeInput::fromArray()`. This was validated in a sandbox against the repro and the module's tests.

### <a id="workflow-17"></a>WORKFLOW-17 · Node state parameter subclass is not validated at graph build, failing mid-run

**low** · design · [`src/Workflow/NodeSignature.php:58`](../src/Workflow/NodeSignature.php#L58) · repro [`StateTypeValidationTest`](repro/Workflow/StateTypeValidationTest.php) · fix validated

`NodeSignature` checks a node's resources parameter against the resources the workflow actually provides, so a mismatch fails before the run. The state parameter is only checked with `is_a(..., WorkflowState::class)`. A node typed with a `WorkflowState` subclass in a workflow whose `state()` hook or seed produces a plain `WorkflowState` passes validation. The earlier nodes run and commit their side effects, and the run then fails with a raw PHP `TypeError` that does not name the node or workflow. The failed generation is auto-recovered by the next `run()` and fails the same way. No data is lost.

Suggested fix: pass the segment's state into `Graph` and `NodeSignature::eventClass()` and reject the node when the state is not an instance of the declared type, as is done for resources. This touches `NodeSignature.php`, `Graph.php`, `Workflow::graph()`, `Segment.php` and `HandleComponents::export()`, and was validated in a sandbox against the repro and the module's tests. A mismatched node on an unreachable path will now fail early, the same trade-off already accepted for resources.

### <a id="workflow-18"></a>WORKFLOW-18 · `NodeSignature` accepts interface or abstract event types that routing can never reach

**low** · design · [`src/Workflow/NodeSignature.php:110`](../src/Workflow/NodeSignature.php#L110) · repro [`NodeSignatureAbstractEventTest`](repro/Workflow/NodeSignatureAbstractEventTest.php) · fix validated

`resolveEventClass()` only checks `is_a($type, Event::class, true)`, so a node whose first parameter is typed as `Event`, an interface extending `Event`, or an abstract event class passes validation. The graph routes by the concrete `$event::class`, so such a node can never be selected. When it is not the start node, the graph builds successfully and the run fails later with "No node found that handle event: <concrete class>", after earlier steps have committed and their side effects have happened. The error does not point at the node signature, which is the actual mistake.

Suggested fix: in `src/Workflow/NodeSignature.php`, pass both return paths of `resolveEventClass()` through a protected `routable()` helper that throws when the class is an interface or abstract (not `isInstantiable()`, so classes with private constructors stay accepted). This was validated in a sandbox against the repro and the module's tests.

### <a id="workflow-19"></a>WORKFLOW-19 · `EloquentPersistence::initializeIfAbsent` lets a related record override the initial condition value

**low** · api-inconsistency · [`src/Workflow/Persistence/EloquentPersistence.php:53`](../src/Workflow/Persistence/EloquentPersistence.php#L53) · repro [`EloquentInitialValueTest`](repro/Workflow/EloquentInitialValueTest.php) · fix validated

The InMemory, File, Database and Redis backends let the initial value win when the related records also contain the condition key. `EloquentPersistence` creates the condition record and then saves every related record, so a same-key related record silently replaces the value just initialized, and later fenced writes or deletes that expect the initial value fail on this backend only. This affects only callers that use `PersistenceInterface` directly and put the condition key inside `$records`. Workflows run by the framework are not affected, because `WorkflowRunStore` never passes the control key in `$records`.

Suggested fix: in `src/Workflow/Persistence/EloquentPersistence.php::initializeIfAbsent()`, `unset($records[$conditionKey])` before the atomic block, then remove the `markTestSkipped` guard in `PersistenceContractTest`. This was validated in a sandbox against the repro and the module's tests.

### <a id="workflow-20"></a>WORKFLOW-20 · `FilePersistence` raises `WorkflowException` for write and delete failures instead of `PersistenceException`

**low** · api-inconsistency · [`src/Workflow/Persistence/FilePersistence.php:105`](../src/Workflow/Persistence/FilePersistence.php#L105) · repro [`FilePersistenceFailureExceptionTest`](repro/Workflow/FilePersistenceFailureExceptionTest.php) · fix validated

`FilePersistence` raises `PersistenceException` for read and corruption errors, but the parent `WorkflowException` when it fails to create the directory, write, rename or delete a partition. `RedisPersistence` raises `PersistenceException` for all backend failures, as `src/Workflow/AGENTS.md` documents. Applications that catch `PersistenceException` to retry or report storage outages (disk full, permissions, read-only filesystem) miss these `FilePersistence` failures, and switching between the File and Redis backends changes the exception type for the same outage. The exception still propagates, so nothing is lost silently.

Suggested fix: change the five `throw new WorkflowException(` calls in `src/Workflow/Persistence/FilePersistence.php` (lines 105, 178, 183, 189, 193) to `PersistenceException` and drop the unused import. Callers catching `WorkflowException` are unaffected because `PersistenceException` extends it. This was validated in a sandbox against the repro and the module's tests; the SQL backends letting `PDOException` through is a separate question.

### <a id="workflow-21"></a>WORKFLOW-21 · `FilePersistence` throws `TypeError` for numeric-string record or condition keys

**low** · bug · [`src/Workflow/Persistence/FilePersistence.php:161`](../src/Workflow/Persistence/FilePersistence.php#L161) · repro [`FilePersistenceNumericKeyTest`](repro/Workflow/FilePersistenceNumericKeyTest.php) · fix validated

PHP converts numeric-string array keys such as `'0'` or `'42'` to integers, and `FilePersistence::writePartition()` passes them to `base64_encode()`, which throws a `TypeError` under strict types. Every `initializeIfAbsent` and `writeIfUnchanged` involving such keys fails, while the InMemory, Database, Eloquent and Redis backends cast `(string) $key` and accept them. The failure is loud, with no partial write. The engine's own keys are never numeric, so this affects only direct users of `PersistenceInterface`, or a future key-format change that would break only the file backend.

Suggested fix: in `src/Workflow/Persistence/FilePersistence.php::writePartition()` (line 161), use `base64_encode((string) $key)`, matching the other backends, and leave the legacy-map check in `readFile()` unchanged. This was validated in a sandbox against the repro and the module's tests.

### <a id="workflow-22"></a>WORKFLOW-22 · `PhpSerializer` silently accepts persisted values followed by trailing bytes

**low** · bug · [`src/Workflow/Persistence/PhpSerializer.php:21`](../src/Workflow/Persistence/PhpSerializer.php#L21) · repro [`PhpSerializerTrailingBytesTest`](repro/Workflow/PhpSerializerTrailingBytesTest.php) · fix validated

`PhpSerializer::unserialize()` detects failure only when the result is `false`, and the `@` operator hides the warning PHP raises for data after a valid serialized value. A persisted record with appended bytes, from storage corruption, a bad manual edit or a faulty migration, is decoded silently as its valid prefix instead of being reported as corrupt, while truncated or garbage bytes are rejected. The decoded value is still type-checked by `WorkflowRunStore`, and there is no security exposure; the problem is a hidden data-integrity issue under the default serializer.

Suggested fix: in `src/Workflow/Persistence/PhpSerializer.php`, replace the `@` suppression with a scoped `set_error_handler()` that flags any `unserialize():` diagnostic and throw `PersistenceException` when one is raised. This was validated in a sandbox against the repro and the module's tests. PHP 8.1 and 8.2 give no signal for trailing data, so the check only works on PHP 8.3 and later.

### <a id="workflow-23"></a>WORKFLOW-23 · `PusherChannel` skips event-name validation when a large event is fragmented

**low** · api-inconsistency · [`src/Workflow/Streaming/Channel/PusherChannel.php:99`](../src/Workflow/Streaming/Channel/PusherChannel.php#L99) · repro [`PusherFragmentedEventNameTest`](repro/Workflow/PusherFragmentedEventNameTest.php) · fix validated

`PusherChannel::encode()` rejects empty, over-200-byte and `pusher:`-prefixed event types, but `AbstractChannel::fragments()` only calls `encode($type, ...)` when the envelope fits the event budget. Over the budget (10 KB, less on encrypted channels), each fragment is encoded as `stream.fragment` and the original type travels unchecked. The same invalid type from a custom stream adapter is therefore rejected when small and delivered when large, so an adapter bug only shows up for some events, and consumers receive reassembled events with types the channel promises to reject. The Pusher-level name is always `stream.fragment`, so Pusher's own protocol cannot be hijacked.

Suggested fix: in `src/Workflow/Streaming/Channel/AbstractChannel.php::fragments()`, always call `encode()` on the full envelope before checking `fits()`, so the transport validates the type regardless of size, at the cost of one extra encode for oversized events. This was validated in a sandbox against the repro and the module's tests.

### <a id="workflow-24"></a>WORKFLOW-24 · A `type` key in `ProtocolEvent` data replaces the event type in SSE frames

**low** · design · [`src/Workflow/Streaming/ProtocolEvent.php:29`](../src/Workflow/Streaming/ProtocolEvent.php#L29) · repro [`ProtocolEventTypeTest`](repro/Workflow/ProtocolEventTypeTest.php) · fix validated

`ProtocolEvent::jsonSerialize()` returns `['type' => $this->type, ...$this->data]`, so a `type` key in the data overrides the protocol event type in every SSE frame. The channel envelope keeps type and data separate, so the SSE and channel paths disagree about the type of the same event. A custom stream adapter or `mapEvent()` that spreads a tool output or other payload containing `type` into the data makes the frontend dispatch the event under the wrong type, potentially a terminal `finish` or `error`. The built-in `AgentChunkAdapter`, `AGUIAdapter` and `VercelAIAdapter` do not trigger this.

Suggested fix: in `src/Workflow/Streaming/ProtocolEvent.php`, return `['type' => $this->type] + $this->data`, so the discriminator wins and stays the first key. This was validated in a sandbox against the repro and the module's tests. Rejecting a `type` key in the constructor is the stricter alternative, but it breaks an existing `AbstractChannelTest` case and changes the contract.

### <a id="workflow-25"></a>WORKFLOW-25 · A channel's `completed()` handler can mutate the state returned by `run()`

**low** · bug · [`src/Workflow/Streaming/SegmentOutput.php:77`](../src/Workflow/Streaming/SegmentOutput.php#L77) · repro [`CompletedStateIsolationTest`](repro/Workflow/CompletedStateIsolationTest.php) · fix validated

`SegmentOutput::interrupted()` gives channels `clone $state`, but `completed()` passes the live state, and `Segment::execute()` clones the state only after the completion output has run. A custom `StreamingChannelInterface` or `CallbackChannel` whose completion handler modifies the state, for example to annotate or clean it, changes the `WorkflowState` returned by `run()` or `events()`. That returned state then differs from the outcome already committed to persistence, so a later reload returns different data. The built-in Redis and Pusher channels ignore the state and are not affected. A related, untested case: `Segment::settle()` passes the live state to `WorkflowInterrupted` listeners.

Suggested fix: in `src/Workflow/Streaming/SegmentOutput.php` line 77, pass `clone $state` to `completed()`, as `interrupted()` does. This was validated in a sandbox against the repro and the module's tests.

### <a id="workflow-26"></a>WORKFLOW-26 · `WorkflowState::get()` and `WorkflowResources::get()` return the default for a stored null

**low** · api-inconsistency · [`src/Workflow/WorkflowState.php:112`](../src/Workflow/WorkflowState.php#L112) · repro [`WorkflowStateNullValueTest`](repro/Workflow/WorkflowStateNullValueTest.php) · fix validated

Both `get()` methods are implemented as `$this->data[$key] ?? $default`, and `??` treats a key holding `null` the same as a missing key. A node or middleware that stores `null` on purpose and reads it back with a non-null default gets the default, while `has()` reports the key as present and `all()`/`only()` return the `null`. For example, `approved_by => null` after a rejection can read back as a default approver, and a node can take the wrong branch. Calls without a default are unaffected, and no framework code depends on this behaviour.

Suggested fix: in `src/Workflow/WorkflowState.php::get()` and `src/Workflow/WorkflowResources.php::get()`, return `array_key_exists($key, $this->data) ? $this->data[$key] : $default`. This was validated in a sandbox against the repro and the module's tests.

### <a id="workflow-27"></a>WORKFLOW-27 · Workflow ID validation accepts IDs ending in a line feed

**low** · bug · [`src/Workflow/WorkflowEngine.php:434`](../src/Workflow/WorkflowEngine.php#L434) · repro [`WorkflowIdTrailingNewlineTrustBoundarySecurityTest`](repro/Workflow/WorkflowIdTrailingNewlineTrustBoundarySecurityTest.php) · fix validated

`WorkflowEngine::store()` validates every workflow ID (the Agent's thread ID) with `/^(?!__)[^\x00-\x1F\x7F]{1,255}$/u`. Without the `D` modifier `$` also matches before a final newline, so `order-42\n` is accepted and persisted while a mid-string line feed is refused. A caller passing loosely normalised IDs (HTTP input, `fgets`) can create a run under an identity that differs from `order-42` only by an invisible byte, so retries or `inspect()` with the trimmed ID do not find it. A 255-character ID plus line feed also bypasses the length limit and fails later in the SQL backends with an unrelated `PersistenceException`, and the newline ends up in `WorkflowException` messages.

Suggested fix: add the `D` modifier to the regex in `WorkflowEngine::store()` in `src/Workflow/WorkflowEngine.php` (or use `\z`), and add a trailing line feed case to `WorkflowEngineTest::refusedWorkflowIds`. This was validated in a sandbox against the repro and the module's tests.

## <a id="module-chat"></a>Chat

Chat has 26 findings, centred on HistoryTrimmer, TokenCounter and the message stores. [CHAT-01](#chat-01) is the most serious: token counting opens user-supplied image URLs and paths, which enables SSRF and local file probing. A crafted image can also crash addMessage() ([CHAT-08](#chat-08), [CHAT-26](#chat-26)). Trimming misjudges the context window in several ways ([CHAT-03](#chat-03), [CHAT-04](#chat-04), [CHAT-25](#chat-25)), so histories can outgrow the provider's limit. Storage can lose or corrupt data: [CHAT-06](#chat-06) stores empty content when encoding fails, and [CHAT-09](#chat-09) makes a thread unloadable.

### <a id="chat-01"></a>CHAT-01 · TokenCounter opens user-supplied image URLs and paths while counting tokens

**high** · security · [`src/Chat/History/TokenCounter.php:118`](../src/Chat/History/TokenCounter.php#L118) · repro [`TokenCounterImageFetchTest`](repro/Chat/TokenCounterImageFetchTest.php) · fix validated

`TokenCounter::handleImageBlock()` passes any image content that is not decodable base64 to `@getimagesize()`, which goes through PHP stream wrappers, and it ignores the block's `SourceType`. Because `ChatHistory::addMessage()` and `calculateTotalUsage()` count tokens on every append, a user-supplied image URL makes the server fetch attacker-chosen hosts, including internal services and cloud metadata endpoints (blind SSRF), and a slow or large resource blocks the request each time. Local paths, including `SourceType::ID` images, are read from disk, and the resulting token count (256 for an existing 1x1 PNG versus 1 for a missing file) reveals whether a server-side file exists and its dimensions.

Suggested fix: in `src/Chat/History/TokenCounter.php`, make `handleImageBlock()` measure only `SourceType::BASE64` content with `getimagesizefromstring()`, never open URL or ID sources, and fall back to the fixed 200-token media estimate (a small `unmeasuredMediaChars()` helper, also used by the `count()` default arm) when the source is not inline or the data cannot be parsed. This also removes the `TypeError` on non-image base64 data described in `CHAT-08`, and it was validated in a sandbox against the repro and the module's tests.

### <a id="chat-02"></a>CHAT-02 · Trimming rebuilds kept messages' Usage and drops cached and reasoning token counts

**medium** · bug · [`src/Chat/History/HistoryTrimmer.php:104`](../src/Chat/History/HistoryTrimmer.php#L104) · repro [`TrimUsageRebaseTest`](repro/Chat/TrimUsageRebaseTest.php) · fix validated

When `HistoryTrimmer` drops the oldest turn, `normalizeCheckpoints()` replaces the `Usage` of every kept checkpoint with `new Usage(input - trimmed, output)`, so `cachedInputTokens` and `reasoningTokens` become 0. These are the caller's own `Message` instances, including the response just returned by the Agent, and because `ChatHistory::addMessage()` trims before `store->append()`, the new assistant message is persisted with both counts zeroed by the SQL, Eloquent and File stores. Once a history outgrows its window, billing, prompt-cache metrics, reasoning budgets and evaluation trajectories under-report cache hits and reasoning spend. The rebased `inputTokens` itself is how the current checkpoint design works, not part of this defect.

Suggested fix: in `src/Chat/History/HistoryTrimmer.php::normalizeCheckpoints()`, pass `$usage->cachedInputTokens` and `$usage->reasoningTokens` through to the new `Usage` alongside the rebased input and the output; this was validated in a sandbox against the repro and the module's tests. Rebasing clones instead breaks measurement across reloads; a follow-up worth discussing is to store the trimmer's context checkpoint separately from provider-reported `Usage`.

### <a id="chat-03"></a>CHAT-03 · HistoryTrimmer subtracts a kept tool call's checkpoint when the cut moves backward

**medium** · bug · [`src/Chat/History/HistoryTrimmer.php:230`](../src/Chat/History/HistoryTrimmer.php#L230) · repro [`TrimBackwardMiscountTest`](repro/Chat/TrimBackwardMiscountTest.php) · fix validated

In `adjustTrimIndex()`, the backward walk updates `backwardTokens` from every message it passes, but those messages, from the trim index back to the chosen `UserMessage`, are kept. When the first checkpoint past the overflow is a `ToolCallMessage`, the cut lands on its `ToolResultMessage` and moves back to the preceding user message, and the trimmed-token figure becomes the tool call's cumulative checkpoint. In the repro only 15 tokens are dropped but 70 are subtracted: `getTotalTokens()` reports 100 instead of 155 and kept usages are rebased by too much. This is common in agent tool loops; Summarization may not trigger and the provider can receive more than the configured window.

Suggested fix: in `src/Chat/History/HistoryTrimmer.php::adjustTrimIndex()`, stop accumulating tokens on the backward walk and, when a backward `UserMessage` is chosen, return the cumulative tokens of the last assistant checkpoint before that index (a small `tokensBefore()` helper). This was validated in a sandbox against the repro and the module's tests.

### <a id="chat-04"></a>CHAT-04 · Estimation-based trimming keeps a context larger than the window when the cut hits an assistant message

**medium** · bug · [`src/Chat/History/HistoryTrimmer.php:276`](../src/Chat/History/HistoryTrimmer.php#L276) · repro [`EstimatedTrimOverWindowTest`](repro/Chat/EstimatedTrimOverWindowTest.php) · fix validated

Without usage checkpoints, `findTrimIndexByEstimation()` returns the first index whose suffix fits the window. When that index is an assistant message, `adjustTrimIndex()` prefers the user message just before it, which is exactly the message that pushed the total over the window, even though the next user message is a valid cut that fits. With six messages of 12/13 estimated tokens and a 40-token window, 50 tokens are kept instead of 25, for every window from 38 to 49. Fresh histories, providers or streams without usage, and test fakes can therefore send up to one extra turn beyond the configured window, risking context-length errors or extra cost.

Suggested fix: in `src/Chat/History/HistoryTrimmer.php::findTrimIndexByEstimation()`, remember the oldest plain `UserMessage` whose suffix still fits while walking backward and return it on overflow, leaving the `adjustTrimIndex()` fallback for the case where the latest turn alone exceeds the window. This was validated in a sandbox against the repro and the module's tests.

### <a id="chat-05"></a>CHAT-05 · Documented MySQL schema for SQLMessageStore compares thread and message IDs case- and accent-insensitively

**medium** · security · [`src/Chat/History/SQLMessageStore.php:27`](../src/Chat/History/SQLMessageStore.php#L27) · repro [`SQLMessageStoreCollationTest`](repro/Chat/SQLMessageStoreCollationTest.php)

The table schema in the `SQLMessageStore` docblock is written for MySQL/MariaDB and declares `thread_id VARCHAR(255)` and `message_id VARCHAR(64)` without a collation, so they take the server default (`utf8mb4_0900_ai_ci`, `utf8mb4_general_ci` or similar), which is case- and accent-insensitive and sometimes ignores trailing spaces. Every query filters with `WHERE thread_id = :thread_id`, so IDs such as `user-Alice` and `user-alice` share rows: `loadActive`/`loadAll` return another conversation's messages, and `archive`/`clear` trim or delete it. Workflow persistence treats those IDs as separate runs, so two runs can write one merged history. SQLite and PostgreSQL are unaffected; the effect was reproduced with a SQLite `NOCASE` column, since no MySQL server was available.

Suggested fix: documentation only, in the `src/Chat/History/SQLMessageStore.php` docblock, declare `thread_id VARBINARY(255)` and `message_id VARBINARY(64)`; `VARBINARY` is preferred over `COLLATE utf8mb4_bin` because the latter is still PAD SPACE on MySQL 5.7/8 and MariaDB, and PDO keeps binding plain strings. Optionally document separate PostgreSQL/SQLite variants, as `DatabasePersistence` does.

### <a id="chat-06"></a>CHAT-06 · SQLMessageStore silently stores empty content and metadata when JSON encoding fails

**medium** · bug · [`src/Chat/History/SQLMessageStore.php:184`](../src/Chat/History/SQLMessageStore.php#L184) · repro [`SqlStoreEncodingTest`](repro/Chat/SqlStoreEncodingTest.php) · fix validated

`SQLMessageStore::serializeMessage()` calls `json_encode()` without `JSON_THROW_ON_ERROR`. For content or metadata containing invalid UTF-8 (binary tool output, text decoded with the wrong encoding, cut-off multibyte sequences), `json_encode()` returns `false`, PDO binds it as `''`, and the row is inserted anyway. On reload the message has `null` content and no metadata, with no error raised, so the loss goes unnoticed and cannot be recovered. `FileMessageStore` throws on the same input, so the outcome depends on which store is configured.

Suggested fix: in `src/Chat/History/SQLMessageStore.php::serializeMessage()`, pass `JSON_THROW_ON_ERROR` to both `json_encode()` calls; serialization happens before the `INSERT`, so nothing is written and a `JsonException` is thrown, as in `FileMessageStore`. This was validated in a sandbox against the repro and the module's tests.

### <a id="chat-07"></a>CHAT-07 · TokenCounter ignores tool call arguments, so a ToolCallMessage counts as about 3 tokens

**medium** · bug · [`src/Chat/History/TokenCounter.php:38`](../src/Chat/History/TokenCounter.php#L38) · repro [`TokenCounterToolCallTest`](repro/Chat/TokenCounterToolCallTest.php) · fix validated

`TokenCounter::count()` handles `ToolResultMessage` specially but counts a `ToolCallMessage` only by its role and content blocks, which are usually empty; tool names, call IDs and inputs are never counted. A tool call with 40,000 characters of arguments is estimated at 3 tokens. Most providers attach usage to the returned `ToolCallMessage`, so the common path is covered by checkpoints, but histories without usage (providers or streams that report none, hand-built or imported histories) and any tool call after the last checkpoint are underestimated. The trimmer then keeps a context that exceeds the model window and the provider rejects the next request.

Suggested fix: in `src/Chat/History/TokenCounter.php::count()`, add the length of each tool call's name, call ID and JSON-encoded inputs for `ToolCallMessage` instances through a small `handleToolCalls()` helper. This was validated in a sandbox against the repro and the module's tests.

### <a id="chat-08"></a>CHAT-08 · TokenCounter throws TypeError on base64 image content that is not a parseable image

**medium** · bug · [`src/Chat/History/TokenCounter.php:112`](../src/Chat/History/TokenCounter.php#L112) · repro [`TokenCounterInvalidImageTest`](repro/Chat/TokenCounterInvalidImageTest.php) · fix validated

When `handleImageBlock()` decodes base64 successfully but `getimagesizefromstring()` returns `false` (an SVG, a truncated or corrupt upload, a data URI with a non-image payload, or any text that happens to be valid base64), `$size[0]` is `null` and `calculateImageChars()` throws a `TypeError` under strict types, along with a PHP notice and warning. Because the trimmer counts tokens on every append, `ChatHistory::addMessage()` fails and the user's turn never reaches the provider. The message is not stored, so the thread remains usable, but any end user can trigger the crash with a malformed attachment.

Suggested fix: in `src/Chat/History/TokenCounter.php::handleImageBlock()`, suppress and check the result of `getimagesizefromstring()` and fall back to an estimate when it is `false`, as the URL/path branch already does. The rewrite proposed in `CHAT-01` covers this case too with a fixed media estimate; the minimal guard was validated in a sandbox against the repro and the module's tests.

### <a id="chat-09"></a>CHAT-09 · Media content blocks drop empty or '0' content on serialization, making the thread unloadable

**medium** · bug · [`src/Chat/Messages/ContentBlocks/FileContent.php:37`](../src/Chat/Messages/ContentBlocks/FileContent.php#L37) · repro [`EmptyMediaContentTest`](repro/Chat/EmptyMediaContentTest.php) · fix validated

`ImageContent`, `FileContent`, `AudioContent` and `VideoContent` build `toArray()` with `array_filter()` and no callback, so the required `content` key is removed when its value is `''` or `'0'`. A realistic trigger is a zero-byte upload, whose base64 is `''`. `MessageDeserializer` then passes `null` to the block constructor and throws a `TypeError`, and because this happens during deserialization, every later `loadActive()` or `loadAll()` on that thread fails in the File, SQL and Eloquent stores, not just the affected message. The conversation cannot resume until the stored row is repaired by hand.

Suggested fix: in `toArray()` of the four media blocks under `src/Chat/Messages/ContentBlocks/`, filter with `fn (mixed $value): bool => $value !== null && $value !== []`, which keeps falsy strings while preserving the current shape where empty `meta` is omitted. This was validated in a sandbox against the repro and the module's tests.

### <a id="chat-10"></a>CHAT-10 · MessageDeserializer rebuilds a SystemMessage as a plain Message

**medium** · bug · [`src/Chat/Messages/MessageDeserializer.php:60`](../src/Chat/Messages/MessageDeserializer.php#L60) · repro [`SystemMessageRoundTripTest`](repro/Chat/SystemMessageRoundTripTest.php) · fix validated

`MessageDeserializer::deserializeMessage()` maps only the assistant and user roles to their classes, so a serialized `SystemMessage` comes back as a base `Message`. It loses the `"\n\n"` text join, `cache()` and `contains()`. The larger effect is in evaluation: a `Trajectory` holding the instructions that crosses the parallel `EvaluatorRunner` fork boundary or the file evaluation cache renders the system prompt in `toTranscript()` as an `Assistant:` turn. LLM judges and the `UserSimulator` then see the assistant reciting its instructions, so results differ between serial and parallel runs. The core agent flow is unaffected because chat history normally holds no system messages.

Suggested fix: in `src/Chat/Messages/MessageDeserializer.php::deserializeMessage()`, add a `MessageRole::SYSTEM => new SystemMessage($content)` arm to the `match`. This was validated in a sandbox against the repro and the module's tests.

### <a id="chat-11"></a>CHAT-11 · MessageDeserializer alters legacy plain-text content that happens to be valid JSON

**medium** · bug · [`src/Chat/Messages/MessageDeserializer.php:183`](../src/Chat/Messages/MessageDeserializer.php#L183) · repro [`LegacyJsonLookingContentTest`](repro/Chat/LegacyJsonLookingContentTest.php) · fix validated

`deserializeContent()` runs `json_decode()` on every legacy string content and recurses on any truthy result. Plain text that is also valid JSON is therefore rewritten on load: `true` becomes `1`, `1e3` becomes `1000`, `"quoted"` loses its quotes, and `[1,2]` or `{"answer":42}` become the literal `Array` with an "Array to string conversion" warning. This affects applications reading chat histories or evaluation trajectories stored in the legacy flat shape; current-format messages are unaffected. The altered text is sent back to the model and shown in transcripts, and under error handlers that promote warnings to exceptions the thread fails to load.

Suggested fix: in `src/Chat/Messages/MessageDeserializer.php::deserializeContent()`, treat a legacy string as encoded content only when the decoded value is a list of blocks (`isset($json[0]['type'])`), otherwise keep it verbatim as `TextContent`. This was validated in a sandbox against the repro and the module's tests.

### <a id="chat-12"></a>CHAT-12 · FileMessageStore raises a raw Error instead of ChatHistoryException for malformed files

**low** · bug · [`src/Chat/History/FileMessageStore.php:147`](../src/Chat/History/FileMessageStore.php#L147) · repro [`FileStoreScalarEntryTest`](repro/Chat/FileStoreScalarEntryTest.php) · fix validated

`FileMessageStore::read()` only checks that the decoded root is an array. A file that decodes to a list of scalars (`[1]`, `["x"]`) or to a single message object then reaches `$entries[$index]['__id'] ??= ...` and throws an engine `Error` or `TypeError` instead of the documented "chat history file is corrupt" `ChatHistoryException`. This affects damaged or hand-edited thread files: `loadActive()`, `loadAll()`, `append()` and `archive()` bypass error handling written for `ChatHistoryException`, and an Agent run on that thread crashes. No data is lost; only the exception type is wrong.

Suggested fix: in `src/Chat/History/FileMessageStore.php::read()`, require the root to satisfy `array_is_list()` and every entry to be an array, throwing the existing corrupt-file `ChatHistoryException` otherwise. This was validated in a sandbox against the repro and the module's tests.

### <a id="chat-13"></a>CHAT-13 · FileMessageStore cannot store threads whose encoded ID exceeds the 255-byte file-name limit

**low** · design · [`src/Chat/History/FileMessageStore.php:201`](../src/Chat/History/FileMessageStore.php#L201) · repro [`FileMessageStoreLongThreadIdTest`](repro/Chat/FileMessageStoreLongThreadIdTest.php) · fix validated

`FileMessageStore` names each file `prefix + rawurlencode(threadId) + ext`. Each multibyte character becomes 9 bytes, so with the default prefix and extension, IDs of about 243 or more ASCII characters or about 27 or more CJK characters exceed the common 255-byte file-name limit. Every `append()` or `archive()` on such a thread throws `ChatHistoryException`, so an Agent cannot persist its history, while `loadActive()`, `loadAll()` and `clear()` silently act on nothing. The same IDs work in the SQL, Eloquent and in-memory stores, so switching to the file store breaks conversations that worked before. No data is silently lost.

Suggested fix: in `src/Chat/History/FileMessageStore.php::path()`, keep the encoded name when it fits and otherwise name the file `'+' . hash('sha256', $threadId)`, which cannot collide with an encoded name because `rawurlencode()` always escapes `+`; this was validated in a sandbox against the repro and the module's tests. `FilePersistence::path()` builds names the same way and likely needs the same change, though that was not reproduced.

### <a id="chat-14"></a>CHAT-14 · HistoryTrimmer checkpoint cache keyed on count and last-message object hash goes stale

**low** · bug · [`src/Chat/History/HistoryTrimmer.php:125`](../src/Chat/History/HistoryTrimmer.php#L125) · repro [`TrimmerCheckpointCacheTest`](repro/Chat/TrimmerCheckpointCacheTest.php) · fix validated

`HistoryTrimmer::getCheckpoints()` reuses cached checkpoints when the message count and the `spl_object_hash()` of the last message match. Usage set on that message after a trim is ignored (25 reported instead of 5010), and because object hashes are reused after garbage collection, a trimmer reused on a different array of the same length can return a freed message's checkpoints (1010 instead of 25). Standard Agent flows do not hit this, since providers set usage before messages reach the history; it affects user code that mutates usage or reuses a trimmer. The outcome is a wrong token total that can skew Summarization or trimming decisions.

Suggested fix: remove the checkpoint cache from `src/Chat/History/HistoryTrimmer.php` (the three cache properties, the `spl_object_hash` use in `trim()`, the cache logic in `getCheckpoints()` and the reset in `normalizeCheckpoints()`), since it saves only one linear scan. This was validated in a sandbox against the repro and the module's tests.

### <a id="chat-15"></a>CHAT-15 · getTotalTokens() still reports the pre-trim total after an estimation-based trim

**low** · bug · [`src/Chat/History/HistoryTrimmer.php:180`](../src/Chat/History/HistoryTrimmer.php#L180) · repro [`TrimmerEstimatedTotalTest`](repro/Chat/TrimmerEstimatedTotalTest.php) · fix validated

When a history has no usage checkpoints, `findTrimPoint()` calls `adjustTrimIndex($messages, $index, 0)`, so the trimmed-token figure is always 0 and `getTotalTokens()` keeps reporting the size before trimming (50 instead of 25 in the repro). Built-in `ChatHistory` and Summarization are unaffected because `calculateTotalUsage()` re-runs the trim before reading the total. Code that calls `HistoryTrimmer::trim()` directly and reads `getTotalTokens()`, such as a custom workflow node or budget check, sees an overstated context that can wrongly trigger summarization or suggest the window is still exceeded.

Suggested fix: in `src/Chat/History/HistoryTrimmer.php::findTrimPoint()`, in the no-checkpoint branch, return the adjusted index together with `estimateTokens(array_slice($messages, 0, $index))` for the dropped prefix. This was validated in a sandbox against the repro and the module's tests.

### <a id="chat-16"></a>CHAT-16 · validateAlternation() accepts a history that starts with a ToolCallMessage

**low** · bug · [`src/Chat/History/HistoryTrimmer.php:339`](../src/Chat/History/HistoryTrimmer.php#L339) · repro [`ToolCallFirstTest`](repro/Chat/ToolCallFirstTest.php) · fix validated

In `HistoryTrimmer::validateAlternation()`, the `ToolCallMessage` branch only checks the assistant role and continues before the expected-role check, so a history beginning with a tool call and its result passes validation, while the same turn as a plain `AssistantMessage` at position 0 is rejected. This contradicts the documented rule that a history must start with a user message. The normal Agent flow cannot produce this sequence, but imported, hand-built, migrated or corrupted histories and custom workflows are accepted and then fail later as a provider rejection rather than as the documented `ChatHistoryException`.

Suggested fix: in `src/Chat/History/HistoryTrimmer.php::validateAlternation()`, throw `ChatHistoryException` inside the `ToolCallMessage` branch when there is no previous message, without the broader `$expectingUser` check that would reject consecutive tool calls. This was validated in a sandbox against the repro and the module's tests; the `ToolApprovalFlowTest` fixture must seed a `UserMessage` and two of its expected histories need updating.

### <a id="chat-17"></a>CHAT-17 · InMemoryMessageStore::archive() with a negative count un-archives or hides messages

**low** · api-inconsistency · [`src/Chat/History/InMemoryMessageStore.php:53`](../src/Chat/History/InMemoryMessageStore.php#L53) · repro [`InMemoryNegativeArchiveTest`](repro/Chat/InMemoryNegativeArchiveTest.php) · fix validated

`InMemoryMessageStore::archive()` stores `min(count, archived + $count)` without a lower bound. A negative count lowers the archived prefix and makes archived messages active again, and a result below zero makes `loadActive()` call `array_slice()` with a negative offset, returning only the last messages and silently dropping the start of the conversation from the context. The SQL, Eloquent and File stores treat a non-positive count as a no-op, so behaviour depends on the backend. `ChatHistory` only archives positive counts, so only direct callers of the store API are affected, and `loadAll()` still returns everything.

Suggested fix: in `src/Chat/History/InMemoryMessageStore.php::archive()`, return early when `$count <= 0`, matching the other stores. This was validated in a sandbox against the repro and the module's tests.

### <a id="chat-18"></a>CHAT-18 · SQLMessageStore table-name guard accepts a trailing newline

**low** · security · [`src/Chat/History/SQLMessageStore.php:52`](../src/Chat/History/SQLMessageStore.php#L52) · repro [`TableNameNewlineTest`](repro/Chat/TableNameNewlineTest.php) · fix validated

The identifier check in the `SQLMessageStore` constructor uses `/^[a-zA-Z_]\w*$/`, and in PCRE `$` also matches before a final newline, so `"chat_messages\n"` passes and is interpolated into the SQL. This is not exploitable today because the newline is only whitespace in SQL, but the regex is the only guard for an interpolated identifier and does not match exactly what it claims to allow. Developers who build the table name from tenant or configuration input rely on it, and any future change to the guard would inherit the gap.

Suggested fix: in `src/Chat/History/SQLMessageStore.php`, add the `D` modifier (`'/^[a-zA-Z_]\w*$/D'`) or anchor with `\z`. This was validated in a sandbox against the repro and the module's tests.

### <a id="chat-19"></a>CHAT-19 · Negative loadAll() limit returns nothing, everything or an error depending on the store

**low** · api-inconsistency · [`src/Chat/History/SQLMessageStore.php:78`](../src/Chat/History/SQLMessageStore.php#L78) · repro [`NegativeLimitTest`](repro/Chat/NegativeLimitTest.php) · fix validated

`MessageStoreInterface::loadAll()` does not define negative limits, and the stores disagree. The in-memory and file stores (via `PaginatesMessages`) return no messages; `SQLMessageStore` interpolates `LIMIT -1`, which SQLite treats as unlimited and PostgreSQL rejects with a `PDOException`; `EloquentMessageStore` ignores the limit and returns the whole thread. Applications that pass an unvalidated page size from a UI or query string can pass tests on in-memory or SQLite stores and then crash on PostgreSQL, or return an unbounded transcript where a page was expected. There is no SQL injection risk because `$limit` is typed `?int`.

Suggested fix: clamp the limit with `max(0, $limit)` in `src/Chat/History/SQLMessageStore.php` and `src/Chat/History/EloquentMessageStore.php`, matching `PaginatesMessages`, and add the negative case to `MessageStoreContractTest`. This was validated in a sandbox against the repro and the module's tests.

### <a id="chat-20"></a>CHAT-20 · TokenCounter ignores extraTokensPerMessage and counts SystemContent as a fixed 200 tokens

**low** · correctness · [`src/Chat/History/TokenCounter.php:34`](../src/Chat/History/TokenCounter.php#L34) · repro [`TokenCounterConfigurationTest`](repro/Chat/TokenCounterConfigurationTest.php) · fix validated

The `TokenCounter` constructor accepts `$extraTokensPerMessage` but never uses it, so configuring per-message overhead has no effect and estimates stay low. `count()` matches blocks by exact class, so `SystemContent` falls into the fixed media fallback: a 4,000-character system message counts as 202 tokens instead of about 1,011. The comment on that fallback says 100 tokens while the code charges 200. The Agent keeps instructions outside chat history, so the standard flow is mostly unaffected; the undercount reaches code that uses `TokenCounter` directly or places system blocks in history.

Suggested fix: in `src/Chat/History/TokenCounter.php`, remove the unused `$extraTokensPerMessage` parameter, switch the `match` to `instanceof` checks so `SystemContent` (a `TextContent`) is counted as text, and correct the comment to 200 tokens. This was validated in a sandbox against the repro and the module's tests; actually applying the parameter would change every estimate and 23 existing expectations, so it needs a separate decision.

### <a id="chat-21"></a>CHAT-21 · TokenCounter throws TypeError on text blocks containing invalid UTF-8

**low** · bug · [`src/Chat/History/TokenCounter.php:91`](../src/Chat/History/TokenCounter.php#L91) · repro [`TokenCounterInvalidUtf8Test`](repro/Chat/TokenCounterInvalidUtf8Test.php) · fix validated

`TokenCounter::handleTextBlock()` returns `mb_strlen(json_encode($block->toArray()))`. For text or reasoning blocks with invalid UTF-8, such as a mis-encoded upload or a truncated multibyte sequence, `json_encode()` returns `false` and `mb_strlen()` throws a `TypeError` that says nothing about encoding. `ChatHistory::addMessage()` fails because it trims before storing; the message is not persisted, so the thread stays usable. Tool results are not affected because they are counted without `json_encode()`. Invalid UTF-8 would also fail later when the HTTP clients encode the request, but this is the first and least clear failure point.

Suggested fix: in `src/Chat/History/TokenCounter.php::handleTextBlock()`, pass `JSON_INVALID_UTF8_SUBSTITUTE` to `json_encode()`, so invalid bytes are counted as replacement characters. This was validated in a sandbox against the repro and the module's tests.

### <a id="chat-22"></a>CHAT-22 · Message::setContents() with an array appends to existing content instead of replacing it

**low** · bug · [`src/Chat/Messages/Message.php:118`](../src/Chat/Messages/Message.php#L118) · repro [`SetContentsReplaceTest`](repro/Chat/SetContentsReplaceTest.php) · fix validated

`Message::setContents()` replaces the content when given a string or a single block, but the array branch loops over `addContent()` without resetting `$this->contents`, so new blocks are appended to the old ones and `setContents([])` cannot clear a message. `SystemMessage` delegates to the same branch. No built-in flow hits this today, but middleware or custom nodes that rewrite a message with an array, for example to redact or sanitise content, leave the original blocks in place, so content meant to be replaced still reaches the provider and persisted history, and the prompt carries duplicated text.

Suggested fix: in `src/Chat/Messages/Message.php::setContents()`, set `$this->contents = []` at the start of the array branch. This was validated in a sandbox against the repro and the module's tests.

### <a id="chat-23"></a>CHAT-23 · MessageDeserializer docblock claims it accepts the raw jsonSerialize() array, which fails

**low** · docs-mismatch · [`src/Chat/Messages/MessageDeserializer.php:30`](../src/Chat/Messages/MessageDeserializer.php#L30) · repro [`DeserializeJsonSerializeOutputTest`](repro/Chat/DeserializeJsonSerializeOutputTest.php) · fix validated

The `MessageDeserializer` class docblock says it rebuilds a message from the array produced by `Message::jsonSerialize()`. That array holds `ContentBlockType` and `SourceType` enum instances and `Citation` objects, and passing it directly throws `TypeError: ContentBlockType::from(): Argument #1 ($value) must be of type string`. Only the JSON-decoded form works, which is what every store and `Trajectory` use, so no production flow is affected. The misleading docblock can send someone writing an in-process store or a test straight into this error.

Suggested fix: correct the class docblock in `src/Chat/Messages/MessageDeserializer.php` to say it rebuilds a message from the JSON-decoded form of `Message::jsonSerialize()`, as stores read it back. This was validated in a sandbox against the repro and the module's tests; making the deserializer accept enum and object values is not recommended because no caller needs it.

### <a id="chat-24"></a>CHAT-24 · An '__id' nested in stored '__meta' overrides the record's stored message identity

**low** · data-integrity · [`src/Chat/Messages/MessageDeserializer.php:268`](../src/Chat/Messages/MessageDeserializer.php#L268) · repro [`ForgedMetaIdentityTest`](repro/Chat/ForgedMetaIdentityTest.php) · fix validated

`MessageDeserializer::deserializeMeta()` first applies the stored `__id` (the `message_id` column) and then calls `setMetadata()`, which uses `array_replace()`, so an `__id` key inside `__meta` wins. The legacy flat shape filters `__id`, but the `__meta` shape does not. The framework never writes `__meta.__id`, so this needs a hand-edited, migrated, corrupted or externally supplied row, file entry or `Trajectory` payload. For such messages the loaded ID differs from the stored `message_id`: re-appending stores a duplicate row, `loadAll()` `before` cursors miss, and `ChatHistory` dedupes against the wrong ID.

Suggested fix: in `src/Chat/Messages/MessageDeserializer.php::deserializeMeta()`, apply `setId($message['__id'])` after `setMetadata()` so the stored identity always wins; unsetting `__id` in the `__meta` branch of `deserializeMetadata()` is an alternative. This was validated in a sandbox against the repro and the module's tests.

### <a id="chat-25"></a>CHAT-25 · Prompt caching disables history trimming for Anthropic because cached tokens are not counted

**medium** · bug · [`src/Chat/History/HistoryTrimmer.php:138`](../src/Chat/History/HistoryTrimmer.php#L138) · repro [`PromptCacheContextWindowContractTest`](repro/Chat/PromptCacheContextWindowContractTest.php) · fix validated

`HistoryTrimmer` uses each assistant message's `inputTokens + outputTokens` as the context size. OpenAI's prompt tokens include cached tokens, but the Anthropic mapping in `HandleChat` and `HandleStream` uses Anthropic's `input_tokens`, which excludes cache reads and writes. With prompt caching enabled via `SystemMessage::cache()`, the trimmer sees only the small uncached tail and never trims, so history grows every turn until Anthropic rejects the request as too long, and cost and latency rise meanwhile. In the repro the same token counts trim correctly for OpenAI but not for Anthropic. `calculateTotalUsage()` and observers also under-report prompt size. Bedrock uses the same pattern, confirmed only by reading the code.

Suggested fix: normalise `Usage.inputTokens` to the whole prompt in `src/Providers/Anthropic/HandleChat.php` and `HandleStream.php` by adding cache-read and cache-write tokens, and document `cachedInputTokens` as a subset of `inputTokens`. This was validated in a sandbox against the repro and the module's tests; tests pinning the old raw values in `AnthropicPromptCachingTest` and `ProviderHistoryReplayContractTest` need new expectations. Follow-ups: apply the same change to the AWS handlers and avoid double-counting cache writes.

### <a id="chat-26"></a>CHAT-26 · TokenCounter throws DivisionByZeroError for images declaring zero height, breaking ChatHistory::addMessage

**medium** · bug · [`src/Chat/History/TokenCounter.php:143`](../src/Chat/History/TokenCounter.php#L143) · repro [`ZeroDimensionImageTokenCountTest`](repro/Chat/ZeroDimensionImageTokenCountTest.php) · fix validated

`TokenCounter::calculateImageChars()` divides by the image height when computing aspect ratios. `getimagesizefromstring()` accepts a PNG or GIF header declaring a zero dimension (a 33-byte crafted PNG is enough) and returns a valid size array, so counting throws `DivisionByZeroError`. Since trimming counts tokens on every append, `ChatHistory::addMessage()` fails and nothing is stored; in an Agent this happens after the provider call was made and billed. The error is an `Error`, so handlers catching `Exception` miss it. The damage is limited to failing the uploader's current request. This is separate from the case where `getimagesizefromstring()` returns `false`.

Suggested fix: at the top of `calculateImageChars()` in `src/Chat/History/TokenCounter.php`, return only the base cost (`85 * charsPerToken`) when width or height is less than or equal to zero. This was validated in a sandbox against the repro and the module's tests.

## <a id="module-providers"></a>Providers

Providers has 46 findings, 7 of them high, and this is where features are broken outright. HuggingFace and AzureOpenAI cannot be constructed ([PROVIDERS-03](#providers-03), [PROVIDERS-06](#providers-06), [PROVIDERS-25](#providers-25)), ElevenLabs speech-to-text never works ([PROVIDERS-02](#providers-02)), and Cohere, Mistral and Ollama mishandle tool calls ([PROVIDERS-01](#providers-01), [PROVIDERS-04](#providers-04), [PROVIDERS-05](#providers-05)). The shared SSE parser drops any event containing "DONE" ([PROVIDERS-07](#providers-07)). Streams that fail partway through are returned as successes ([PROVIDERS-12](#providers-12)), and [PROVIDERS-28](#providers-28) leaks reasoning metadata from one request into the next. Many lower items reflect chat() and stream() drifting apart in usage, stop reasons and reasoning.

### <a id="providers-01"></a>PROVIDERS-01 · Cohere crashes on v2 tool-call responses and streaming keeps only the first tool call

**high** · bug · [`src/Providers/Cohere/HandleChat.php:25`](../src/Providers/Cohere/HandleChat.php#L25) · repro [`CohereToolCallsTest`](repro/Providers/CohereToolCallsTest.php) · fix validated

Cohere v2 tool-call responses carry `message.tool_plan` and `message.tool_calls` but no `message.content`. `HandleChat::processChatResult()` still passes the missing `content` key to `extractContent(array)`, so every non-streaming turn where the model calls a tool throws a `TypeError`, and the tool plan is never read. This makes the Cohere agent tool loop unusable in `chat()` and `structured()`. In streaming, `HandleStream` returns on the first `tool-call-end` event. When the model makes parallel calls, every call after the first is silently dropped, the model never gets results for them, and the `message-end` usage is never read.

Suggested fix: in `src/Providers/Cohere/HandleChat.php`, build the `ToolCallMessage` from `tool_calls` with `tool_plan` as its text, and default `content` to `[]`. In `src/Providers/Cohere/HandleStream.php`, remove the early return on `tool-call-end` and build the final message after the loop, once `message-end` has been read. This was validated in a sandbox against the repro and the module's tests, and `CohereTest::test_tool_call_answer_becomes_tool_call_message` should be updated to the real v2 shape.

### <a id="providers-02"></a>PROVIDERS-02 · ElevenLabsSpeechToText sends requests to a non-existent endpoint with invalid field and headers

**high** · bug · [`src/Providers/ElevenLabs/ElevenLabsSpeechToText.php:76`](../src/Providers/ElevenLabs/ElevenLabsSpeechToText.php#L76) · repro [`ElevenLabsSpeechToTextContractTest`](repro/Providers/ElevenLabsSpeechToTextContractTest.php) · fix validated

`ElevenLabsSpeechToText` appends OpenAI's `/audio/transcriptions` to `https://api.elevenlabs.io/v1/speech-to-text`, sends the model as `model` instead of the required `model_id`, and its default headers force `Content-Type: application/json` onto a multipart body, which then has no boundary. So no transcription can succeed against the real API, and every call surfaces as an `HttpException`. The provider also calls `fopen()` on the audio content whatever its `SourceType` is. With base64 audio this raises a PHP warning and sends the JSON body `{"file": false, ...}` instead of a clear `ProviderException`.

Suggested fix: in `src/Providers/ElevenLabs/ElevenLabsSpeechToText.php`, drop the JSON `Content-Type` header, post to `$this->baseUri` without a suffix, send `model_id`, and throw a `ProviderException` when the audio source is not `SourceType::URL`. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-03"></a>PROVIDERS-03 · HuggingFace provider throws in its constructor because `$baseUri` has no template

**high** · bug · [`src/Providers/HuggingFace/HuggingFace.php:17`](../src/Providers/HuggingFace/HuggingFace.php#L17) · repro [`HuggingFaceConstructionTest`](repro/Providers/HuggingFaceConstructionTest.php) · fix validated

`HuggingFace` redeclares `protected string $baseUri;` with no value, overriding the parent's default, and `buildBaseUri()` calls `sprintf()` on it in the constructor. Every `new HuggingFace(...)` therefore throws `Error: Typed property ... $baseUri must not be accessed before initialization`, whatever the inference provider, so the class cannot be used at all. No test constructs it. Two secondary defects would surface once construction works: URL paths are joined with `DIRECTORY_SEPARATOR`, which gives backslashes on Windows, and the nullable `?InferenceProvider` parameter would dereference `null->value`.

Suggested fix: in `src/Providers/HuggingFace/HuggingFace.php`, set `$baseUri` to `'https://router.huggingface.co/%s/v1'`, make `$inferenceProvider` a non-nullable `InferenceProvider` defaulting to `HF_INFERENCE`, and join URL segments in `buildBaseUri()` with `'/'`. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-04"></a>PROVIDERS-04 · Mistral loses chunked reasoning answers, crashes on null tool-call content, drops streamed tool calls

**high** · bug · [`src/Providers/Mistral/HandleChat.php:83`](../src/Providers/Mistral/HandleChat.php#L83) · repro [`MistralResponseParsingTest`](repro/Providers/MistralResponseParsingTest.php) · fix validated

The Mistral provider has three parsing defects. In non-streaming `chat()`, when `message.content` is a list of chunks, as Magistral reasoning models return it, `processChatResult()` iterates `$choice['content']` instead of `$choice['message']['content']`. It emits warnings and returns an empty `AssistantMessage`, so both the answer and the reasoning are lost. This hits every non-streaming Magistral response. A `tool_calls` response with `content: null` throws a `TypeError` when wrapped in `TextContent`. In streaming, if `finish_reason: "tool_calls"` arrives in a later chunk than the tool-call delta, the gathered calls are dropped and the tool loop ends silently. The last two are robustness gaps: Mistral usually sends `""` content and a same-chunk `finish_reason`, but its schema allows both shapes.

Suggested fix: in `src/Providers/Mistral/HandleChat.php`, iterate `$choice['message']['content']` and wrap the content in `TextContent` only when it is set. In `src/Providers/Mistral/HandleStream.php`, add `finishForToolCall()` and `toolCallResponse()` helpers so a `tool_calls` finish reason returns the gathered calls in whichever chunk it arrives. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-05"></a>PROVIDERS-05 · Ollama message mapper drops assistant tool calls and replays reasoning as visible text

**high** · bug · [`src/Providers/Ollama/MessageMapper.php:85`](../src/Providers/Ollama/MessageMapper.php#L85) · repro [`OllamaHistoryMappingTest`](repro/Providers/OllamaHistoryMappingTest.php) · fix validated

`MessageMapper::mapToolCall()` builds `tool_calls` only from a `tool_calls` metadata entry. `Ollama::createToolCallMessage()` never sets it, and messages from other providers or reloaded from storage never carry it. After the first tool round, the assistant turn is sent without its calls, so the following tool results refer to nothing, and tool-calling models can lose track of the loop. `mapMessage()` also joins every `TextContent`, and `ReasoningContent` extends it, so a thinking model's reasoning is replayed as part of its earlier answer (`'42Let me compute'`), garbling context. The existing `OllamaMessageMapperTest` hides the first bug by setting the metadata by hand.

Suggested fix: in `src/Providers/Ollama/MessageMapper.php`, rebuild `tool_calls` from `$message->getToolCalls()` and skip `ReasoningContent` blocks in `mapMessage()`, then drop the hand-set metadata from `OllamaMessageMapperTest`. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-06"></a>PROVIDERS-06 · AzureOpenAI throws in its constructor and builds its chat URL in the wrong order

**high** · bug · [`src/Providers/OpenAI/AzureOpenAI.php:16`](../src/Providers/OpenAI/AzureOpenAI.php#L16) · repro [`AzureOpenAIConstructionTest`](repro/Providers/AzureOpenAIConstructionTest.php) · fix validated

`AzureOpenAI` redeclares `protected string $baseUri;` without the `%s` template that `setBaseUrl()` passes to `sprintf()`. Every constructor call throws `Typed property ... $baseUri must not be accessed before initialization`, so chat, stream and structured output are all unusable. No test covers the class. Even with a template restored, `setBaseUrl()` appends `?api-version=...` to the base URI, and the inherited `createChatHttpRequest()` then adds `/chat/completions` after the query string. That produces a broken URL for every entry point. The separate authentication-header problem is covered in PROVIDERS-25.

Suggested fix: in `src/Providers/OpenAI/AzureOpenAI.php`, restore `$baseUri = 'https://%s/openai/deployments/%s'`, stop appending the API version in `setBaseUrl()` and make it protected, and override `createChatHttpRequest()` so it posts to `{baseUri}/chat/completions?api-version={version}`. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-07"></a>PROVIDERS-07 · SSE parsers drop any event whose JSON payload contains the substring `DONE`

**high** · bug · [`src/Providers/SSEParser.php:35`](../src/Providers/SSEParser.php#L35) · repro [`DoneSentinelTest`](repro/Providers/DoneSentinelTest.php) · fix validated

`SSEParser` and `OpenAIResponses::parseNextDataLine()` detect end of stream with `str_contains($line, 'DONE')` on the whole payload, not by comparing the data field to `[DONE]`. Any event whose text, reasoning or tool-argument fragment contains the uppercase substring `DONE` is returned as null and skipped. Streamed text silently loses words (`'Task is .'`). Tool arguments such as `{"status":"DONE"}` are cut to invalid JSON and decode to `[]`, so tools run with wrong parameters. This affects streaming for OpenAI and its compatible vendors, OpenAI Responses, Anthropic, Cohere, Mistral, OpenAI audio and image, and ZAI transcription. No error is raised.

Suggested fix: replace the check with `$line === '[DONE]'` in `src/Providers/SSEParser.php` and in `src/Providers/OpenAI/Responses/HandleStream.php`, and remove the unused `str_contains` imports. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-08"></a>PROVIDERS-08 · BedrockRuntime payloads fail AWS SDK validation without a system prompt or tool description

**medium** · bug · [`src/Providers/AWS/BedrockRuntime.php:66`](../src/Providers/AWS/BedrockRuntime.php#L66) · repro [`BedrockSdkValidationTest`](repro/Providers/BedrockSdkValidationTest.php) · fix validated

`BedrockRuntime::createPayLoad()` always sends `'system' => [['text' => $this->system]]`, even when no system prompt is set. The AWS SDK then rejects the null union value before any network call. The Bedrock `ToolMapper` also sends `'description' => null` for tools without a description, which the SDK rejects even though the field is optional. Chat, stream and structured modes all fail with `InvalidArgumentException`. The missing-system case is narrower than first reported, because an `Agent` always has default instructions. It hits direct provider use without a system prompt, `systemPrompt(null)`, and agents with empty instructions. The tool case hits any agent or provider that registers a tool without a description. Existing tests mock the client and so skip SDK validation.

Suggested fix: in `src/Providers/AWS/BedrockRuntime.php`, add `system` only when `$this->system !== null`. In `src/Providers/AWS/ToolMapper.php::mapTool()`, add `description` only when the tool has one. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-09"></a>PROVIDERS-09 · Bedrock document format uses the MIME subtype, which Converse rejects for common types

**medium** · bug · [`src/Providers/AWS/MessageMapper.php:256`](../src/Providers/AWS/MessageMapper.php#L256) · repro [`BedrockDocumentFormatTest`](repro/Providers/BedrockDocumentFormatTest.php) · fix validated

`MessageMapper::extractFormat()` returns whatever follows `/` in the media type. Converse documents only accept `pdf|csv|doc|docx|xls|xlsx|html|txt|md`, so text, Markdown, Word and Excel attachments are sent as `plain`, `markdown`, `msword` or `vnd.openxmlformats-...`, and Bedrock rejects the whole request. Four of these values come from the framework's own `MediaType` enum. The only workaround, putting `txt` or `docx` in `mediaType`, breaks other providers. PDF, CSV and HTML work. Separately, `mapMediaSource()` uses `base64_decode($content, true) ?: $content`, so a payload that decodes to `'0'` is sent still encoded. That edge case is negligible in practice.

Suggested fix: in `src/Providers/AWS/MessageMapper.php`, add an `extractDocumentFormat()` that maps the known document MIME types to the Converse values and falls back to `extractFormat()`, and use it in `mapFileBlock()`. Replace the `?:` decode with a strict `!== false` check. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-10"></a>PROVIDERS-10 · Citation extraction reads fields the Anthropic and OpenAI APIs do not send

**medium** · bug · [`src/Providers/Anthropic/Anthropic.php:177`](../src/Providers/Anthropic/Anthropic.php#L177) · repro [`CitationFindingsTest`](repro/Providers/CitationFindingsTest.php) · fix validated

`Anthropic::extractCitations()` reads `source`, `text`, `start_index` and `end_index`, but Anthropic citations carry `cited_text`, `url`, `document_title` and character indexes. Every citation gets a null `citedText`, and web-search citations get an empty `source`. `OpenAI\HandleChat::extractCitations()` only looks for annotations inside array content, while Chat Completions returns `message.annotations` with `url_citation`, so no citations are ever attached for OpenAI or the compatible providers that reuse it. `OpenAIResponses::extractCitations()` ignores `url_citation` and reads `file_citation` from a nested key instead of the flat shape. Apps that show sources from `getMetadata('citations')` get empty or missing data with no warning.

Suggested fix: map the documented shapes. In `src/Providers/Anthropic/Anthropic.php`, use `url`, `document_title` and `cited_text`, with the block span as indexes. In `src/Providers/OpenAI/HandleChat.php`, iterate `message.annotations` and handle `url_citation`. In `src/Providers/OpenAI/Responses/OpenAIResponses.php`, match the flat `url_citation` and `file_citation` shapes. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-11"></a>PROVIDERS-11 · AnthropicVertex fetches its OAuth token once in the constructor and never refreshes it

**medium** · design · [`src/Providers/Anthropic/AnthropicVertex.php:42`](../src/Providers/Anthropic/AnthropicVertex.php#L42) · repro [`AnthropicVertexTokenRefreshTest`](repro/Providers/AnthropicVertexTokenRefreshTest.php) · fix validated

`AnthropicVertex` fetches the Google access token during construction and stores it in the fixed `Authorization` header. Service-account tokens expire after about an hour. So in long-lived processes (queue workers, Octane, RoadRunner or Swoole servers, daemons, long agent runs), every Vertex request fails with 401 from then on, and the only fix is to build a new provider. Short-lived PHP-FPM requests are not affected. The constructor also does network I/O, so building an agent needs working credentials, and unit tests have to skip the constructor. `GeminiVertex` has the same pattern.

Suggested fix: add a protected `requestHeaders()` hook to `src/Providers/Anthropic/Anthropic.php` and use it in `HandleChat` and `HandleStream`. In `AnthropicVertex`, keep the credentials and override the hook to add a token from `accessToken()`, which refetches when `getLastReceivedToken()` is missing or within 60 seconds of `expires_at`. Then apply the same change to `GeminiVertex`. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-12"></a>PROVIDERS-12 · Mid-stream vendor error events are ignored and partial answers are returned as success

**medium** · bug · [`src/Providers/Anthropic/HandleStream.php:53`](../src/Providers/Anthropic/HandleStream.php#L53) · repro [`StreamErrorEventsTest`](repro/Providers/StreamErrorEventsTest.php) · fix validated

When a vendor aborts a stream after HTTP 200, for example with `overloaded_error`, the error is not surfaced. Anthropic's stream loop ignores the documented `error` event, OpenAI Chat Completions skips chunks without `choices` so an in-stream `{"error": ...}` is dropped, and OpenAI Responses handles `response.failed` but not `error`. In all three cases the truncated text comes back as a normal `ProviderResponse`. The agent saves it to chat history as a valid turn and shows it as the final answer, and retry or fallback logic that relies on `ProviderException` never runs. This affects Anthropic, OpenAI Responses, OpenAI and every class that inherits its stream processing.

Suggested fix: throw a `ProviderException` carrying the vendor message on an `error` event in `src/Providers/Anthropic/HandleStream.php`, on a chunk with an `error` key in `src/Providers/OpenAI/HandleStream.php::processStream()`, and on a `case 'error'` in `src/Providers/OpenAI/Responses/HandleStream.php`. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-13"></a>PROVIDERS-13 · Anthropic streaming drops a `partial_json` fragment equal to `"0"`, corrupting tool arguments

**medium** · bug · [`src/Providers/Anthropic/StreamState.php:71`](../src/Providers/Anthropic/StreamState.php#L71) · repro [`AnthropicPartialJsonZeroTest`](repro/Providers/AnthropicPartialJsonZeroTest.php) · fix validated

`StreamState::composeToolCalls()` appends a tool-input fragment only when `$line['delta']['partial_json'] ?? false` is truthy, and PHP treats the string `'0'` as falsy. Anthropic splits `partial_json` at arbitrary points, so a fragment that is exactly `0` is plausible for numeric arguments, though rare. When it happens, the result is either invalid JSON (`'{"count": }'`), which crashes the turn with a `TypeError` from `newToolCall()`, or valid JSON with a different value (`10` becomes `1`), and the tool silently runs with wrong arguments. The streamed `ToolArgumentChunk` events still show the correct text, so the UI and observability disagree with what was executed.

Suggested fix: in `src/Providers/Anthropic/StreamState.php::composeToolCalls()`, replace the truthy `elseif` with an `else` that appends `$line['delta']['partial_json'] ?? ''`. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-14"></a>PROVIDERS-14 · Malformed tool-call argument JSON causes a TypeError or runs tools with empty arguments

**medium** · bug · [`src/Providers/Anthropic/StreamState.php:80`](../src/Providers/Anthropic/StreamState.php#L80) · repro [`MalformedToolArgumentsTest`](repro/Providers/MalformedToolArgumentsTest.php) · fix validated

Invalid tool-argument JSON is handled inconsistently. In Anthropic streaming, `StreamState::getToolCalls()` decodes the accumulated input without checking it. A call cut off by `max_tokens` yields null and an uncaught `TypeError` from `newToolCall()`, which callers catching `ProviderException` or `Exception` cannot handle. `OpenAI::createToolCallMessage()` and `OpenAIResponses::createToolCallMessage()` use `json_decode(...) ?? []`, so malformed arguments silently become `[]` and the tool runs without the model's parameters, which can trigger a wrong side-effecting action. Arguments that decode to a scalar raise a `TypeError` there too. `Mistral.php` has the same `?? []` pattern.

Suggested fix: add a protected `decodeToolArguments()` helper to `src/Providers/HandleWithTools.php` that returns `[]` for empty input and throws `ProviderException` for anything that does not decode to an array. Use it in the OpenAI, OpenAIResponses and Anthropic `createToolCallMessage()` methods, and remove the `getToolCalls()` override from the Anthropic `StreamState`. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-15"></a>PROVIDERS-15 · ElevenLabsTextToSpeech ignores constructor `$parameters` and puts `voiceId` into the path unencoded

**medium** · bug · [`src/Providers/ElevenLabs/ElevenLabsTextToSpeech.php:81`](../src/Providers/ElevenLabs/ElevenLabsTextToSpeech.php#L81) · repro [`ElevenLabsTextToSpeechRequestTest`](repro/Providers/ElevenLabsTextToSpeechRequestTest.php) · fix validated

`ElevenLabsTextToSpeech` accepts `$parameters` (`voice_settings`, `language_code`, `seed` and others) but never sends them in `chat()` or `stream()`, so users silently get default voice settings. `ElevenLabsSpeechToText`, `ZAITranscription` and `OpenAISpeechToText` also ignore their `$parameters`. `voiceId` is also concatenated into the URL path without encoding. If an application passes an untrusted `voiceId`, a value like `'../../user?x='` redirects the authenticated POST, with the application's `xi-api-key`, to another ElevenLabs path or injects query parameters. The request still goes to `api.elevenlabs.io`, so the key never leaves ElevenLabs.

Suggested fix: in `src/Providers/ElevenLabs/ElevenLabsTextToSpeech.php`, spread `...$this->parameters` into the body in both `chat()` and `stream()`, and wrap `$this->voiceId` in `rawurlencode()`. The same `$parameters` merge should follow in the speech-to-text providers. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-16"></a>PROVIDERS-16 · Gemini chat parsing crashes when optional `parts`, `args` or `promptTokenCount` are missing

**medium** · bug · [`src/Providers/Gemini/HandleChat.php:107`](../src/Providers/Gemini/HandleChat.php#L107) · repro [`GeminiChatRobustnessTest`](repro/Providers/GeminiChatRobustnessTest.php) · fix validated

Gemini response parsing reads three optional fields without guards. `processChatResult()` iterates `$content['parts']` directly. Gemini 2.5 sometimes returns a STOP candidate with no parts or no content, which emits warnings, and apps that promote warnings to `ErrorException`, such as Laravel and Symfony, crash. `Gemini::createToolCallMessage()` passes `functionCall.args` straight to `newToolCall()`, so a call to a parameterless tool, where Gemini omits `args`, throws a `TypeError` in both chat and stream. `Usage` is built from `usageMetadata.promptTokenCount` with no default, so a response without it fails with a `TypeError`. This affects Gemini and GeminiVertex users.

Suggested fix: default the missing fields in `src/Providers/Gemini/HandleChat.php` (`$candidate['content'] ?? []`, `$content['parts'] ?? []`, `promptTokenCount ?? 0`) and in `src/Providers/Gemini/Gemini.php::createToolCallMessage()` (`args ?? []`). This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-17"></a>PROVIDERS-17 · Gemini stream crashes on elements without candidates and drops all parts after the first

**medium** · bug · [`src/Providers/Gemini/HandleStream.php:137`](../src/Providers/Gemini/HandleStream.php#L137) · repro [`GeminiStreamRobustnessTest`](repro/Providers/GeminiStreamRobustnessTest.php) · fix validated

`Gemini\HandleStream` reads `$line['candidates'][0]` without a guard. A stream element with no candidates, such as a usage-only element or a blocked prompt (`{"promptFeedback":{"blockReason":...}}`), causes warnings and then a `TypeError` from `array_key_exists()`. So a blocked prompt is not reported as a `ProviderException`, and its block reason is lost. The loop also reads only `parts[0]`, so when a chunk carries several parts, for example a reasoning part followed by the answer, or text followed by a generated image, every later part is silently lost from the chunks and the final message. This affects every Gemini and GeminiVertex user who streams.

Suggested fix: in `src/Providers/Gemini/HandleStream.php`, skip elements without a candidate, throw a `ProviderException` when `promptFeedback.blockReason` is set, and iterate every entry of `content.parts` for text, `inlineData` and `fileData`. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-18"></a>PROVIDERS-18 · Gemini stream framing is quadratic, so large inline data stalls the worker

**medium** · performance / resource-exhaustion · [`src/Providers/Gemini/HandleStream.php:217`](../src/Providers/Gemini/HandleStream.php#L217) · repro [`GeminiStreamPerformanceTest`](repro/Providers/GeminiStreamPerformanceTest.php) · fix validated

`Gemini\HandleStream::readLine()` reads the response one byte at a time and runs `json_decode()` on the whole buffer after each byte, so parse time grows with the square of each element's size. In the sandbox a 200 KB `inlineData` element took about 25 seconds, and the times doubled in size quadruple. Image-generation models return base64 images of several MB in one element, which in practice hangs the PHP worker, hits `max_execution_time` or blocks queue workers. This applies with every HTTP client because the framing sits above the stream. Normal text streaming with small elements is barely affected, the output stays correct, and `chat()` is not affected.

Suggested fix: replace the body of `readLine()` in `src/Providers/Gemini/HandleStream.php` with a single-pass framer that tracks brace depth and string and escape state, and returns when the outer object closes. After the fix, 2 MB parsed in about 0.45 seconds. This was validated in a sandbox against the repro and the module's tests. Switching to `?alt=sse` with `SSEParser` is a larger alternative.

### <a id="providers-19"></a>PROVIDERS-19 · Gemini, Cohere and ZAIImage modify the caller's message objects in place

**medium** · bug · [`src/Providers/Gemini/HandleStructured.php:59`](../src/Providers/Gemini/HandleStructured.php#L59) · repro [`CallerMessageMutationTest`](repro/Providers/CallerMessageMutationTest.php) · fix validated

Three providers change the message they are given instead of a copy. `Gemini::structured()`, for models in `$unsupportedModels` when tools are set, calls `setContents()` on the last user message. This adds the JSON schema and removes images and files, both from the request and from the stored history. `Cohere::structured()` appends `Generate a JSON` to the caller's last message, and an end-to-end agent run stores `'Who? Generate a JSON'` as the user's message in chat history. `ZAIImage::chat()`, and `OpenAIImage` with the same pattern, append the system prompt. The text stacks up when the same message object is reused. Within one structured-output run, each retry adds a new correction message, so it does not pile up there.

Suggested fix: add the extra text to a `clone` of the last message and put the clone in the request array. In `src/Providers/Gemini/HandleStructured.php`, add a separate `TextContent` block so non-text blocks are kept. Apply the same clone in `src/Providers/Cohere/Cohere.php`, `ZAIImage::chat()` and `OpenAIImage`, and update the expected wire format in `GeminiStructuredTest`. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-20"></a>PROVIDERS-20 · Gemini `functionDeclarations` becomes a JSON object when a provider tool comes first

**medium** · bug · [`src/Providers/Gemini/ToolMapper.php:30`](../src/Providers/Gemini/ToolMapper.php#L30) · repro [`GeminiFunctionDeclarationsListTest`](repro/Providers/GeminiFunctionDeclarationsListTest.php) · fix validated

`Gemini\ToolMapper::map()` filters function tools with `array_filter()`, which keeps the original keys, and then passes them through `array_map()`. When a provider tool such as `google_search` or `code_execution` comes before a function tool, or when the caller passes an associative tools array, the declarations are keyed from `1`. `json_encode` then emits `{"functionDeclarations":{"1":{...}}}` instead of a list. By Gemini's proto-JSON rules this request should be rejected with a 400, which has not been tested against the live API. Every chat and stream call from such an agent would fail, and the call only works when function tools happen to be listed first.

Suggested fix: in `src/Providers/Gemini/ToolMapper.php`, wrap the mapped declarations in `array_values()`. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-21"></a>PROVIDERS-21 · Mistral mapper sends `ReasoningContent` as one object instead of a list of chunks

**medium** · api-inconsistency · [`src/Providers/Mistral/MessageMapper.php:73`](../src/Providers/Mistral/MessageMapper.php#L73) · repro [`MistralReasoningMappingTest`](repro/Providers/MistralReasoningMappingTest.php) · fix validated

`Mistral\MessageMapper::mapContentBlock()` maps `ReasoningContent` to `['type' => 'thinking', 'thinking' => ['type' => 'text', 'text' => ...]]`, which serialises `thinking` as a JSON object. Mistral's ThinkChunk schema, and this provider's own response parsers, expect a list of chunks. When a conversation with a reasoning model such as `magistral-*` replays an assistant turn that holds reasoning, including tool-calling turns, the follow-up request is malformed. The API will likely reject it with a validation error or ignore the reasoning. This only affects multi-turn use of Mistral reasoning models, and nothing is lost or exposed.

Suggested fix: in `src/Providers/Mistral/MessageMapper.php::mapContentBlock()`, wrap the text chunk in a list: `'thinking' => [['type' => 'text', 'text' => $block->content]]`. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-22"></a>PROVIDERS-22 · Ollama stream drops `"0"` tokens, ignores error lines and loses tool-call usage

**medium** · bug · [`src/Providers/Ollama/HandleStream.php:83`](../src/Providers/Ollama/HandleStream.php#L83) · repro [`OllamaStreamParsingTest`](repro/Providers/OllamaStreamParsingTest.php) · fix validated

`Ollama\HandleStream` has three defects. `if ($content = $line['message']['content'] ?? null)` treats the token `"0"` as falsy, so it is neither yielded nor stored, and numbers like `10` become `1` in the UI, the chat history and any downstream parsing. Llama tokenizers often emit digits as separate tokens. `parseNextJson()` returns null for any line without `message`, so the `{"error": ...}` lines Ollama sends mid-generation are ignored, and a truncated answer is returned and stored as a success. The loop also returns at the first `tool_calls` line. The usage on the final `done` line is lost, so streamed tool-call turns report 0 tokens, and tool calls split across lines after the first are dropped.

Suggested fix: in `src/Providers/Ollama/HandleStream.php`, compare content with `!== ''`, throw a `ProviderException` in `parseNextJson()` when the line has an `error` key, and collect tool calls until the stream ends, then build the `ToolCallMessage` with the final usage. The early-return expectation in `OllamaStreamTest` needs a realistic empty `done` line. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-23"></a>PROVIDERS-23 · OpenAISpeechToText cannot transcribe base64 audio because it calls `fopen()` on it

**medium** · correctness · [`src/Providers/OpenAI/Audio/OpenAISpeechToText.php:77`](../src/Providers/OpenAI/Audio/OpenAISpeechToText.php#L77) · repro [`OpenAISpeechToTextBase64Test`](repro/Providers/OpenAISpeechToTextBase64Test.php) · fix validated

`OpenAISpeechToText::chat()` and `stream()` call `fopen()` on the audio content whatever its `SourceType` is. With base64 audio, such as browser or mobile recordings kept in memory, `fopen()` fails with a PHP warning. The request is then sent as JSON with `"file": false` and no audio, and OpenAI returns HTTP 400, so base64 transcription cannot work with this provider. The report also framed URL input as local file exfiltration or SSRF. That is overstated: opening a path is the intended API, and restricting untrusted paths belongs to the application. The file handle is never closed, and `ElevenLabsSpeechToText` has the same pattern.

Suggested fix: in `src/Providers/OpenAI/Audio/OpenAISpeechToText.php`, add a protected `audioFile()` that opens `SourceType::URL` content, decodes `SourceType::BASE64` into a `php://temp` resource with a filename extension taken from the media type, and throws `ProviderException` for other types. The contents must be a resource because the Guzzle and Amp clients only detect multipart for resources. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-24"></a>PROVIDERS-24 · OpenAITextToSpeech stream joins base64 chunks, corrupting the final audio

**medium** · bug · [`src/Providers/OpenAI/Audio/OpenAITextToSpeech.php:142`](../src/Providers/OpenAI/Audio/OpenAITextToSpeech.php#L142) · repro [`OpenAITextToSpeechStreamAudioTest`](repro/Providers/OpenAITextToSpeechStreamAudioTest.php) · fix validated

`OpenAITextToSpeech::stream()` builds the final audio by joining each `speech.audio.delta` string (`$content .= $line['audio']`), but each delta is base64-encoded on its own. Whenever a delta's byte length is not a multiple of 3, padding lands in the middle of the string. A strict decode then fails and a lenient decode gives wrong bytes. So the `AudioContent` in the final response is corrupt or cut short whenever it is saved, stored in history or played back. The `AudioChunk` values yielded during streaming are each valid and unaffected. The existing test misses this because its chunks happen to be 3-byte aligned.

Suggested fix: in `src/Providers/OpenAI/Audio/OpenAITextToSpeech.php::stream()`, gather `base64_decode($line['audio'])` into a byte buffer and `base64_encode()` it once for the final `AudioContent`, keeping the per-chunk yields as they are. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-25"></a>PROVIDERS-25 · AzureOpenAI sends resource keys as `Authorization: Bearer` instead of `api-key`

**medium** · bug · [`src/Providers/OpenAI/AzureOpenAI.php:31`](../src/Providers/OpenAI/AzureOpenAI.php#L31) · repro [`AzureOpenAIAuthenticationTest`](repro/Providers/AzureOpenAIAuthenticationTest.php) · fix validated

The `AzureOpenAI` constructor sets `'Authorization' => 'Bearer ' . $this->key`. According to Microsoft's documentation, Azure OpenAI resource keys go in an `api-key` header, and Bearer is only for Microsoft Entra ID access tokens. The provider cannot currently be constructed (PROVIDERS-06), so this causes no new outage today. But once that is fixed, every call made with a resource key would still be rejected with 401, which rests on the vendor documentation and cannot be proven offline. A sandbox repro that bypasses the constructor defect confirmed that the request carries `Authorization: Bearer` and no `api-key` header.

Suggested fix: in `src/Providers/OpenAI/AzureOpenAI.php`, replace the `Authorization` header with `'api-key' => $this->key`, together with the constructor and URL fix. Entra ID token support, if wanted, would be a separate option to discuss. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-26"></a>PROVIDERS-26 · OpenAI provider drops tool calls unless `finish_reason` is exactly `tool_calls`

**medium** · bug · [`src/Providers/OpenAI/HandleChat.php:61`](../src/Providers/OpenAI/HandleChat.php#L61) · repro [`OpenAIForcedToolChoiceTest`](repro/Providers/OpenAIForcedToolChoiceTest.php) · fix validated

`OpenAI\HandleChat::processChatResult()` and `HandleStream::finishForToolCall()` treat a response as a tool call only when `finish_reason === 'tool_calls'`. With a forced `tool_choice`, OpenAI and many compatible servers reached through `OpenAILike` (vLLM, Groq and others) return `finish_reason: 'stop'` together with `tool_calls`. The calls are then dropped and the agent gets an `AssistantMessage` with null content, so no tool runs and the loop ends early with no error, in both `chat()` and `stream()`. The default `tool_choice: 'auto'` works. Mistral has the same finish-reason-only check.

Suggested fix: in `src/Providers/OpenAI/HandleChat.php`, detect a tool call from a non-empty `message.tool_calls`. In `src/Providers/OpenAI/HandleStream.php::finishForToolCall()`, return true when any `finish_reason` is set and `$this->streamState->hasToolCalls()`. The raw finish reason is still recorded as the stop reason. This was validated in a sandbox against the repro and the module's tests, and the same change fits `src/Providers/Mistral/HandleChat.php` and `HandleStream.php`.

### <a id="providers-27"></a>PROVIDERS-27 · OpenAIImage writes the system prompt into the caller's message on every call

**medium** · bug · [`src/Providers/OpenAI/Image/OpenAIImage.php:83`](../src/Providers/OpenAI/Image/OpenAIImage.php#L83) · repro [`OpenAIImageSystemPromptTest`](repro/Providers/OpenAIImageSystemPromptTest.php) · fix validated

When a system prompt is set, `OpenAIImage::chat()` and `stream()` call `addContent(new TextContent($this->system))` on the last message they receive, which is the caller's own `Message` object. The instructions are therefore stored permanently in the user's message and in chat history, and every retry or reuse of the same message appends them again. In the repro, two `chat()` calls leave three text blocks in the message, and the second request sends `A red fox Watercolor style Watercolor style` instead of `A red fox Watercolor style`. This affects Agent usage, where instructions become the system prompt, as well as direct calls. It also exposes instructions meant to stay hidden in stored user content. `ZAIImage::chat()` has the same bug.

Suggested fix: Remove the `addContent()` block from both methods in `src/Providers/OpenAI/Image/OpenAIImage.php` and build the request `prompt` with a new protected `prompt(Message $message)` helper that returns the message text followed by the system prompt, without mutating the message. The text sent to the API stays the same. This was validated in a sandbox against the repro and the module's tests, and `ZAIImage` needs the same change.

### <a id="providers-28"></a>PROVIDERS-28 · Stream metadata from an earlier stream() leaks into later chat() answers

**medium** · security · [`src/Providers/OpenAI/OpenAI.php:126`](../src/Providers/OpenAI/OpenAI.php#L126) · repro [`DeepseekStreamStateLeakTest`](repro/Providers/DeepseekStreamStateLeakTest.php) · fix validated

`OpenAI::enrichMessage()` copies `$this->streamState` metadata into every message whenever the stream state is set, and that state is never reset after a stream finishes. If the same provider instance runs a `stream()` and later a `chat()` or `structured()`, the later answer inherits the earlier stream's accumulated metadata. For Deepseek this includes `reasoning_content`, which is then saved in the second conversation's history and sent back to the API by `Deepseek\MessageMapper` on the next turn. The stale value even overrides the new response's own reasoning, so the metadata and the `ReasoningContent` block disagree. All OpenAI subclasses are affected. The leak requires a reused provider instance (container singletons, long-running workers, or an agent that mixes streaming and non-streaming calls), but in that setup one conversation's reasoning can end up in another user's history.

Suggested fix: Remove the stream-state merge from `enrichMessage()` in `src/Providers/OpenAI/OpenAI.php`, and in `src/Providers/OpenAI/HandleStream.php` apply the stream metadata only on the stream path through a protected `applyStreamMetadata()` helper called at both `enrichMessage()` sites in `processStream()`. This was validated in a sandbox against the repro and the module's tests. Unsetting the state at the end of the stream was rejected because an abandoned generator would still leak.

### <a id="providers-29"></a>PROVIDERS-29 · OpenAI Responses chat() discards assistant text and reasoning that accompany function calls

**medium** · bug · [`src/Providers/OpenAI/Responses/HandleChat.php:72`](../src/Providers/OpenAI/Responses/HandleChat.php#L72) · repro [`ResponsesFunctionCallTextTest`](repro/Providers/ResponsesFunctionCallTextTest.php) · fix validated

When a Responses API output contains `function_call` items, `processChatResult()` builds the message with `createToolCallMessage($toolCalls)` and no content. Any `message` items (the assistant's visible preamble, such as "Let me check the weather.", and their citations) and `reasoning` summary items in the same response are thrown away. The returned `ToolCallMessage` and the stored chat history have no content blocks. On replay, the assistant turn goes back to the API without its text. The streaming path keeps these blocks, so the same agent behaves differently in chat and stream mode. This affects the default non-streaming Agent flow with `OpenAIResponses`.

Suggested fix: In `src/Providers/OpenAI/Responses/HandleChat.php`, pass `$this->createAssistantMessage($result)->getContentBlocks()` as the content of `createToolCallMessage()`. This was validated in a sandbox against the repro and the module's tests. Keeping the `citations` metadata on tool-call turns would be a separate follow-up.

### <a id="providers-30"></a>PROVIDERS-30 · OpenAI Responses drops generated images and concatenates streamed partial images

**medium** · bug · [`src/Providers/OpenAI/Responses/HandleStream.php:128`](../src/Providers/OpenAI/Responses/HandleStream.php#L128) · repro [`ResponsesImageFindingsTest`](repro/Providers/ResponsesImageFindingsTest.php) · fix validated

`createAssistantMessage()` reads only `message` and `reasoning` output items, so the result of an `image_generation_call` is silently dropped in `chat()`, and the returned message has no image even though the image was generated and billed. In `stream()`, each `partial_image_b64` (a complete lower-quality image) is appended to the same `ImageContent`, producing invalid base64. On `response.completed` without tool calls, the message is rebuilt from the final output, which drops the image again. When the same turn has a function call, the `ToolCallMessage` keeps the concatenated, corrupt image, which is saved to history and sent back on later turns. As a result, the built-in `image_generation` provider tool never delivers a usable image through this provider.

Suggested fix: Add a `createImageContent()` helper in `src/Providers/OpenAI/Responses/OpenAIResponses.php` and use it in `createAssistantMessage()` for `image_generation_call` items that have a `result`. In `src/Providers/OpenAI/Responses/HandleStream.php`, make each partial image replace the block instead of accumulating, and set the final image on `response.output_item.done`. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-31"></a>PROVIDERS-31 · OpenAI Responses stream drops any event containing "DONE" and reads byte by byte

**medium** · bug · [`src/Providers/OpenAI/Responses/HandleStream.php:198`](../src/Providers/OpenAI/Responses/HandleStream.php#L198) · repro [`OpenAIResponsesStreamReadingTest`](repro/Providers/OpenAIResponsesStreamReadingTest.php) · fix validated

`OpenAIResponses` has its own SSE parser (`parseNextDataLine()` and `readLine()` in `HandleStream`) that duplicates `SSEParser`'s defects. It returns null for any data line containing the substring `DONE`, so a delta such as `"Task DONE."` produces no `TextChunk`. The same happens to reasoning deltas, function-call arguments and item text. Model output is lost without an error, and tool arguments can arrive corrupted. The parser also builds each line with `read(1)` calls instead of `StreamInterface::readLine()`: about 10KB of text caused 10,232 stream calls. On `AmpStream`, each read copies the remaining buffer, so cost grows quadratically (0.84s versus 0.008s for a 262KB chunk). This affects everyone streaming through `OpenAIResponses` and its subclasses.

Suggested fix: In `src/Providers/OpenAI/Responses/HandleStream.php`, read with `$stream->readLine()`, strip `strlen('data:')`, compare the payload exactly to `[DONE]`, and delete the protected `readLine()` method. This was validated in a sandbox against the repro and the module's tests. The same exact-sentinel fix belongs in `SSEParser::parseNextSSEEvent()`, after which Responses could reuse one shared parser.

### <a id="providers-32"></a>PROVIDERS-32 · OpenAI Responses stream ignores response.incomplete, losing usage, stop reason and tool calls

**medium** · bug · [`src/Providers/OpenAI/Responses/HandleStream.php:135`](../src/Providers/OpenAI/Responses/HandleStream.php#L135) · repro [`ResponsesIncompleteStreamTest`](repro/Providers/ResponsesIncompleteStreamTest.php) · fix validated

When a streamed answer is cut off by `max_output_tokens` or a content filter, the Responses API ends with a terminal `response.incomplete` event that carries usage, output and status. `HandleStream` finalizes only on `response.completed`, so it falls through to the EOF fallback, which returns a plain `AssistantMessage` with no usage and no stop reason. Tool calls already collected in the stream state are also discarded, so the agent does not run them and ends the turn without saying why. Token and cost accounting undercounts, and truncation is undetectable in stream mode while `chat()` reports `incomplete` for the same response. Stream mode never sets a stop reason at all, even for completed responses.

Suggested fix: In `src/Providers/OpenAI/Responses/HandleStream.php`, handle `response.incomplete` in the same branch as `response.completed`, build either the tool-call or the assistant message, set id and usage on it, and call `setStopReason()` from `response.status` as `HandleChat` does. This was validated in a sandbox against the repro and the module's tests. One open question is whether tool calls from an incomplete response should run at all, since truncated arguments decode to `[]`. The fix keeps parity with `chat()`.

### <a id="providers-33"></a>PROVIDERS-33 · OpenAI Responses crashes with a TypeError on refusals and drops extra text parts

**medium** · bug · [`src/Providers/OpenAI/Responses/OpenAIResponses.php:181`](../src/Providers/OpenAI/Responses/OpenAIResponses.php#L181) · repro [`ResponsesRefusalTest`](repro/Providers/ResponsesRefusalTest.php) · fix validated

`createAssistantMessage()` reads only `content[0]` of a `message` item and assumes it is `output_text`. When the model refuses, the part is `{type: refusal, refusal: ...}`, so reading `text` triggers an undefined-key warning followed by `TypeError: ContentBlock::__construct(): Argument #1 ($content) must be of type string, null given`. The run fails with an uncaught PHP error instead of a readable message or a `ProviderException`, and the refusal reason is lost. This affects `chat()` and structured mode, which reuses the same code. When a message has several `output_text` parts, every part after the first is silently dropped along with its citations, so users get a truncated answer.

Suggested fix: In `src/Providers/OpenAI/Responses/OpenAIResponses.php`, make `createAssistantMessage()` loop over all content parts, map a `refusal` part to a `TextContent` and collect citations for each text part. This was validated in a sandbox against the repro and the module's tests. Handling `response.refusal.delta` in `HandleStream` is a related follow-up, because streamed refusals currently produce an empty message.

### <a id="providers-34"></a><a id="providers-35"></a>PROVIDERS-34 · AIProviderInterface::setTools() PHPDoc rejects provider tools that providers accept

**low** · docs-mismatch · [`src/Providers/AIProviderInterface.php:26`](../src/Providers/AIProviderInterface.php#L26) · repro [`ProviderToolSetToolsTypeTest`](repro/Providers/ProviderToolSetToolsTypeTest.php) · fix validated  
Also covers **PROVIDERS-35** (low, [`src/Providers/AIProviderInterface.php:26`](../src/Providers/AIProviderInterface.php#L26), repro [`GeminiProviderToolsTypingTest`](repro/Providers/GeminiProviderToolsTypingTest.php))

`AIProviderInterface::setTools()` documents `@param ToolInterface[] $tools`, but `HandleWithTools` stores `array<ToolInterface|ProviderToolInterface>` and the tool mappers (Gemini, ZAI and Anthropic among them) handle provider tools such as `web_search`, `google_search`, `mcp` and `code_execution`. Runtime behaviour is correct. However, PHPStan at level 5 reports a false `argument.type` error when a user calls `setTools()` directly with `ProviderTool` instances, and at level 7 and above the error also appears for union arrays such as `ToolRegistry::all()`. The Agent path does not trigger it at level 5. Users must add ignores or untyped wrappers, which can hide real type errors. The test suite already has such wrappers (`HandleWithToolsTest::registry()`, `GeminiTest::providerTools()`). Both reports describe this same defect.

Suggested fix: In `src/Providers/AIProviderInterface.php`, import `NeuronAI\Tools\ProviderToolInterface` and change the docblock to `@param array<ToolInterface|ProviderToolInterface> $tools`. This was validated in a sandbox against the repro and the module's tests. Afterwards the untyped test helpers can call `setTools()` directly.

### <a id="providers-36"></a>PROVIDERS-36 · Malformed or non-object tool-call argument JSON silently becomes empty inputs

**low** · robustness / input-validation · [`src/Providers/AWS/BedrockRuntime.php:91`](../src/Providers/AWS/BedrockRuntime.php#L91) · repro [`MalformedToolArgumentsTestPROVIDERS36`](repro/Providers/MalformedToolArgumentsTestPROVIDERS36.php) · fix validated

`BedrockRuntime::createTool()` (streaming path), `Mistral::createToolCallMessage()` and `OpenAI::createToolCallMessage()` (also used by ZAI, Cohere and other subclasses) decode tool arguments with `json_decode(...) ?? []`. If the model returns invalid JSON such as `{"q":"par` or `q=paris`, the tool call is built with empty inputs and no error. Tools whose parameters are all optional then run with defaults instead of the model's intent, for example an unfiltered search. Tools with required parameters fail with a misleading "Missing required parameter" error. A JSON scalar such as `"paris"` or `42` raises an uncaught `TypeError` instead of a framework exception. Truncated output rarely reaches this code because tool calls are gated on the finish reason, and non-streaming Bedrock is not affected.

Suggested fix: Add a protected `decodeToolArguments()` helper to `src/Providers/HandleWithTools.php` that decodes with `JSON_THROW_ON_ERROR` and throws `ProviderException` for malformed or non-object input, then use it in `Mistral.php`, `OpenAI.php` and `BedrockRuntime.php`. `src/Providers/AWS/HandleStream.php` also needs to build tools only in the `tool_use` branch so that max-token truncation is still discarded. This was validated in a sandbox against the repro and the module's tests. The same helper should also go into `OpenAIResponses` and Anthropic's `StreamState`, and reporting the error back to the model as a tool error is an alternative that needs a decision on the `ToolCall` API.

### <a id="providers-37"></a>PROVIDERS-37 · Bedrock, Ollama and Cohere streams never set the stop reason

**low** · api-inconsistency · [`src/Providers/AWS/HandleStream.php:63`](../src/Providers/AWS/HandleStream.php#L63) · repro [`StreamStopReasonTest`](repro/Providers/StreamStopReasonTest.php) · fix validated

`BedrockRuntime::stream()` reads `messageStop.stopReason` but never calls `setStopReason()`. Ollama's `HandleStream` ignores `done_reason` on the final line, and Cohere's `processStream` ignores `message-end.delta.finish_reason`. The `chat()` paths of all three providers set the stop reason, and so do the Gemini and Mistral streams. For these three streams, though, `stopReason()` is always null. User code or middleware that checks for `max_tokens`, `length` or `MAX_TOKENS` to detect truncation therefore does nothing in streaming mode, and truncated answers are treated as complete. No framework flow depends on the value, and the streamed text is still delivered.

Suggested fix: Store the reason while streaming and call `setStopReason()` on the final message in `src/Providers/AWS/HandleStream.php`, `src/Providers/Ollama/HandleStream.php` and `src/Providers/Cohere/HandleStream.php`. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-38"></a>PROVIDERS-38 · Anthropic and OpenAI streams omit the stop reason, and Anthropic reuses a stale one

**low** · bug · [`src/Providers/Anthropic/HandleStream.php:96`](../src/Providers/Anthropic/HandleStream.php#L96) · repro [`StreamStopReasonTestPROVIDERS38`](repro/Providers/StreamStopReasonTestPROVIDERS38.php) · fix validated

Anthropic's `stream()` sets the stop reason only on the plain-answer path, so tool-call streams return null instead of `tool_use`. The stop reason is also kept in a provider property that is never reset. When a stream ends without a `message_delta`, for example because of a dropped connection, the message reports the previous call's `end_turn`, so a truncated answer looks complete on a reused provider instance, which is the normal Agent setup. OpenAI's `processStream()` never reads `finish_reason`, so `length` and `tool_calls` are invisible in streaming for OpenAI and the subclasses that share it (Deepseek, Alibaba DashScope, HuggingFace, XAI, OpenAILike). `chat()` and the Gemini and Mistral streams are not affected.

Suggested fix: In `src/Providers/Anthropic/HandleStream.php`, reset `$this->stopReason` when the stream starts and set it on the tool-call message too, or keep it in the Anthropic `StreamState`. In `src/Providers/OpenAI/HandleStream.php::processStream()`, track `finish_reason` and set it on both the tool-call and final text messages. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-39"></a>PROVIDERS-39 · Anthropic stream double-counts output tokens from message_start

**low** · bug · [`src/Providers/Anthropic/HandleStream.php:125`](../src/Providers/Anthropic/HandleStream.php#L125) · repro [`AnthropicStreamUsageTest`](repro/Providers/AnthropicStreamUsageTest.php) · fix validated

`handleMessageStart()` adds `message.usage.output_tokens`, and `handleMessageDelta()` then calls `addOutputTokens()` with `message_delta.usage.output_tokens`. Anthropic documents that value as cumulative. Because `BasicStreamState::addOutputTokens()` uses `+=`, every streamed response overstates output tokens by the `message_start` count. In the repro the result is `16` instead of `15`. The error is usually a few tokens per response, but it skews cost tracking, token budgets, observability events and the usage stored in chat history for Anthropic and AnthropicVertex streaming. Non-streaming chat is not affected.

Suggested fix: In `src/Providers/Anthropic/HandleStream.php::handleMessageDelta()`, assign the cumulative `output_tokens` to `$this->streamState->getUsage()->outputTokens` instead of adding it. This was validated in a sandbox against the repro and the module's tests.

### <a id="providers-40"></a>PROVIDERS-40 · ElevenLabs providers' exceptions name "OpenAI Text to Speech"

**low** · docs-mismatch · [`src/Providers/ElevenLabs/ElevenLabsSpeechToText.php:92`](../src/Providers/ElevenLabs/ElevenLabsSpeechToText.php#L92) · repro [`ElevenLabsUnsupportedOperationsTest`](repro/Providers/ElevenLabsUnsupportedOperationsTest.php) · fix validated

The unsupported-operation exceptions in `ElevenLabsSpeechToText` (`stream`, `structured`, `messageMapper`, `toolPayloadMapper`) and `ElevenLabsTextToSpeech` (`structured`, `messageMapper`, `toolPayloadMapper`) were copied from the OpenAI providers and all say "not supported by OpenAI Text to Speech". A developer who calls one of these operations, for example by wiring the provider into an Agent, gets an error and log entries that name the wrong vendor. The speech-to-text class also names the wrong direction. The correct exception type is thrown, so functionality is unaffected, but debugging starts in the wrong place.

Suggested fix: Change the messages to "by ElevenLabs Speech to Text." in `src/Providers/ElevenLabs/ElevenLabsSpeechToText.php` and to "by ElevenLabs Text to Speech." in `src/Providers/ElevenLabs/ElevenLabsTextToSpeech.php`. This was validated in a sandbox against the repro and the module's tests. `OpenAISpeechToText` and `OpenAIImage` could get correct messages in a separate follow-up.

### <a id="providers-41"></a>PROVIDERS-41 · Gemini chat() drops usage for a MAX_TOKENS answer without parts

**low** · bug · [`src/Providers/Gemini/HandleChat.php:102`](../src/Providers/Gemini/HandleChat.php#L102) · repro [`GeminiTruncatedUsageTest`](repro/Providers/GeminiTruncatedUsageTest.php) · fix validated

`processChatResult()` returns early when the candidate has no `parts` and `finishReason` is `MAX_TOKENS`, which skips the `usageMetadata` block. This is the usual outcome when a thinking model such as gemini-2.5 spends its whole `maxOutputTokens` budget on thoughts. The billed prompt and thought tokens are missing from the message's `Usage` (the repro gets `[null, null]` instead of `[10, 1024]`). Agent usage totals, cost tracking and observability therefore under-report the most expensive truncated calls in `chat()` and structured mode, while the streaming path reports the same request correctly. The response and control flow are unaffected.

Suggested fix: In `src/Providers/Gemini/HandleChat.php`, remove the early return and iterate over `$content['parts'] ?? []` so the method always reaches the citations, usage and stop-reason code. This was validated in a sandbox against the repro and the module's tests. The existing empty-`MAX_TOKENS` test still passes.

### <a id="providers-42"></a>PROVIDERS-42 · Ollama chat() discards message.thinking that stream() keeps as reasoning

**low** · api-inconsistency · [`src/Providers/Ollama/HandleChat.php:72`](../src/Providers/Ollama/HandleChat.php#L72) · repro [`OllamaChatThinkingTest`](repro/Providers/OllamaChatThinkingTest.php) · fix validated

`processResponse()` in Ollama's `HandleChat` builds the message from `message.content` only. The `message.thinking` field, which reasoning models such as qwen3, deepseek-r1 and gpt-oss return with `think: true`, is discarded, so `getReasoning()` is null. Observability and chat history never see the reasoning. Reasoning that precedes a tool call is lost as well, because `createToolCallMessage()` receives only the content. `stream()` turns the same field into `ReasoningContent`, so the same app behaves differently depending on the transport. This affects Agent chat mode and structured output built on `chat()`.

Suggested fix: In `src/Providers/Ollama/HandleChat.php`, add a protected `contentBlocks()` helper that builds `TextContent` and `ReasoningContent` blocks from non-empty `content` and `thinking`, in the same order as Ollama's `StreamState`, and pass the result to both the tool-call and assistant branches. This was validated in a sandbox against the repro and the module's tests. One side effect is that an empty response now yields no blocks instead of one empty `TextContent`, which matches `stream()`.

### <a id="providers-43"></a>PROVIDERS-43 · SSEParser returns JSON scalars, causing a TypeError instead of skipping the line

**low** · bug · [`src/Providers/SSEParser.php:27`](../src/Providers/SSEParser.php#L27) · repro [`SSEParserScalarLineTest`](repro/Providers/SSEParserScalarLineTest.php) · fix validated

For lines that do not start with `data:`, the NDJSON fallback in `SSEParser::parseNextSSEEvent()` returns any truthy `json_decode` result. A non-conforming proxy or OpenAI-compatible server (for example through `OpenAILike` with a custom base URL) that sends a bare keep-alive such as `1`, `"ping"` or `true` makes the method return a scalar. That violates the `?array` return type and aborts the stream with an uncaught `TypeError`, which code catching `ProviderException` does not handle. A `data:` line holding a JSON scalar is caught inside the `try` block and rethrown as a misleading `ProviderException`, so it does not escape as a `TypeError`. It still fails the whole stream on a valid value. Official Anthropic and OpenAI streams are not affected.

Suggested fix: In `src/Providers/SSEParser.php`, return the decoded value only when `is_array()` holds and null otherwise, on both the NDJSON and `data:` paths. This was validated in a sandbox against the repro and the module's tests. One behaviour change is that a bare `{}` line now returns `[]` instead of null, and existing callers handle that safely.

### <a id="providers-44"></a>PROVIDERS-44 · SSE data: lines without the optional space lose their first character

**low** · bug · [`src/Providers/SSEParser.php:33`](../src/Providers/SSEParser.php#L33) · repro [`SSEDataFieldWithoutSpaceTest`](repro/Providers/SSEDataFieldWithoutSpaceTest.php) · fix validated

`SSEParser` accepts lines starting with `data:` but always strips `mb_strlen('data: ')`, which is 6 bytes. The SSE spec makes the space after the colon optional, so a server that sends `data:{"type":"ping"}` loses the opening `{`. The first event then throws `ProviderException: Streaming error - Syntax error` and the stream stops. `src/Providers/OpenAI/Responses/HandleStream.php` has the same defect. The main vendors send the space, so this affects only self-hosted or proxy OpenAI-compatible gateways. For those, streaming through every `SSEParser` client and the Responses stream breaks completely. Non-streaming calls are unaffected.

Suggested fix: Strip `strlen('data:')` instead and let the existing `trim()` remove the optional space, in `src/Providers/SSEParser.php` and `src/Providers/OpenAI/Responses/HandleStream.php`, and swap the `mb_strlen` imports for `strlen`. This was validated in a sandbox against the repro and the module's tests. The `str_contains($line, 'DONE')` check in both places should become an exact `[DONE]` comparison (see PROVIDERS-31).

### <a id="providers-45"></a>PROVIDERS-45 · ZAI stream() drops reasoning_content that chat() keeps

**low** · api-inconsistency · [`src/Providers/ZAI/ZAI.php:46`](../src/Providers/ZAI/ZAI.php#L46) · repro [`ZAIStreamReasoningTest`](repro/Providers/ZAIStreamReasoningTest.php) · fix validated

`ZAI` overrides `createAssistantMessage()` to keep `reasoning_content` in `chat()`, but it inherits OpenAI's `processContentDelta()`, which reads only `delta.content`. Deepseek overrides that method and ZAI does not. When streaming GLM thinking models (for example glm-4.5 or glm-4.6 with thinking enabled), including Agent stream mode, no `ReasoningChunk` is yielded, so a UI cannot show the thinking live. The final message has no `ReasoningContent`, so `getReasoning()` is null and history loses the reasoning. `ZAI\MessageMapper` therefore has nothing to send back as `reasoning_content`, which GLM uses to keep thinking across multi-turn tool calls. The same agent behaves differently in chat and stream mode.

Suggested fix: Override `processContentDelta()` in `src/Providers/ZAI/ZAI.php`, following Deepseek's approach. The override accumulates `delta.reasoning_content` into a `ReasoningContent` block at index `-1`, yields a `ReasoningChunk`, then delegates to the parent. This was validated in a sandbox against the repro and the module's tests. With the fix, streamed turns that end in tool calls also keep their reasoning.

### <a id="providers-46"></a>PROVIDERS-46 · OpenAI schema name for anonymous subclasses contains the absolute source path and exceeds 64 characters

**low** · bug · [`src/Providers/OpenAI/HandleStructured.php:60`](../src/Providers/OpenAI/HandleStructured.php#L60) · repro [`StructuredSchemaNameReproTest`](repro/Providers/StructuredSchemaNameReproTest.php) · fix validated

`sanitizeClassName()` in both OpenAI `HandleStructured` traits collapses only names starting with `class@anonymous`. An anonymous class that extends a parent is named `Parent@anonymous\0/abs/path/File.php:LINE$N`, which becomes a long name such as `stdClass_anonymous__tmp_..._php_26_bd`. That name, containing the server's absolute source path, is sent as `json_schema.name` (Chat Completions) or `text.format.name` (Responses) and is typically well over OpenAI's 64-character limit, so the request is rejected. This affects `OpenAI`, `OpenAIResponses` and providers built on them when structured output uses an anonymous subclass; plain `new class {}` and named DTOs are not affected.

Suggested fix: in `src/Providers/OpenAI/HandleStructured.php` and `src/Providers/OpenAI/Responses/HandleStructured.php`, replace the regex with `preg_replace(['/^class@anonymous.*$/s', '/@anonymous.*$/s'], ['anonymous', '_anonymous'], $name)`, so an anonymous subclass of `X` maps to `X_anonymous`. This was validated in a sandbox against the repro and the module's tests; truncating to 64 characters would additionally cover very long named classes.

## <a id="module-tools"></a>Tools

Tools has 59 findings, including the only critical one. The MySQL and PostgreSQL select tools are not read-only in practice: [TOOLS-01](#tools-01) and [TOOLS-04](#tools-04) to [TOOLS-08](#tools-08) show verified bypasses, and the read-only transaction in [TOOLS-08](#tools-08) is the robust fix. The next most important group covers model input that aborts the run instead of producing a correctable error ([TOOLS-02](#tools-02), [TOOLS-12](#tools-12), [TOOLS-13](#tools-13)), approval policies bypassed on nested fields ([TOOLS-10](#tools-10)), and BashTool hanging on large stderr ([TOOLS-03](#tools-03)). The remainder are mostly small correctness bugs in the Calendar, Calculator and FileSystem toolkits.

### <a id="tools-01"></a><a id="tools-09"></a>TOOLS-01 · Naive comment stripping lets write statements pass the read-only SQL check

**critical** · security · [`src/Tools/Toolkits/MySQL/MySQLSelectTool.php:130`](../src/Tools/Toolkits/MySQL/MySQLSelectTool.php#L130) · repro [`SelectToolCommentMarkerInLiteralTest`](repro/Tools/SelectToolCommentMarkerInLiteralTest.php) · fix validated  
Also covers **TOOLS-09** (high, [`src/Tools/Toolkits/PGSQL/PGSQLSelectTool.php:142`](../src/Tools/Toolkits/PGSQL/PGSQLSelectTool.php#L142), repro [`SelectToolCommentAsWhitespaceTest`](repro/Tools/SelectToolCommentAsWhitespaceTest.php))

`MySQLSelectTool::sanitizeQuery()` and `PGSQLSelectTool::removeComments()` strip `--` and `/* */` comments with regexes that ignore string literals and replace each comment with an empty string, while the database executes the original query. A literal such as `'--'` or `'/*'` hides everything after it from the keyword checks, and `DELETE/**/FROM` becomes `DELETEFROM`, which no guard matches. On Postgres, `WITH a AS (SELECT '--'), d AS (DELETE FROM users RETURNING 1) SELECT * FROM d` and `WITH d AS (DELETE/**/FROM users RETURNING 1) SELECT * FROM d` each deleted every row; on MySQL, `SELECT '--' AS a; DELETE FROM users` and `SELECT 1; DELETE/**/FROM users` pass and run through pdo_mysql's default multi-statement support. Any agent given the select tool as read-only access can be steered, including by prompt injection, into silent data loss limited only by the connection user's privileges.

Suggested fix: in both methods, replace the two `preg_replace` calls with a single `preg_replace_callback` that matches quoted literals first (per-dialect rules, including Postgres `E''` and dollar quoting) and keeps them intact, and replaces each comment with a space, using `PREG_UNMATCHED_AS_NULL`. This was validated in a sandbox against the repro and the module's tests; as defence in depth, running the query in a `READ ONLY` transaction and disabling MySQL multi-statements is worth discussing.

### <a id="tools-02"></a>TOOLS-02 · Non-object input for a class-mapped ObjectProperty escapes setInputs() and aborts the run

**high** · bug · [`src/Tools/ObjectProperty.php:267`](../src/Tools/ObjectProperty.php#L267) · repro [`MappedObjectWrongTypeTest`](repro/Tools/MappedObjectWrongTypeTest.php) · fix validated

`ObjectProperty::cast()` JSON-encodes whatever it receives and passes it to the Deserializer without checking that it is an array. When the model sends a string, number, bool or `null` for a class-mapped object, the Deserializer throws a `TypeError`; an invalid field value (such as a wrong enum case) throws a `DeserializerException`. `Tool::setInputs()` only catches `InvalidToolInput`, and `ToolNode` calls it in the approval gate before any error handling, so the whole agent run aborts instead of returning a correctable tool error. The `null` case means valid calls to a nullable mapped object, common with OpenAI strict mode, can never succeed.

Suggested fix: in `ObjectProperty::cast()`, return `null` unchanged, throw `InvalidToolInput('must be of type object, ... given')` for non-array input, and wrap the Deserializer call to convert `DeserializerException` into `InvalidToolInput`. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-03"></a>TOOLS-03 · BashTool deadlocks when a command writes more than a pipe buffer to stderr

**high** · bug · [`src/Tools/Toolkits/FileSystem/BashTool.php:72`](../src/Tools/Toolkits/FileSystem/BashTool.php#L72) · repro [`BashToolLargeStderrTest`](repro/Tools/BashToolLargeStderrTest.php) · fix validated

`BashTool` reads stdout to EOF with a blocking `stream_get_contents()` before it reads stderr at all. If the command writes more than the pipe buffer (about 64 KB on Linux) to stderr, the child blocks on the full stderr pipe and never exits, while the tool waits forever for stdout EOF. Failing test runs, compiler errors, linters and verbose package managers routinely produce that much stderr, so the agent run and its PHP worker hang until killed from outside, and the workflow cannot be interrupted or persisted. Separately, the tool has no timeout or output cap, so non-terminating or unbounded commands also hang it; these are design gaps rather than part of the verified defect.

Suggested fix: in `src/Tools/Toolkits/FileSystem/BashTool.php`, replace the two sequential reads with a protected `drain()` method that reads both pipes concurrently in a `stream_select` loop, then assemble stdout and stderr as today. This was validated in a sandbox against the repro and the module's tests; a timeout with `proc_terminate` and an output cap can follow separately.

### <a id="tools-04"></a>TOOLS-04 · MySQL read-only tool accepts stacked statements not on its forbidden list

**high** · security · [`src/Tools/Toolkits/MySQL/MySQLSelectTool.php:109`](../src/Tools/Toolkits/MySQL/MySQLSelectTool.php#L109) · repro [`MySQLSelectMultiStatementTest`](repro/Tools/MySQLSelectMultiStatementTest.php) · fix validated

`MySQLSelectTool::validateReadOnly()` checks only the first keyword and scans for a short forbidden list; it never rejects multiple statements. Because pdo_mysql emulates prepares with multi-statements enabled by default, queries such as `SELECT 1; GRANT ALL ...`, `SELECT 1; SET GLOBAL general_log = 1`, `SELECT 1; LOCK TABLES ...` or `SELECT 1; RENAME TABLE ...` (also `KILL`, `FLUSH`, `SHUTDOWN`, `DO`, `HANDLER`) reach `PDO::prepare()` and the server. Combined with the comment-stripping bypass, even listed statements such as `DROP TABLE` get through. An agent steered by prompt injection can escalate privileges, change server configuration, lock or kill sessions, or destroy data when the connection user has write privileges. `PGSQLSelectTool` validates each statement; the MySQL tool does not.

Suggested fix: in `validateReadOnly()`, reject the query when the raw input (before `sanitizeQuery()`) contains a `;` followed by more content, via a small protected `containsStatementSeparator()` check, still allowing one trailing `;`. This was validated in a sandbox against the repro and the module's tests; literals containing `;` followed by text are then rejected, which is acceptable because values go through placeholders.

### <a id="tools-05"></a>TOOLS-05 · MySQL executable comments are stripped before the forbidden-keyword scan but executed

**high** · security · [`src/Tools/Toolkits/MySQL/MySQLSelectTool.php:134`](../src/Tools/Toolkits/MySQL/MySQLSelectTool.php#L134) · repro [`MySQLSelectExecutableCommentTest`](repro/Tools/MySQLSelectExecutableCommentTest.php) · fix validated

MySQL executes the contents of `/*! ... */` and `/*!50000 ... */` comments (and MariaDB `/*M! ... */`), but `sanitizeQuery()` removes them before `validateReadOnly()` scans for forbidden keywords. `SELECT * FROM users /*!50000 INTO OUTFILE '/var/www/html/users.txt' */` is therefore accepted, as are `SELECT 1; /*!DROP TABLE users*/` and variants that rely on comment markers inside literals or MySQL's `1--1` arithmetic. Acceptance by the validator is proven with PDO mocks; execution on a real MySQL was not confirmed. Depending on the DB user's privileges, a steered model could write files on the server (including into a web root) or run destructive stacked statements through a tool presented as read-only.

Suggested fix: in `MySQLSelectTool::validateReadOnly()`, run the forbidden-keyword loop on the raw `$query` instead of the comment-stripped `$cleanQuery`, keeping `$cleanQuery` only for `getFirstKeyword()`. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-06"></a>TOOLS-06 · PGSQL read-only tool allows SELECT ... INTO, which creates and populates tables

**high** · security · [`src/Tools/Toolkits/PGSQL/PGSQLSelectTool.php:34`](../src/Tools/Toolkits/PGSQL/PGSQLSelectTool.php#L34) · repro [`PGSQLSelectIntoTest`](repro/Tools/PGSQLSelectIntoTest.php) · fix validated

In PostgreSQL, `SELECT * INTO new_table FROM users` is equivalent to `CREATE TABLE AS`. `PGSQLSelectTool` only forbids `INTO OUTFILE`, so the query passes every check, creates a new table and copies the data into it; the tool returns `[[]]`. The `INTO TABLE`, `INTO UNLOGGED` and post-CTE variants are accepted as well. When the database role has `CREATE` on the schema, which is common for application roles, a model steered by prompt injection can run DDL through a tool assumed to be read-only, copying readable data into tables other readers can see and consuming disk. It cannot modify or delete existing rows this way.

Suggested fix: in `$forbiddenPatterns` of `src/Tools/Toolkits/PGSQL/PGSQLSelectTool.php`, replace `'/\bINTO\s+OUTFILE\s+/i'` with `'/\bINTO\s+/i'`, matching the policy of `MySQLSelectTool`. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-07"></a>TOOLS-07 · PGSQL write-keyword patterns require trailing whitespace, so UPDATE"users"SET passes

**high** · security · [`src/Tools/Toolkits/PGSQL/PGSQLSelectTool.php:36`](../src/Tools/Toolkits/PGSQL/PGSQLSelectTool.php#L36) · repro [`PGSQLSelectKeywordWithoutWhitespaceTest`](repro/Tools/PGSQLSelectKeywordWithoutWhitespaceTest.php) · fix validated

Every forbidden pattern in `PGSQLSelectTool` ends in `\s+`, including the `SET` pattern, but PostgreSQL needs no whitespace between a keyword and a quoted identifier. `WITH x AS (UPDATE"users"SET"name"='pwned' RETURNING 1) SELECT * FROM x` matches nothing and ran against a real Postgres, updating the row. The same happens with unquoted identifiers when comments separate the keywords (`UPDATE/**/users/**/SET/**/name=...`), because `removeComments()` deletes comments without leaving a space. A model or injected content can therefore update or delete rows through a data-modifying CTE in the select tool, bypassing any approval flow placed on write tools.

Suggested fix: in `src/Tools/Toolkits/PGSQL/PGSQLSelectTool.php`, rewrite `$forbiddenPatterns` to match keywords on a word boundary followed by whitespace or `"` (`(?=[\s"])`), so functions like `replace(...)` stay allowed, and make `removeComments()` replace each comment with a space. This was validated in a sandbox against the repro and the module's tests; a read-only transaction is the more robust follow-up.

### <a id="tools-08"></a>TOOLS-08 · Text-based read-only checks let side-effecting SQL functions run through select tools

**high** · security · [`src/Tools/Toolkits/PGSQL/PGSQLSelectTool.php:120`](../src/Tools/Toolkits/PGSQL/PGSQLSelectTool.php#L120) · repro [`PGSQLSelectToolSideEffectsTest`](repro/Tools/PGSQLSelectToolSideEffectsTest.php) · fix validated

Both select tools decide read-only status from the SQL text alone, so a plain `SELECT` calling a function with side effects passes. Verified on Postgres: `SELECT purge_users()` deleted all rows, `SELECT setval('users_id_seq', 1000)` permanently moved a sequence, `SELECT set_config('search_path','pg_catalog',false)` changed the shared PDO session for the application's later queries, and `SELECT pg_read_file('/etc/hostname')` returned the file with a superuser role. The MySQL tool accepts similar calls such as `SLEEP()`. There is also no statement timeout or row limit. Any agent using these tools as read-only access can be driven to destroy data, corrupt sequences or alter connection state.

Suggested fix: in `PGSQLSelectTool::__invoke()`, run the statement inside a transaction started with `SET TRANSACTION READ ONLY` and always roll it back in `finally`, keeping the regex check as a first filter; `MySQLSelectTool` can do the same with `START TRANSACTION READ ONLY`. This was validated in a sandbox against the repro and the module's tests, after moving two SQLite-backed `PGSQLSelectToolTest` cases to the Postgres sandbox. A read-only transaction does not block `pg_read_file`, `lo_export` or `pg_sleep`, so the documentation should also require a least-privilege role and a statement timeout.

### <a id="tools-10"></a>TOOLS-10 · Class-less ObjectProperty never casts nested fields, allowing approval policies to be sidestepped

**medium** · security · [`src/Tools/ObjectProperty.php:267`](../src/Tools/ObjectProperty.php#L267) · repro [`NestedObjectCastingTest`](repro/Tools/NestedObjectCastingTest.php) · fix validated

When an `ObjectProperty` has no class, `cast()` returns the input unchanged: nested properties are not cast and a scalar is accepted where an object is declared. The documented guarantee that casting prevents a policy from being sidestepped by spelling `true` as `"true"` therefore holds only for top-level fields. A policy such as `$this->inputs['transfer']['international'] === true` is bypassed when the model sends `'true'`, so the gated action runs without human approval. Wrongly typed nested values and scalars sent for objects reach `__invoke()` instead of returning as correctable errors. This affects hand-written class-less objects, those built by `ToolPropertyFactory::fromSchema()` for MCP and `FrontendTool`, and object items in an `ArrayProperty`.

Suggested fix: in `ObjectProperty::cast()`, pass `null` through, reject non-array input with `InvalidToolInput`, deserialize when a class is set, and otherwise cast each declared nested property present in the input, prefixing nested errors with the field name. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-11"></a>TOOLS-11 · Non-encodable array results crash Tool::setResult() and are emptied by ToolCall::setResult()

**medium** · bug · [`src/Tools/Tool.php:238`](../src/Tools/Tool.php#L238) · repro [`UnencodableResultTest`](repro/Tools/UnencodableResultTest.php) · fix validated

`Tool::setResult()` assigns `json_encode($result)` directly to a typed `string|ToolOutput|null` property. For an array holding invalid UTF-8 (binary or BLOB columns, raw file bytes, Latin-1 data) or `INF`/`NAN` floats, `json_encode()` returns `false` and PHP throws `TypeError: Cannot assign false to property NeuronAI\Tools\Tool::$result`, which aborts the run unless a `toolErrorHandler` is set, after `__invoke()` has already succeeded. `ToolCall::setResult()` casts the same `false` to `''`, so the call silently settles with an empty result. Framework nodes never pass arrays to `ToolCall::setResult()`, so the silent case affects user code calling that public method.

Suggested fix: in both `src/Tools/Tool.php` and `src/Tools/ToolCall.php`, encode arrays with `JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR`, so invalid bytes become U+FFFD and other failures throw a descriptive `JsonException`. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-12"></a>TOOLS-12 · Omitted optional inputs are passed as null, defeating non-nullable __invoke defaults

**medium** · bug · [`src/Tools/Tool.php:365`](../src/Tools/Tool.php#L365) · repro [`OmittedOptionalInputTest`](repro/Tools/OmittedOptionalInputTest.php) · fix validated

`Tool::execute()` passes `$this->inputs[$name] ?? null` for every declared property. A tool author who writes `__invoke(string $query, int $limit = 10)` never gets the default: when the model omits `limit`, PHP receives `null` and throws a `TypeError`, which aborts the agent run unless a `toolErrorHandler` is configured. The shipped `ZepSearchGraphTool` is affected through `string $search_scope = 'facts'`, so a call with only `query`, valid under its own schema, fails. Models routinely omit optional fields, and the required `?type $x = null` pattern used by some built-in tools is not documented in `src/Tools/AGENTS.md`.

Suggested fix: in `Tool::execute()`, skip a missing input when the matching `__invoke()` parameter declares a default (found via a protected `ReflectionMethod`-based helper), and keep explicit nulls otherwise, which existing tests for nullable and variadic signatures rely on. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-13"></a>TOOLS-13 · Explicit null for a required non-nullable parameter aborts the run with a TypeError

**medium** · bug · [`src/Tools/ToolProperty.php:101`](../src/Tools/ToolProperty.php#L101) · repro [`NullRequiredInputTest`](repro/Tools/NullRequiredInputTest.php) · fix validated

`ToolProperty::cast()` passes `null` through for every property and nothing checks `isNullable()`. `Tool::execute()` treats the key as present, so the required-parameter check passes, and then calls `__invoke()` with `null` for a non-nullable typed parameter. The resulting `TypeError` (for example `FactorialTool::__invoke(): Argument #1 ($n) must be of type int, null given`) escapes and aborts the agent run instead of returning a `ToolOutput::error` the model could correct, contrary to the module contract in `src/Tools/AGENTS.md`. Built-in tools such as the Calculator tools and `write_todos` are affected, as are custom and MCP or frontend-derived tools; models do send explicit nulls, especially with strict tool calling.

Suggested fix: in `Tool::setInputs()`, inside the existing `try` block, throw `InvalidToolInput("must be of type ..., null given")` when the value is `null` and the property is required and not nullable, so the existing catch settles it as an invalid-input error without requiring approval. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-14"></a>TOOLS-14 · ToolPropertyFactory rejects numeric property names and throws TypeErrors on malformed schemas

**medium** · bug · [`src/Tools/ToolPropertyFactory.php:35`](../src/Tools/ToolPropertyFactory.php#L35) · repro [`SchemaFactoryRobustnessTest`](repro/Tools/SchemaFactoryRobustnessTest.php) · fix validated

`ToolPropertyFactory` converts external schemas from `FrontendTool` clients and MCP servers. Valid JSON property names such as `"1"` become integer array keys in PHP, so `createProperty(string $name)` throws a `TypeError` and the whole tool catalog for that client or server fails to load. Malformed values (non-string `type`, non-array `required`, `items` or `enum`, a string property definition, non-string `description`, non-int `minItems`/`maxItems`) each surface as a PHP `TypeError` rather than the documented `ToolException`, and a string `properties` value only emits a warning and registers a tool with no parameters. `PropertyType::fromSchema('unknown')` throws `ValueError` despite its docblock. Handlers built around `NeuronException` do not catch these errors.

Suggested fix: in `ToolPropertyFactory::fromSchema()`, cast names to string and validate each schema keyword through small protected helpers (`optionalArray()`, `optionalInt()`) plus string checks for `type` and `description`, throwing `ToolException` that names the property; in `PropertyType::fromSchema()`, use `tryFrom()` with a `ToolException` fallback. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-15"></a>TOOLS-15 · Calculator evaluate tool silently reads non-ASCII digits as zero or truncates them

**medium** · bug · [`src/Tools/Toolkits/Calculator/Expression.php:64`](../src/Tools/Toolkits/Calculator/Expression.php#L64) · repro [`UnicodeDigitsTest`](repro/Tools/UnicodeDigitsTest.php) · fix validated

`Expression::TOKEN_PATTERN` uses the `/u` modifier, which makes `\d` match any Unicode decimal digit (Arabic-Indic, Devanagari, fullwidth). `parseAtom()` then converts the token with `filter_var()` or a `(float)` cast, which reads those digits as 0 or stops at the first non-ASCII byte. As a result `'٣+1'` evaluates to `1`, `'３'` to `0`, and `'12٣4'` to `12`, with no error. Agents using `CalculatorToolkit`'s evaluate tool on numbers copied from Arabic, Hindi or CJK text receive a wrong result from a tool presented as deterministic and exact, and may pass it on to the user.

Suggested fix: in `src/Tools/Toolkits/Calculator/Expression.php`, replace `\d` with `[0-9]` in the number group of `TOKEN_PATTERN` while keeping `/u`, so such input raises an `ExpressionException` for the unexpected character. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-16"></a>TOOLS-16 · Oversized evaluate expressions exhaust memory and kill the PHP worker fatally

**medium** · security · [`src/Tools/Toolkits/Calculator/Expression.php:114`](../src/Tools/Toolkits/Calculator/Expression.php#L114) · repro [`ExpressionSizeLimitTest`](repro/Tools/ExpressionSizeLimitTest.php) · fix validated

`Expression::evaluate()` accepts model-supplied input with no length limit. `tokenize()` builds nested per-match arrays with `preg_match_all()` and copies them into `$this->tokens`, costing about 1.5 KB of memory per two source bytes. Measured on PHP 8.4 with `memory_limit=128M`, a flat `1+1+...` expression peaks at 121 MB at 80 KB and hits a fatal memory error at 100 KB; nested parentheses behave similarly. A memory fatal cannot be caught, so the worker dies mid-run: no `ToolOutput::error`, no `toolErrorHandler`, and no persisted step. Triggering it requires the model, possibly steered by injected content, to emit roughly 80-100 KB in a single argument; the impact is availability only.

Suggested fix: add a `MAX_LENGTH` constant (for example `10_000`) to `src/Tools/Toolkits/Calculator/Expression.php` and throw an `ExpressionException` in `evaluate()` before tokenizing when the input exceeds it; `EvaluateTool` already turns that into a tool error. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-17"></a>TOOLS-17 · Calendar tools declare NUMBER inputs but require int, so 2.5 or '02' aborts

**medium** · bug · [`src/Tools/Toolkits/Calendar/GetDaysInMonthTool.php:27`](../src/Tools/Toolkits/Calendar/GetDaysInMonthTool.php#L27) · repro [`NumberPropertyIntegerParameterTest`](repro/Tools/NumberPropertyIntegerParameterTest.php) · fix validated

`GetDaysInMonthTool` (`month`, `year`) and `IsLeapYearTool` (`year`) declare `PropertyType::NUMBER`, but their `__invoke()` parameters are typed `int`. The NUMBER cast produces a float for inputs such as `2.5`, `2024.5` or `"02"`, all allowed by the advertised `"type":"number"` schema, and the call in `Tool::execute()` then throws a `TypeError`. With the default `ToolNode` and no error handler the whole agent run aborts, instead of the model receiving the usual invalid-input error it could correct.

Suggested fix: change these properties to `PropertyType::INTEGER` in `src/Tools/Toolkits/Calendar/GetDaysInMonthTool.php` and `IsLeapYearTool.php`, so the schema says integer and bad values go through the normal invalid-input path. This was validated in a sandbox against the repro and the module's tests; note that `"02"` is then rejected as a correctable error rather than accepted, because the INTEGER cast refuses leading zeros.

### <a id="tools-18"></a>TOOLS-18 · GetTimezoneInfoTool formats negative fractional UTC offsets one hour too far west

**medium** · bug · [`src/Tools/Toolkits/Calendar/GetTimezoneInfoTool.php:64`](../src/Tools/Toolkits/Calendar/GetTimezoneInfoTool.php#L64) · repro [`TimezoneOffsetFormattingTest`](repro/Tools/TimezoneOffsetFormattingTest.php) · fix validated

`GetTimezoneInfoTool` builds `offset_formatted` with `sprintf('%+03d:%02d', floor($offsetHours), ...)`. For negative offsets that are not whole hours, `floor()` rounds away from zero, so America/St_Johns is reported as `-04:30` instead of `-03:30`, Pacific/Marquesas as `-10:30` instead of `-09:30`, and historical Africa/Monrovia (`-00:44`) as `-01:44`. Positive and whole-hour offsets are correct, which is why existing tests missed it. The formatted value contradicts `offset_seconds` and `offset_hours` in the same payload, and a model relying on it gives users wrong offsets for scheduling and time conversion.

Suggested fix: in `src/Tools/Toolkits/Calendar/GetTimezoneInfoTool.php`, format the offset from an explicit sign plus `intdiv(abs($offset), 3600)` and `intdiv(abs($offset) % 3600, 60)`, replacing the `floor` import with `intdiv`. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-19"></a>TOOLS-19 · GetWeekdayTool result depends on the server's default PHP timezone

**medium** · bug · [`src/Tools/Toolkits/Calendar/GetWeekdayTool.php:62`](../src/Tools/Toolkits/Calendar/GetWeekdayTool.php#L62) · repro [`GetWeekdayDefaultTimezoneTest`](repro/Tools/GetWeekdayDefaultTimezoneTest.php) · fix validated

Unlike the other Calendar tools, `GetWeekdayTool` parses the date with `new DateTime($date)`, which uses the process default timezone, and only then converts to the requested zone. The same call with timezone `UTC` therefore returns different weekdays depending on `php.ini` or `date_default_timezone_set()`: `'2024-01-15 23:00:00'` gives `Tuesday` under America/New_York and a Tokyo default shifts early-morning times back to `Sunday`. Laravel and Symfony apps configured with a local timezone are affected, and CI running in UTC never catches the off-by-one-day answers near midnight.

Suggested fix: in `src/Tools/Toolkits/Calendar/GetWeekdayTool.php`, parse with `new DateTime($date, new DateTimeZone('UTC'))` and keep the subsequent `setTimezone($tz)`, anchoring naive strings to UTC as the existing tests assume. This was validated in a sandbox against the repro and the module's tests; parsing directly in `$tz` like the sibling tools would change the tool's rule and break three existing tests, which is a design choice to decide separately.

### <a id="tools-20"></a>TOOLS-20 · Scoped DeleteFileTool deletes a symlink's target file instead of the link

**medium** · bug · [`src/Tools/Toolkits/FileSystem/DeleteFileTool.php:37`](../src/Tools/Toolkits/FileSystem/DeleteFileTool.php#L37) · repro [`DeleteFileToolSymlinkTest`](repro/Tools/DeleteFileToolSymlinkTest.php) · fix validated

With a scope configured, `resolve()` returns the `realpath()`-canonicalized path, which follows the final symlink, and `DeleteFileTool` calls `unlink()` on it. Deleting `alias.txt`, a link to `notes.txt` inside the scope, permanently deletes `notes.txt` and leaves a dangling link; without a scope the same call removes only the link. Enabling the security scope thus silently changes which file is destroyed. Data behind every alias pointing to that target is lost, and later reads through the dangling link fail with an access-denied error. Only files inside the scope are affected; links escaping the scope are still refused.

Suggested fix: in `src/Tools/Toolkits/FileSystem/DeleteFileTool.php`, keep `resolve($file_path)` for the containment and existence checks, but unlink `basename($file_path)` inside the resolved `dirname($file_path)`, so the named entry is removed. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-21"></a>TOOLS-21 · GlobPathTool treats non-leading '**' as a single level, returning incomplete results

**medium** · bug · [`src/Tools/Toolkits/FileSystem/GlobPathTool.php:58`](../src/Tools/Toolkits/FileSystem/GlobPathTool.php#L58) · repro [`GlobPathToolInnerGlobstarTest`](repro/Tools/GlobPathToolInnerGlobstarTest.php) · fix validated

`GlobPathTool` enables recursion only when the pattern starts with `**/`; otherwise it calls PHP `glob()`, where `**` behaves like `*`. So `src/**/*.php` matches only files exactly one directory below `src`, silently omitting files directly in `src` and anything deeper. In addition, `str_replace('**/', '', $pattern)` strips every `**/`, so `**/a/**/*.php` becomes a recursive `a/*.php`, and the output echoes the rewritten pattern. The model receives a listing that looks complete and may miss files it needs or wrongly conclude they do not exist. Scope enforcement is unaffected.

Suggested fix: in `src/Tools/Toolkits/FileSystem/GlobPathTool.php`, remove the prefix-stripping block and replace `globRecursive()` with a protected `globstar()` that expands each `**/` segment wherever it appears into the preceding directory plus all its subdirectories, and update the expected pattern text in `GlobPathToolTest::test_recursive_pattern_walks_every_level`. This was validated in a sandbox against the repro and the module's tests; rejecting non-leading `**` with a clear error is the simpler alternative.

### <a id="tools-22"></a>TOOLS-22 · GlobPathTool recursion follows symlinked directories without cycle detection

**medium** · security · [`src/Tools/Toolkits/FileSystem/GlobPathTool.php:106`](../src/Tools/Toolkits/FileSystem/GlobPathTool.php#L106) · repro [`GlobPathToolSymlinkLoopTest`](repro/Tools/GlobPathToolSymlinkLoopTest.php) · fix validated

`globRecursive()` recurses into every `is_dir()` entry, including symlinks, and never records which real directories it has visited. One link back to an ancestor inside the scope repeats every match until the kernel's symlink depth limit (41 matches for a single file in the repro), flooding the model's context and misrepresenting the file count. Two such links make the walk exponential and the call never returns, hanging the agent run until PHP's time or memory limit ends it. Anyone able to create a symlink in the workspace, including a cloned repository, an extracted archive or the agent via `BashTool`, can trigger it; the scope filter does not help because the loop stays inside the scope.

Suggested fix: in `src/Tools/Toolkits/FileSystem/GlobPathTool.php`, pass a by-reference set of visited `realpath()` values through `globRecursive()` and skip directories already walked, keeping symlink following for non-cyclic links. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-23"></a>TOOLS-23 · GrepFileContentTool reports wrong line numbers after multibyte characters

**medium** · bug · [`src/Tools/Toolkits/FileSystem/GrepFileContentTool.php:88`](../src/Tools/Toolkits/FileSystem/GrepFileContentTool.php#L88) · repro [`GrepFileContentToolMultibyteLineTest`](repro/Tools/GrepFileContentToolMultibyteLineTest.php) · fix validated

`GrepFileContentTool` gets byte offsets from `PREG_OFFSET_CAPTURE` but maps them to lines by summing `mb_strlen()` character lengths. Every multibyte character before a match shifts the result: for `"éééééé\nfoo\nbar"` the match on `foo` is reported on line 3 instead of line 2, and a match past the file's character total is reported as `line 0`. Files with accents, CJK, emoji or smart quotes are affected, with the error growing through the file. A model that uses these numbers to read or edit specific lines is sent to the wrong place, which can lead to wrong or destructive edits. ASCII-only files are unaffected.

Suggested fix: in `src/Tools/Toolkits/FileSystem/GrepFileContentTool.php`, compute the line as `substr_count($content, "\n", 0, $match[1]) + 1`, removing the character-based loop and its unused imports. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-24"></a>TOOLS-24 · WriteFileTool lets PHP warnings escape as exceptions under framework error handlers

**medium** · bug · [`src/Tools/Toolkits/FileSystem/WriteFileTool.php:60`](../src/Tools/Toolkits/FileSystem/WriteFileTool.php#L60) · repro [`FileSystemToolWarningsTest`](repro/Tools/FileSystemToolWarningsTest.php) · fix validated

`WriteFileTool` checks the return values of `mkdir()` and `file_put_contents()` but does not prevent the warnings they raise. Laravel, Symfony and similar frameworks convert warnings into `ErrorException`, so ordinary model mistakes such as writing to a path that is an existing directory, or below a path that is a file, abort the agent run instead of producing the intended `ToolOutput::error`. Without such a handler the code does reach the error result, but each mistake writes a PHP warning to the logs. The other FileSystem tools already pre-check, so the verified gap is limited to `WriteFileTool`.

Suggested fix: in `WriteFileTool::__invoke()`, return a `ToolOutput::error` when the target is a directory or the parent path exists as a file, and prefix the remaining `mkdir()` call with `@` to cover files higher in the path and the mkdir race. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-25"></a>TOOLS-25 · Jina and Tavily tools throw ToolException on invalid URLs instead of returning errors

**medium** · design · [`src/Tools/Toolkits/Jina/JinaUrlReader.php:49`](../src/Tools/Toolkits/Jina/JinaUrlReader.php#L49) · repro [`InvalidUrlReturnedToModelTest`](repro/Tools/InvalidUrlReturnedToModelTest.php) · fix validated

`JinaUrlReader`, `TavilyExtractTool` and `TavilyCrawlTool` throw `ToolException('Invalid URL.')` when the model passes a string that fails `FILTER_VALIDATE_URL`, such as `example.com/page` without a scheme. `Tool::execute()` does not catch it and `ToolNode` rethrows it unless a `toolErrorHandler` is set, so one malformed argument aborts the whole agent run instead of letting the model fix the URL on its next turn. This contradicts the contract in `src/Tools/AGENTS.md` and the behaviour of the FileSystem and Calculator toolkits. Separately, `FILTER_VALIDATE_URL` also accepts non-HTTP schemes such as `file://` and `ftp://`.

Suggested fix: in the three tools, return `ToolOutput::error('Invalid URL.')` instead of throwing, widen the `__invoke()` return types to include `ToolOutput`, and update the three `test_an_invalid_url_is_rejected_before_any_request` tests accordingly; optionally restrict schemes to `http` and `https`. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-26"></a>TOOLS-26 · MySQLSchemaTool index listing ignores the table allow-list and exposes hidden tables

**medium** · security · [`src/Tools/Toolkits/MySQL/MySQLSchemaTool.php:262`](../src/Tools/Toolkits/MySQL/MySQLSchemaTool.php#L262) · repro [`MySQLSchemaToolIndexFilterTest`](repro/Tools/MySQLSchemaToolIndexFilterTest.php) · fix validated

`getTables()`, `getRelationships()` and `getConstraints()` in `MySQLSchemaTool` filter by `$this->tables`, but `getIndexes()` queries `INFORMATION_SCHEMA.STATISTICS` for the whole database without that filter. With an allow-list of `['orders']`, the rendered "Available Indexes" section still lists other tables with non-primary indexes, their index names and indexed columns, for example `secret_payroll (salary)`. This metadata reaches the LLM context and possibly logs, traces and chat history, undermining the allow-list and helping a prompt-injection attack aim the select or write tools at hidden tables. No row data is exposed. `PGSQLSchemaTool` filters indexes correctly.

Suggested fix: in `MySQLSchemaTool::getIndexes()`, add the same `TABLE_NAME IN (...)` placeholder filter and bound parameters the sibling methods use when an allow-list is set. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-27"></a>TOOLS-27 · SQL write tools miss non-uppercase INSERTs and report stale last insert IDs

**medium** · bug · [`src/Tools/Toolkits/MySQL/MySQLWriteTool.php:85`](../src/Tools/Toolkits/MySQL/MySQLWriteTool.php#L85) · repro [`WriteToolInsertDetectionTest`](repro/Tools/WriteToolInsertDetectionTest.php) · fix validated

`MySQLWriteTool` and `PGSQLWriteTool` only detect an insert with `str_starts_with($query, 'INSERT')`, so `insert into ...`, a query with leading whitespace, or one preceded by a comment returns just `1 row(s) affected.` without the generated ID, forcing the model into an extra SELECT. Conversely, an INSERT that affects 0 rows (an `INSERT ... SELECT` with an empty source, `INSERT IGNORE` or `ON CONFLICT DO NOTHING` that skips) still reports the ID left over from an earlier insert on the connection, for example `0 row(s) affected. Last insert ID: 1`. On Postgres that value can come from another table's sequence, so the model may update or delete the wrong record.

Suggested fix: in both `MySQLWriteTool.php` and `PGSQLWriteTool.php`, report the ID only when `$rowCount > 0` and the query matches a case-insensitive `INSERT` keyword after optional whitespace and SQL comments (via `preg_match`), and in `PGSQLWriteTool` read the ID through the `lastInsertId()` helper described in TOOLS-28; this was validated in a sandbox against the repro and the module's tests. Because Postgres `LASTVAL()` is session-global, an insert into a sequence-less table can still report another table's ID, so dropping the heuristic in favour of `INSERT ... RETURNING` is worth discussing as a design change.

### <a id="tools-28"></a>TOOLS-28 · PGSQLWriteTool throws after a committed INSERT into a table without a sequence

**medium** · bug · [`src/Tools/Toolkits/PGSQL/PGSQLWriteTool.php:87`](../src/Tools/Toolkits/PGSQL/PGSQLWriteTool.php#L87) · repro [`PGSQLWriteToolInsertWithoutSequenceTest`](repro/Tools/PGSQLWriteToolInsertWithoutSequenceTest.php) · fix validated

For any query starting with `INSERT`, `PGSQLWriteTool` calls `$this->pdo->lastInsertId()` with no sequence name, which runs `SELECT LASTVAL()`. In a session that has not yet used a sequence, an INSERT into a table without a serial or identity column (natural, text or UUID keys, join tables, logs) makes that call throw `PDOException: SQLSTATE[55000] ... lastval is not yet defined in this session`. The row has already been committed in autocommit mode, but the exception escapes the tool and aborts the agent run; if a tool error handler converts it, the model sees a failure for a write that succeeded and may retry, duplicating the row. On connections that already used a sequence, the same inserts instead report a misleading ID from an unrelated sequence.

Suggested fix: in `PGSQLWriteTool.php`, route the lookup through a protected `lastInsertId(): string|false` helper that catches `PDOException` and returns `false`, so the tool reports the row count without an ID; this was validated in a sandbox against the repro and the module's tests. The stale-ID case remains, and replacing the `LASTVAL()` heuristic with `INSERT ... RETURNING` is the more correct design to discuss.

### <a id="tools-29"></a>TOOLS-29 · RetrievalTool includes each document's full embedding vector in the tool result

**medium** · design · [`src/Tools/Toolkits/RetrievalTool.php:38`](../src/Tools/Toolkits/RetrievalTool.php#L38) · repro [`RetrievalEmbeddingLeakTest`](repro/Tools/RetrievalEmbeddingLeakTest.php) · fix validated

`RetrievalTool::__invoke()` returns the retrieved `Document` objects, and the tool result is JSON-encoded through `Document::jsonSerialize()`, which includes `embedding`. Vector stores such as Memory, File, Qdrant, Pinecone and Meilisearch return documents with their embeddings, so every retrieval call puts dimension times topK floats into the conversation: with 1536 dimensions and `topK=4`, about 6,000 floats, or tens of KB of JSON. The model cannot use these values, yet they are resent to the provider on every later turn, billed as input tokens, can overflow smaller context windows, dilute the actual content, and are stored in chat history and workflow persistence.

Suggested fix: in `RetrievalTool.php`, map each document to an array with `content`, `sourceType`, `sourceName`, `score` and `metadata`, leaving out the embedding; this was validated in a sandbox against the repro and the module's tests. Changing `Document::jsonSerialize()` instead is not appropriate, because `FileVectorStore` persists documents through that same shape.

### <a id="tools-30"></a>TOOLS-30 · Supadata tools concatenate model input into query strings without URL encoding

**medium** · security · [`src/Tools/Toolkits/Supadata/SupadataVideoTranscriptTool.php:44`](../src/Tools/Toolkits/Supadata/SupadataVideoTranscriptTool.php#L44) · repro [`SupadataQueryEncodingTest`](repro/Tools/SupadataQueryEncodingTest.php) · fix validated

All four Supadata tools build their request by string concatenation, for example `'youtube/transcript?url='.$video_url.'&text=true'` and `'youtube/video?id='.$video`, with no encoding. An ordinary YouTube URL such as `...watch?v=dQw4w9WgXcQ&t=42` reaches the API as a truncated `url` plus a separate `t=42` parameter, and playlist URLs lose their `list` id the same way, so the wrong or an invalid resource is fetched. Model-controlled input, which prompt-injected content can steer, can also add Supadata parameters such as `lang=fr` to requests made with the application's API key, and a `#` silently drops the rest of the value. The host and headers cannot be changed and the key does not leak.

Suggested fix: build each query with `http_build_query` in `SupadataVideoMetadataTool.php`, `SupadataVideoTranscriptTool.php`, `SupadataYoutubeChannelTool.php` and `SupadataYoutubePlaylistTool.php`; this was validated in a sandbox against the repro and the module's tests. A slightly cleaner alternative is to have the `HttpClient` trait's `get()` accept a query array and encode it in one place.

### <a id="tools-31"></a>TOOLS-31 · TavilyExtractTool throws a TypeError when Tavily cannot extract the requested URL

**medium** · bug · [`src/Tools/Toolkits/Tavily/TavilyExtractTool.php:65`](../src/Tools/Toolkits/Tavily/TavilyExtractTool.php#L65) · repro [`TavilyExtractFailedUrlTest`](repro/Tools/TavilyExtractFailedUrlTest.php) · fix validated

`TavilyExtractTool::__invoke()` returns `$result['results'][0]` unconditionally. When Tavily reports the URL under `failed_results` (a routine outcome for 404, blocked, paywalled or timed-out pages) or the body is not JSON, PHP raises an `Undefined array key` warning followed by `TypeError: Return value must be of type array, null returned`. Without a custom tool error handler this aborts the agent run instead of letting the model recover, for example by trying another source, and Tavily's actual error message is never shown. `SupadataVideoTranscriptTool` and `ZepAddToGraphTool` index `$response['content']` the same way, and `TavilySearchTool` crashes on `$result['results']`, but only for malformed bodies.

Suggested fix: in `TavilyExtractTool.php`, widen the return type to `array|ToolOutput` and return `$result['results'][0] ?? ToolOutput::error(...)`, using `failed_results[0]['error']` when present; this was validated in a sandbox against the repro and the module's tests. The same guarded shape can be applied to the secondary cases in `TavilySearchTool`, `SupadataVideoTranscriptTool` and `ZepAddToGraphTool`.

### <a id="tools-32"></a>TOOLS-32 · TavilySearchTool limits every search to the last day unless time_range is set

**medium** · design · [`src/Tools/Toolkits/Tavily/TavilySearchTool.php:71`](../src/Tools/Toolkits/Tavily/TavilySearchTool.php#L71) · repro [`TavilySearchToolDefaultFiltersTest`](repro/Tools/TavilySearchToolDefaultFiltersTest.php) · fix validated

When the model omits `time_range`, `TavilySearchTool::__invoke()` defaults it to `day`, and it always sends `days=7` as well. Because models usually skip optional filters, most searches are silently restricted to content from the last 24 hours, so background or reference questions return few or irrelevant results and the agent may report that nothing was found or hallucinate. Developers cannot remove the filter through `withOptions()`, only replace it with another value that still restricts results. The existing `TavilySearchToolTest::test_posts_the_query_with_default_filters_and_options` pins the current behaviour.

Suggested fix: in `TavilySearchTool.php`, send only the filters that were provided by building the array with `array_filter`, keeping `topic` defaulting to `general` since that matches the API default, and update the expected body in the existing test; this was validated in a sandbox against the repro and the module's tests.

### <a id="tools-33"></a>TOOLS-33 · ZepSearchGraphTool throws a TypeError when the model omits the optional search_scope

**medium** · bug · [`src/Tools/Toolkits/Zep/ZepSearchGraphTool.php:69`](../src/Tools/Toolkits/Zep/ZepSearchGraphTool.php#L69) · repro [`ZepSearchDefaultScopeTest`](repro/Tools/ZepSearchDefaultScopeTest.php) · fix validated

`search_scope` is declared as an optional property, but `ZepSearchGraphTool::__invoke()` types it as a non-nullable `string $search_scope = 'facts'`. `Tool::execute()` passes every missing optional property as an explicit `null`, so a call with only `query`, which the schema allows, fails with `TypeError: Argument #2 ($search_scope) must be of type string, null given` before any HTTP request is made. The error escapes `ToolNode` and aborts the agent run unless a tool error handler is registered. It is limited to this tool, in a toolkit marked `@deprecated`.

Suggested fix: in `ZepSearchGraphTool.php`, declare `?string $search_scope = null` and apply `$search_scope ??= 'facts';` inside the method, matching the Calendar and Calculator tools; this was validated in a sandbox against the repro and the module's tests.

### <a id="tools-34"></a>TOOLS-34 · ArrayProperty omits maxItems: 0 from its schema, removing the upper bound

**low** · bug · [`src/Tools/ArrayProperty.php:71`](../src/Tools/ArrayProperty.php#L71) · repro [`ArrayMaxItemsZeroTest`](repro/Tools/ArrayMaxItemsZeroTest.php) · fix validated

`ArrayProperty::getJsonSchema()` skips `maxItems` when its value is `0`. Omitting `minItems: 0` is harmless because it is the JSON Schema default, but `maxItems: 0` is a real constraint meaning the array must be empty, and dropping it turns it into an unbounded array. This affects tools declared with `maxItems: 0` and schemas imported through `ToolPropertyFactory::fromSchema`, such as MCP and frontend tools, where the round trip loses the constraint. Since `ArrayProperty::cast()` does not enforce min or max, nothing catches elements the tool author meant to forbid. The case is rare.

Suggested fix: in `ArrayProperty::getJsonSchema()`, emit `maxItems` whenever it is not `null`, leaving the `minItems` branch unchanged; this was validated in a sandbox against the repro and the module's tests.

### <a id="tools-35"></a>TOOLS-35 · Tool::getResult() throws a TypeError instead of ToolException when never executed

**low** · api-inconsistency · [`src/Tools/Tool.php:228`](../src/Tools/Tool.php#L228) · repro [`ToolGetResultWithoutResultTest`](repro/Tools/ToolGetResultWithoutResultTest.php) · fix validated

`ToolCall::getResult()` guards the unexecuted state with a descriptive `ToolException`, but `Tool::getResult()` returns its `null` property under a non-nullable `string|ToolOutput` return type. Custom code that calls `getResult()` on a `Tool` that never ran, such as a pending, rejected or deferred call, gets an engine `TypeError` from inside the framework instead of the same domain error. The framework's own code always calls it after execution, so nothing breaks at runtime; the only effect is a worse error for API misuse and an inconsistency between the two sibling types.

Suggested fix: in `Tool.php`, throw `NeuronAI\Exceptions\ToolException` with a message saying the tool was never executed when `$this->result` is `null`, mirroring `ToolCall`; this was validated in a sandbox against the repro and the module's tests.

### <a id="tools-36"></a>TOOLS-36 · ToolRegistry constructor keeps duplicate tool names that add() would reject

**low** · design · [`src/Tools/ToolRegistry.php:19`](../src/Tools/ToolRegistry.php#L19) · repro [`ToolRegistryDuplicateNamesTest`](repro/Tools/ToolRegistryDuplicateNamesTest.php) · fix validated

`ToolRegistry::add()` applies first-wins deduplication by name, but the constructor stores its array as-is. `Agent::resources()` builds the registry with `new ToolRegistry($tools)` from the flattened toolkits and standalone tools, and `HandleTools::addTool`/`setTools` do not check for duplicates either. When two toolkits, or a toolkit and a standalone tool, expose the same name, both definitions reach the provider (verified end to end: the provider received `['lookup', 'lookup']`). Most provider APIs reject this with an HTTP 400 that is hard to trace, and if one accepts it, `find()` always runs the first tool even if the model chose the second.

Suggested fix: in `ToolRegistry.php`, drop constructor property promotion and pass each tool through `add()` so both entry points share the first-wins rule; this was validated in a sandbox against the repro and the module's tests. Throwing a `ToolException` on duplicate names at bootstrap is a louder alternative, but it would stop `add()` from being a silent no-op for middleware that registers on every node, so the choice is a design decision.

### <a id="tools-37"></a>TOOLS-37 · ToolRegistry::add() silently drops a second unnamed provider tool

**low** · bug · [`src/Tools/ToolRegistry.php:48`](../src/Tools/ToolRegistry.php#L48) · repro [`RegistryProviderToolsTest`](repro/Tools/RegistryProviderToolsTest.php) · fix validated

`ToolRegistry::add()` deduplicates by comparing `getName()` with `===`. Provider tools may be unnamed, so two different unnamed tools, for example `web_search` and `code_execution`, compare as `null === null` and the second is discarded without an exception or log entry, so the model is never offered it. No framework code currently calls `add()` with a `ProviderTool` (Agent registers them through the constructor, and `ToolSearchMiddleware` adds only function tools), so the bug affects user middleware or custom workflows that call `$resources->tools->add(new ProviderTool(...))` while an unnamed provider tool is already registered.

Suggested fix: in `ToolRegistry::add()`, treat a tool as a duplicate only if it is the same instance or it has a non-null name equal to a registered one; this was validated in a sandbox against the repro and the module's tests. Identifying unnamed provider tools by `getType()` is an alternative, but it would also collapse two separate instances of the same type, so the choice is a design decision.

### <a id="tools-38"></a>TOOLS-38 · SESTool validates only 'to', accepts an empty recipient list and ignores reply_to

**low** · input-validation · [`src/Tools/Toolkits/AWS/SESTool.php:87`](../src/Tools/Toolkits/AWS/SESTool.php#L87) · repro [`SESToolRecipientValidationTest`](repro/Tools/SESToolRecipientValidationTest.php) · fix validated

`SESTool::__invoke()` runs `validateRecipients()` on `$to` only, so malformed `cc` and `bcc` addresses go to SES unchecked and fail remotely with a vague error instead of a clear local one. An empty `to` also passes, because `minItems` is only a schema hint: with a non-empty `cc` or `bcc`, SES accepts the request and the email is sent without a primary recipient while the tool reports success, breaking the at-least-one-recipient contract. The `?string $reply_to` parameter is neither declared as a property nor used, so a supplied value is silently ignored. SES takes structured destination fields, so there is no header-injection path, and the tool is deprecated.

Suggested fix: in `SESTool.php`, remove the unused `$reply_to` parameter, throw a `ToolException` when `$to` is empty, and validate the combined `to`, `cc` and `bcc` lists before sending; this was validated in a sandbox against the repro and the module's tests. Whether this outbound, side-effecting tool should require approval through `approvalPolicy()` is a separate design question.

### <a id="tools-39"></a>TOOLS-39 · Calculator gcd, lcm and permutations throw ValueError at PHP_INT_MIN and PHP_INT_MAX

**low** · bug · [`src/Tools/Toolkits/Calculator/GcdTool.php:37`](../src/Tools/Toolkits/Calculator/GcdTool.php#L37) · repro [`IntegerLimitsTest`](repro/Tools/IntegerLimitsTest.php) · fix validated

`GcdTool` and `LcmTool` call `abs($number)`, which returns a float for `PHP_INT_MIN`; its string form `9.2233720368548E+18` is rejected by `bcmod()` with a `ValueError`. In `PermutationsTool`, when `n = PHP_INT_MAX` the loop counter `$factor++` overflows to a float that still compares `<=` n, and the next `bcmul()` throws the same error. All three inputs pass the integer cast, so a model-supplied value escapes `__invoke()` and aborts the agent run unless a tool error handler is installed. The inputs are unusual, but a user or prompt injection can request them deliberately to crash a single run; no data is lost or exposed.

Suggested fix: in `GcdTool.php` and `LcmTool.php`, replace `(string) abs($number)` with `ltrim((string) $number, '-')`, and in `PermutationsTool.php` loop over the number of terms (`$i < $k`, multiplying by `$n - $i`) so the factor never steps past n; this was validated in a sandbox against the repro and the module's tests.

### <a id="tools-40"></a>TOOLS-40 · Calculator statistics tools return 'INF' as a successful result on overflow

**low** · bug · [`src/Tools/Toolkits/Calculator/Number.php:22`](../src/Tools/Toolkits/Calculator/Number.php#L22) · repro [`StatisticOverflowTest`](repro/Tools/StatisticOverflowTest.php) · fix validated

`EvaluateTool` checks every operation for non-finite values and reports overflow as a tool error, but `mean`, `median`, `variance` and `standard_deviation` do not. Their intermediate sums or squared deviations overflow to `INF`, and `Number::format()` renders it as the plain string `INF` with no error flag. For the mean, median and standard deviation the true value often fits in a double (for example `mean([1e308, 1e308])` is `1e308`), so the model receives a confidently wrong answer caused only by an intermediate overflow and presents it as the result. The inputs must be near the limit of a double, and nothing is lost or exposed.

Suggested fix: add a protected `result()` method to `StatisticTool.php` that returns `Number::format()` for finite values and `ToolOutput::error()` otherwise, and call it from the four tools; this was validated in a sandbox against the repro and the module's tests. Returning the correct value by scaling before summing and computing the median midpoint as `$a / 2 + $b / 2` is a possible follow-up that was not validated.

### <a id="tools-41"></a>TOOLS-41 · Calendar add and subtract month operations overflow past the end of the month

**low** · bug · [`src/Tools/Toolkits/Calendar/AddTimeTool.php:64`](../src/Tools/Toolkits/Calendar/AddTimeTool.php#L64) · repro [`MonthOverflowTest`](repro/Tools/MonthOverflowTest.php) · fix validated

`AddTimeTool` and `SubtractTimeTool` apply a raw `DateInterval` `P{n}M`, inheriting PHP's day overflow. For any date on day 29 to 31 the result skips the target month: Jan 31 plus one month is Mar 3, Mar 31 plus one month is May 1, and Mar 31 minus one month is also Mar 3, so subtracting does not change the month. The model receives no warning and may pass the date on as a billing, renewal or deadline date. Most date libraries clamp to the last day of the target month instead. Feb 29 plus or minus one year landing on Mar 1 is a defensible, separate policy that `AddTimeToolTest::test_add_years` currently pins.

Suggested fix: in `AddTimeTool.php` and `SubtractTimeTool.php`, for the `months` unit remember the day of month, move to the first day of the month, apply the interval, then set the day to the smaller of the original day and the target month's length; this was validated in a sandbox against the repro and the module's tests. Extending the clamp to `years` also works but changes `test_add_years`, so it should be adopted only as a deliberate policy decision.

### <a id="tools-42"></a>TOOLS-42 · Calendar add and subtract tools truncate fractional amounts and drop fractional weeks

**low** · bug · [`src/Tools/Toolkits/Calendar/AddTimeTool.php:70`](../src/Tools/Toolkits/Calendar/AddTimeTool.php#L70) · repro [`FractionalAmountTest`](repro/Tools/FractionalAmountTest.php) · fix validated

`AddTimeTool` and `SubtractTimeTool` compute the fractional remainder with `(int) ($fracPart * 60)` (and likewise for hours and days), which truncates floating-point error: 2.3 minutes adds 137 seconds instead of 138, and 1.15 hours gives `01:08:59` instead of `01:09:00`. For `weeks` the fraction is dropped entirely, so 1.5 weeks adds exactly 7 days instead of 10.5. The model receives these wrong times and dates for scheduling answers with no signal that anything was lost. Fractions of months and years are also dropped, but since those units have no fixed length this is a design point rather than a verified bug.

Suggested fix: in both tools, compute the extra seconds with `(int) round(...)` and add a `weeks` arm using 604800 seconds; this was validated in a sandbox against the repro and the module's tests. Whether fractional months, years and seconds should be rejected with an error or documented as unsupported remains open.

### <a id="tools-43"></a>TOOLS-43 · CalculateAgeTool returns a positive age for a birthdate after the reference date

**low** · bug · [`src/Tools/Toolkits/Calendar/CalculateAgeTool.php:65`](../src/Tools/Toolkits/Calendar/CalculateAgeTool.php#L65) · repro [`CalculateAgeFutureBirthdateTest`](repro/Tools/CalculateAgeFutureBirthdateTest.php) · fix validated

`CalculateAgeTool` reads `$interval->y`, `m`, `d` and `days` from `$birth->diff($reference)` and never checks `$interval->invert`. When the birthdate is later than the reference date, for example because the model swapped the arguments, the tool returns a plausible positive age: birthdate `2030-01-01` with reference `2024-01-01` yields `6` years, and the `all` format even reports a `next_birthday` of `2037-01-01`. The model passes this wrong value on as fact instead of receiving an error it could correct. Nothing crashes and there is no security exposure.

Suggested fix: in `CalculateAgeTool.php`, return `'Error: The birthdate is after the reference date.'` when `$birth > $reference`, before computing the interval, matching the tool's existing error convention; this was validated in a sandbox against the repro and the module's tests.

### <a id="tools-44"></a>TOOLS-44 · CompareDatesTool and IsDateInRangeTool ignore unsupported precision values but report them as applied

**low** · bug · [`src/Tools/Toolkits/Calendar/CompareDatesTool.php:93`](../src/Tools/Toolkits/Calendar/CompareDatesTool.php#L93) · repro [`UnsupportedPrecisionTest`](repro/Tools/UnsupportedPrecisionTest.php) · fix validated

The `precision` enum is only a schema hint, because `Tool::execute()` does not enforce enums. In `CompareDatesTool` and `IsDateInRangeTool`, `normalizeDateTimes()` maps any unknown value such as `week` to `default => null`, so the dates are compared at second precision while the payload reports `"precision":"week"`. The model gets a confident, wrong answer: `IsDateInRangeTool` says Sunday 2023-06-18 12:00 is after a range ending on 2023-06-18 at week precision. `StartOfPeriod`, `EndOfPeriod`, `AddTime` and `SubtractTime` return an error for unknown values, so these two tools are also inconsistent with the rest of the toolkit.

Suggested fix: in both files, handle `'second' => null` explicitly and make the `default` arm throw `InvalidArgumentException("Unsupported precision: {$precision}")`, which the existing try/catch turns into an `Error:` result; this was validated in a sandbox against the repro and the module's tests. Enforcing `ToolProperty` enums generically in `Tool::execute()` is a broader alternative for later discussion.

### <a id="tools-45"></a>TOOLS-45 · GetDaysInMonthTool lets a ValueError escape for year 0

**low** · bug · [`src/Tools/Toolkits/Calendar/GetDaysInMonthTool.php:53`](../src/Tools/Toolkits/Calendar/GetDaysInMonthTool.php#L53) · repro [`GetDaysInMonthYearZeroTest`](repro/Tools/GetDaysInMonthYearZeroTest.php) · fix validated

`GetDaysInMonthTool` passes the year straight to `cal_days_in_month()`, which throws `ValueError: Invalid date` for year 0. `ValueError` is an `Error`, not an `Exception`, so the tool's `catch (Exception)` does not handle it, and without a tool error handler the agent run aborts instead of the model receiving a recoverable error string. Negative years do not crash but return contradictory data: for year -1 the tool reports 29 days in February together with `is_leap_year` false and a malformed date string, which can mislead the model.

Suggested fix: in `GetDaysInMonthTool.php`, after the month check and inside the existing try block, throw `InvalidArgumentException('Year must be greater than 0')` when `$year < 1`, which fixes both the crash and the inconsistent output; this was validated in a sandbox against the repro and the module's tests.

### <a id="tools-46"></a>TOOLS-46 · Calendar tools interpret the same date string in different timezones

**low** · api-inconsistency · [`src/Tools/Toolkits/Calendar/IsWeekendTool.php:49`](../src/Tools/Toolkits/Calendar/IsWeekendTool.php#L49) · repro [`CalendarTimezoneConsistencyTest`](repro/Tools/CalendarTimezoneConsistencyTest.php) · fix validated

Every Calendar tool describes `timezone` as the zone for date interpretation, and most of them parse the date string in that zone. `IsWeekendTool` instead parses it as UTC and then converts, and `GetWeekdayTool` parses it in PHP's default timezone and then converts. The same input therefore yields different instants: `2023-06-16 23:00:00` with `Asia/Tokyo` is Friday for `GetWeekNumber` but Saturday for `IsWeekend` and, on a server set to New York, for `GetWeekday`. This produces contradictory answers for scheduling decisions near midnight, and `GetWeekday` additionally varies between development, CI and production depending on `php.ini`.

Suggested fix: in `IsWeekendTool.php` and `GetWeekdayTool.php`, parse the string in the requested zone and keep the trailing `setTimezone($tz)` so strings with an explicit offset are still converted, and update the misleading code comments; this was validated in a sandbox against the repro and the module's tests. Three existing tests that pin the old behaviour must be updated with it: `IsWeekendToolTest::test_a_date_string_is_read_as_utc_and_converted_to_the_requested_timezone`, `GetWeekdayToolTest::test_get_weekday_with_timezone` and `GetWeekdayToolTest::test_get_weekday_across_timezones`.

### <a id="tools-47"></a>TOOLS-47 · EditFileTool reports success for an empty search string without changing the file

**low** · bug · [`src/Tools/Toolkits/FileSystem/EditFileTool.php:71`](../src/Tools/Toolkits/FileSystem/EditFileTool.php#L71) · repro [`EditFileToolEmptySearchTest`](repro/Tools/EditFileToolEmptySearchTest.php) · fix validated

In `EditFileTool`, `str_contains($current, '')` is always true, so the not-found check passes, and `str_replace('', ...)` returns the content unchanged. When the model sends an empty search string, for example because it wanted to insert or prepend text, the tool rewrites the same bytes and replies that the file was edited successfully. The agent then continues with a wrong picture of the workspace, and the file's modification time is touched for no reason. No data is corrupted or exposed.

Suggested fix: at the top of `EditFileTool::__invoke()`, return `ToolOutput::error()` when `$search === ''`, pointing the model to `write_file` for replacing a whole file; this was validated in a sandbox against the repro and the module's tests. Whether `edit_file` should require a unique match instead of replacing every occurrence is a separate design question.

### <a id="tools-48"></a>TOOLS-48 · ParseFileTool description refers the model to a non-existent preview_file tool

**low** · docs-mismatch · [`src/Tools/Toolkits/FileSystem/ParseFileTool.php:25`](../src/Tools/Toolkits/FileSystem/ParseFileTool.php#L25) · repro [`ToolkitCrossReferenceTest`](repro/Tools/ToolkitCrossReferenceTest.php) · fix validated

`ParseFileTool::$description` says to use the tool after `preview_file` confirms the document is relevant, but no tool with that name exists in the toolkit or the framework. A model that follows the instruction calls an unknown tool, which is refused and wastes a turn and tokens, or it may hold off on `parse_file` while waiting for a precondition it can never meet. Every other tool name referenced in the toolkit's descriptions and guidelines is valid. There is no security or data impact.

Suggested fix: rewrite the description in `ParseFileTool.php` so it no longer mentions `preview_file`, for example stating that it parses PDF and HTML documents and should be used instead of `read_file` for them, and add a test under `tests/Tools/Toolkits/FileSystem/` that checks every tool name mentioned in descriptions and guidelines exists; this was validated in a sandbox against the repro and the module's tests.

### <a id="tools-49"></a>TOOLS-49 · ReadFileTool and GrepFileContentTool return binary content and have no size limit

**low** · design · [`src/Tools/Toolkits/FileSystem/ReadFileTool.php:47`](../src/Tools/Toolkits/FileSystem/ReadFileTool.php#L47) · repro [`ReadFileBinaryContentTest`](repro/Tools/ReadFileBinaryContentTest.php) · fix validated

`ReadFileTool` and `GrepFileContentTool` read the file with `file_get_contents` and return its bytes (or matched substrings) with no UTF-8 check. When the model reads a binary or non-UTF-8 file listed by `glob_path`, such as an image, archive, SQLite database or Latin-1 log, the tool result contains invalid UTF-8. With the default `CurlHttpClient`, `json_encode` of the next provider request returns `false`, so the provider receives an empty body and the run fails with an opaque API error; with Guzzle an encoding exception aborts the run, and JSON persistence of the history can fail the same way. Separately, neither tool has a byte cap, so reading a multi-GB file can hit `memory_limit` or flood the context window; this part was confirmed only by reading the code.

Suggested fix: in `ReadFileTool.php` and `GrepFileContentTool.php`, after the `$content === false` check, return `ToolOutput::error()` when `mb_check_encoding($content, 'UTF-8')` fails, suggesting `parse_file` for documents; this was validated in a sandbox against the repro and the module's tests. A size limit, via a byte cap checked with `filesize()` or offset and limit properties on `read_file`, would be new API and should be discussed separately.

### <a id="tools-50"></a>TOOLS-50 · MySQL and PostgreSQL schema tools run a constraints query whose result is unused

**low** · dead-code · [`src/Tools/Toolkits/MySQL/MySQLSchemaTool.php:42`](../src/Tools/Toolkits/MySQL/MySQLSchemaTool.php#L42) · repro [`SchemaToolDiscardedLookupTest`](repro/Tools/SchemaToolDiscardedLookupTest.php) · fix validated

`MySQLSchemaTool` and `PGSQLSchemaTool` store the output of `getConstraints()` under `$structure['constraints']`, but `formatForLLM` never reads that key. Every invocation of the schema tool therefore executes an extra `information_schema.table_constraints` query whose result is discarded, adding latency and database load that can be noticeable on MySQL servers with many schemas, and maintainers may wrongly assume constraints reach the model. The `max_length`, `precision` and `scale` keys built in `getTables()` are likewise never rendered, though that is only minor redundancy since `full_type` already carries them. There is no functional or security impact.

Suggested fix: remove the `'constraints' => $this->getConstraints()` entry and the `getConstraints()` method from both classes, and update `MySQLSchemaToolTest` to expect three executed queries and drop its two `TABLE_CONSTRAINTS` assertions; this was validated in a sandbox against the repro and the module's tests. Rendering the constraints instead would require fetching CHECK clauses and filtering auto-generated NOT NULL checks, so removal is the simpler option.

### <a id="tools-51"></a>TOOLS-51 · MySQLWriteTool iterates null parameters, raising a foreach warning on every unparameterised write

**low** · bug · [`src/Tools/Toolkits/MySQL/MySQLWriteTool.php:69`](../src/Tools/Toolkits/MySQL/MySQLWriteTool.php#L69) · repro [`MySQLWriteToolNullParametersTest`](repro/Tools/MySQLWriteToolNullParametersTest.php) · fix validated

`parameters` is optional and typed `?array`, but unlike `MySQLSelectTool` and `PGSQLWriteTool`, `MySQLWriteTool` does not normalise it before its binding loop. Because `Tool::execute()` fills in missing optional inputs as `null`, every write the model sends without placeholders, such as `DELETE FROM sessions WHERE expired = 1`, triggers `foreach() argument must be of type array|object, null given`. In applications that convert warnings into exceptions (Laravel, Symfony and most error handlers) the tool call fails; elsewhere the query runs correctly but each call logs a warning. There is no data loss or security exposure.

Suggested fix: add `$parameters ??= [];` before the binding loop in `MySQLWriteTool::__invoke()`, as the sibling tools already do; this was validated in a sandbox against the repro and the module's tests.

### <a id="tools-52"></a>TOOLS-52 · PGSQLSchemaTool mixes key columns and row estimates from other schemas

**low** · bug · [`src/Tools/Toolkits/PGSQL/PGSQLSchemaTool.php:174`](../src/Tools/Toolkits/PGSQL/PGSQLSchemaTool.php#L174) · repro [`PGSQLSchemaToolSchemaIsolationTest`](repro/Tools/PGSQLSchemaToolSchemaIsolationTest.php) · fix validated

`PGSQLSchemaTool` joins `key_column_usage` to `table_constraints` only on `constraint_name` in the primary and unique key subqueries, and `getTableRowCount()` filters `pg_stat_user_tables` only by `relname`. When other schemas contain tables and constraints with the same names, which is common in schema-per-tenant or staging setups because default names like `<table>_pkey` collide, the description sent to the model merges key columns from another schema's table (verified output: primary key `id, code` instead of `id`) and can report another schema's row estimate (7 instead of 2). This steers the model toward wrong join and uniqueness assumptions and can reveal that other schemas exist with their approximate sizes, though no cross-schema data is read.

Suggested fix: in `PGSQLSchemaTool.php`, add `AND tc.constraint_schema = ku.constraint_schema` to the join in both key subqueries of `getTables()`, and filter `getTableRowCount()` with `schemaname = current_schema() AND relname = ?`; this was validated in a sandbox against the repro and the module's tests.

### <a id="tools-53"></a>TOOLS-53 · PGSQLSchemaTool misreports array types, expression-index columns and an empty table filter

**low** · bug · [`src/Tools/Toolkits/PGSQL/PGSQLSchemaTool.php:189`](../src/Tools/Toolkits/PGSQL/PGSQLSchemaTool.php#L189) · repro [`PGSQLSchemaToolFormattingTest`](repro/Tools/PGSQLSchemaToolFormattingTest.php) · fix validated

The schema text that `PGSQLSchemaTool` gives the model has three rendering defects. `formatPostgreSQLType` uses `udt_name`, so array columns show up as `_text` or `_int4` rather than `text[]`, and the array-column hint, which looks for `[]`, never fires. `getIndexes` takes the first identifier inside the parentheses of `indexdef`, so an index on `lower(title)` is listed as covering a column named `lower`. And with `tables: []`, which means no filter everywhere else, `formatForLLM` still prints "(filtered to specified tables)" because it only checks `!== null`. Nothing leaks, but the model can be led into queries that reference non-existent columns, which lowers SQL quality and wastes tool-call round trips.

Suggested fix: in `src/Tools/Toolkits/PGSQL/PGSQLSchemaTool.php`, have `formatPostgreSQLType` return `ltrim(udt_name, '_') . '[]'` when `data_type` is `ARRAY`. Replace the regex parsing in `getIndexes` with a per-key `pg_get_indexdef(indexrelid, k, true)` aggregated via `json_agg`, which yields `lower(title)` and handles quoted identifiers and `INCLUDE` columns correctly. In `formatForLLM`, also require `$this->tables !== []`. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-54"></a>TOOLS-54 · PGSQLSelectTool rejects harmless SELECTs whose identifiers or literals contain 'eval', 'exec' or 'system'

**low** · bug · [`src/Tools/Toolkits/PGSQL/PGSQLSelectTool.php:176`](../src/Tools/Toolkits/PGSQL/PGSQLSelectTool.php#L176) · repro [`PGSQLSelectFalsePositiveTest`](repro/Tools/PGSQLSelectFalsePositiveTest.php) · fix validated

`performAdditionalSecurityChecks()` runs a case-insensitive `stripos` for `pg_exec`, `pg_query`, `system`, `exec`, `shell_exec`, `passthru` and `eval` over the whole query. So any table, column, alias or string literal containing one of these words, such as `evaluations`, `system_logs`, `executed_at` or `WHERE status = 'executed'`, makes the read-only tool refuse a harmless SELECT with a misleading "write query" error. Agents on such schemas can never read that data and may loop retrying, which wastes tool runs and tokens. The listed names are PHP functions, not PostgreSQL ones, so the check blocks nothing real while real risks such as `pg_read_file`, `lo_import` or `dblink` are absent from it. This is a usability bug, not a security hole.

Suggested fix: in `src/Tools/Toolkits/PGSQL/PGSQLSelectTool.php`, replace the `$dangerousFunctions` array and `stripos` loop with a whole-word function-call match, `preg_match('/\b(pg_exec|pg_query|system|exec|shell_exec|passthru|eval)\s*\(/i', $query)`, and drop the unused `stripos` import. This was validated in a sandbox against the repro and the module's tests. Whether to drop the list entirely or replace it with the real risky PostgreSQL functions, and to recommend a read-only role or transaction as the actual safeguard, is a separate design question.

### <a id="tools-55"></a>TOOLS-55 · write_todos schema omits required 'content', and an array status raises a PHP warning

**low** · bug · [`src/Tools/Toolkits/TodoPlanning/WriteTodosTool.php:104`](../src/Tools/Toolkits/TodoPlanning/WriteTodosTool.php#L104) · repro [`WriteTodosContractTest`](repro/Tools/WriteTodosContractTest.php) · fix validated

In `WriteTodosTool`, the item `ObjectProperty` declares `content` without `required: true`, so the schema sent to the model lists only `status` as required, yet `__invoke()` rejects any item without `content`. A model that follows the schema is rejected, wasting a turn that counts toward `toolMaxRuns`. Also, when `status` is an array, the error message interpolates it and emits "Array to string conversion". This is reachable through the normal `setInputs()`/`execute()` path, because a class-less `ObjectProperty::cast()` passes nested values through unchecked. Under a handler that turns warnings into exceptions (Laravel, Symfony), the exception escapes the tool and aborts the agent run. Otherwise the model gets a misleading "invalid status 'Array'" message.

Suggested fix: in `src/Tools/Toolkits/TodoPlanning/WriteTodosTool.php`, add `required: true` to the `content` `ToolProperty`, and in `__invoke()` render the invalid status as `is_scalar($status) ? (string) $status : get_debug_type($status)`. The unused `protected array $todos` can be removed as cleanup. This was validated in a sandbox against the repro and the module's tests. A broader point for discussion is that class-less `ObjectProperty` never casts or validates nested properties.

### <a id="tools-56"></a>TOOLS-56 · Zep createUser() puts user_id into the URL path without encoding

**low** · security · [`src/Tools/Toolkits/Zep/HandleZepClient.php:47`](../src/Tools/Toolkits/Zep/HandleZepClient.php#L47) · repro [`ZepUserIdEncodingTest`](repro/Tools/ZepUserIdEncodingTest.php) · fix validated

`createUser()` in `HandleZepClient` requests `'users/'.$this->user_id` without `rawurlencode`. A `user_id` containing `/`, `..`, `?`, `#` or `%` changes the path the existence check hits or injects a query string or fragment. For example, `../admin?x=1` sends `GET /api/v2/users/../admin?x=1`. This affects apps using the deprecated Zep toolkit whose ids contain such characters. The lookup can hit the wrong endpoint, so a user may be wrongly treated as missing or existing and the fallback `POST users` may run needlessly. The impact is mainly correctness. The `user_id` comes from the developer, not from LLM tool input, the request only uses the app's own key, and the GET response is discarded, so nothing is exposed.

Suggested fix: in `src/Tools/Toolkits/Zep/HandleZepClient.php`, import `rawurlencode` and call `$this->get('users/'.rawurlencode($this->user_id))`, while keeping the raw `user_id` in the POST body. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-57"></a>TOOLS-57 · Zep createUser() treats any lookup failure as a missing user and masks the error

**low** · error-handling · [`src/Tools/Toolkits/Zep/HandleZepClient.php:51`](../src/Tools/Toolkits/Zep/HandleZepClient.php#L51) · repro [`ZepUserLookupFailureTest`](repro/Tools/ZepUserLookupFailureTest.php) · fix validated

`createUser()` catches every `Exception` from `GET users/{id}`, including 401/403, 5xx and network errors, and then POSTs to `users`. During an outage or with a bad API key, the deprecated Zep tools (`ZepAddToGraphTool`, `ZepSearchGraphTool`, `ZepLongTermMemoryToolkit`) send a needless user-creation request. The original lookup error is also replaced by whatever the POST returns, or hidden completely if the POST succeeds, so the agent carries on as if nothing went wrong. Every add or search call also pays for an extra GET. There is no data loss or security exposure.

Suggested fix: in `src/Tools/Toolkits/Zep/HandleZepClient.php`, catch `NeuronAI\Exceptions\HttpException` instead of `Exception` and rethrow unless `$exception->response?->statusCode` is `404`, so only a real 404 triggers the POST. This was validated in a sandbox against the repro and the module's tests. Caching the "user ensured" state per instance to skip the repeated GET is an optional efficiency follow-up, not part of the fix.

### <a id="tools-58"></a>TOOLS-58 · ZepAddToGraphTool property descriptions were copied from the search tool and contradict the enum

**low** · docs-mismatch · [`src/Tools/Toolkits/Zep/ZepAddToGraphTool.php:38`](../src/Tools/Toolkits/Zep/ZepAddToGraphTool.php#L38) · repro [`ZepAddToGraphToolSchemaTest`](repro/Tools/ZepAddToGraphToolSchemaTest.php) · fix validated

In `ZepAddToGraphTool::properties()`, `data` is described as "The search term to find relevant facts or nodes". `type` is described as the scope of a search that can be "facts" or "nodes", while its enum is `['text', 'json', 'message']`. Both descriptions were copied from `ZepSearchGraphTool`. The LLM therefore receives contradictory instructions for an insert. It may send `facts` or `nodes`, which the enum and the Zep API reject, or put a search query in `data` instead of the information to remember. That makes long-term memory writes in the deprecated toolkit less reliable. There is no crash or data exposure.

Suggested fix: in `src/Tools/Toolkits/Zep/ZepAddToGraphTool.php`, change the `data` description to "The information to store in the knowledge graph" and the `type` description to "The format of the data. Can be \"text\", \"json\" or \"message\"", leaving the enum unchanged. This was validated in a sandbox against the repro and the module's tests.

### <a id="tools-59"></a>TOOLS-59 · TrackByInputs run key depends on argument order and undeclared inputs, bypassing per-input limits

**low** · security · [`src/Tools/TrackByInputs.php:21`](../src/Tools/TrackByInputs.php#L21) · repro [`TrackByInputsRunKeyTest`](repro/Tools/TrackByInputsRunKeyTest.php) · fix validated

`TrackByInputs::getRunKey()` hashes `json_encode($this->getInputs())` as is. Key order becomes part of the hash, and `setInputs()` keeps keys the tool never declared. So `{permanent, account_id}`, `{account_id, permanent}` and the same inputs plus a junk `nonce` produce three different run keys, even though `__invoke()` receives identical arguments. `ToolNode` counts runs by this key, so a model can get a fresh `toolMaxRuns()` budget for the same logical call and slip a repeat loop past the per-input limit, for example repeated paid or destructive calls. The scope is narrow: the trait is opt-in, the default name-based key is unaffected, and approval gating is unaffected. The invalid-UTF-8 `TypeError` is not reachable in the agent flow.

Suggested fix: in `src/Tools/TrackByInputs.php`, build the hashed array from the declared properties in declaration order, using `getInput()` for each, and encode it with `JSON_INVALID_UTF8_SUBSTITUTE`. The fixture in `ToolTest::test_track_by_inputs_run_key` must then declare the `path` property it uses. This was validated in a sandbox against the repro and the module's tests. A tradeoff to decide on is that a `FrontendTool` that allows `additionalProperties` would have its undeclared keys ignored in the run key.

## <a id="module-rag"></a>RAG

RAG has 53 findings, 3 of them high. [RAG-01](#rag-01) allows Cypher injection through LLM-extracted relations. [RAG-02](#rag-02) deletes a source before its replacement has been validated or embedded. [RAG-03](#rag-03) duplicates Elasticsearch documents in every bulk request. FileVectorStore can be corrupted or emptied ([RAG-22](#rag-22), [RAG-23](#rag-23), [RAG-24](#rag-24), [RAG-53](#rag-53)), Typesense drops rejected documents silently ([RAG-31](#rag-31), [RAG-52](#rag-52)), and directory loading ingests .env files and follows symlinks outside the root ([RAG-06](#rag-06), [RAG-08](#rag-08)). Many medium items are per-store inconsistencies in topK, ids and score semantics ([RAG-21](#rag-21), [RAG-26](#rag-26), [RAG-27](#rag-27), [RAG-30](#rag-30), [RAG-32](#rag-32)).

### <a id="rag-01"></a>RAG-01 · Cypher injection through the relation string in Neo4jGraphStore upsert() and delete()

**high** · security · [`src/RAG/GraphStore/Neo4jGraphStore.php:35`](../src/RAG/GraphStore/Neo4jGraphStore.php#L35) · repro [`Neo4jCypherInjectionTest`](repro/RAG/Neo4jCypherInjectionTest.php) · fix validated

`Neo4jGraphStore::upsert()` and `delete()` only uppercase the relation and replace spaces with underscores before placing it between backticks in the Cypher text. Backticks are not escaped, and tabs, newlines, `//` comments and `\uXXXX` escapes pass through. In knowledge-graph RAG the relation normally comes from triplets an LLM extracts from untrusted documents, so a crafted document can close the relationship pattern and append arbitrary Cypher, for example `WITH * MATCH (X) DETACH DELETE X`, which wipes the graph with the store's credentials. A harmless relation that merely contains a backtick also produces a broken statement and an ingestion error.

Suggested fix: in `src/RAG/GraphStore/Neo4jGraphStore.php`, route the relation through a new protected `relationshipType()` helper that collapses every run of characters other than letters, digits and `_` into `_` and uppercases with `mb_strtoupper`, keeping the existing normalisation while blocking backticks, whitespace, comments and escape sequences. This was validated in a sandbox against the repro and the module's tests; `nodeLabel` could optionally be validated the same way in the constructor.

### <a id="rag-02"></a>RAG-02 · reindexBySource deletes a source before validating or embedding its replacement

**high** · bug · [`src/RAG/RAG.php:102`](../src/RAG/RAG.php#L102) · repro [`ReindexValidatesBeforeDeleteTest`](repro/RAG/ReindexValidatesBeforeDeleteTest.php) · fix validated

For each source, `RAG::reindexBySource()` deletes the stored documents first and only then calls `addDocuments()`, where the chunk-size guard, schema validation and embedding happen. If one replacement document fails the store's `DocumentSchema`, if `chunkSize` is below 1, or if the embeddings provider throws (rate limit, network error, bad API key), the source's previously indexed documents are permanently gone and nothing replaces them. With several sources in one call, earlier sources are replaced, the failing one is emptied and later ones keep old data, and retrieval silently returns nothing for the wiped source until it is re-ingested.

Suggested fix: in `src/RAG/RAG.php`, extract protected `assertValidChunkSize()` and `validateDocuments()` helpers (reused by `addDocuments()`), call them at the top of `reindexBySource()`, and embed each source's chunks before calling `delete()` and storing them. This was validated in a sandbox against the repro and the module's tests; the store-level delete and add remain non-atomic, which is a separate design topic.

### <a id="rag-03"></a>RAG-03 · Elasticsearch addDocuments re-sends all earlier chunks in each bulk request

**high** · bug · [`src/RAG/VectorStore/ElasticsearchVectorStore.php:143`](../src/RAG/VectorStore/ElasticsearchVectorStore.php#L143) · repro [`ElasticsearchBulkReproTest`](repro/RAG/ElasticsearchBulkReproTest.php) · fix validated

In `ElasticsearchVectorStore::addDocuments()`, `$params = ['body' => []]` is initialised once outside the chunk loop and never reset, so each `_bulk` request carries every previous chunk plus the current one. Documents have no `_id`, so Elasticsearch indexes the earlier chunks again: with 101 documents the second request carries 101 documents instead of 1, and with 1,000 documents 5,500 index operations produce 4,500 duplicates. Any ingestion above 100 documents silently corrupts the index; duplicate chunks crowd relevant context out of `topK`, waste LLM tokens, and growing payloads can hit `http.max_content_length`.

Suggested fix: in `src/RAG/VectorStore/ElasticsearchVectorStore.php`, move `$params = ['body' => []];` inside the `foreach ($chunks as $chunk)` loop, and add the repro as a regression test under `tests/RAG/VectorStore/`. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-04"></a>RAG-04 · FileDataLoader silently returns no documents when a single file fails to load

**medium** · design · [`src/RAG/DataLoader/FileDataLoader.php:72`](../src/RAG/DataLoader/FileDataLoader.php#L72) · repro [`FileDataLoaderReadFailureTest`](repro/RAG/FileDataLoaderReadFailureTest.php) · fix validated

`FileDataLoader::getDocuments()` wraps single-file loading in `catch (Throwable) { return []; }`. A missing or non-executable `pdftotext`, a failed or timed-out extraction, an unreadable file, a reader or splitter exception, or even a `TypeError` all turn into an empty result with no signal, so ingestion jobs report success after indexing nothing and retrieval later answers without that source. Directory mode has no such catch, so the same file loaded through its parent directory throws instead, and the behaviour depends on how the caller passed the path.

Suggested fix: in `src/RAG/DataLoader/FileDataLoader.php`, remove the try/catch around single-file loading in `getDocuments()` so errors propagate as in directory mode, and drop the unused `Throwable` import. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-05"></a>RAG-05 · FileDataLoader follows directory symlink cycles and ingests every file about 40 times

**medium** · bug · [`src/RAG/DataLoader/FileDataLoader.php:84`](../src/RAG/DataLoader/FileDataLoader.php#L84) · repro [`FileDataLoaderSymlinkLoopTest`](repro/RAG/FileDataLoaderSymlinkLoopTest.php) · fix validated

`getDocumentsFromDirectory()` recurses with `is_dir()`, which follows symlinks, and does not record visited real paths. A symlink pointing back to an ancestor directory is walked until the OS returns `ELOOP`: in the repro a single file came back as 41 identical documents, and the final unresolvable path was read as a file, raising a `file_get_contents()` notice. The vector store is flooded with duplicates, embedding cost grows by the same factor, retrieval is dominated by repeated chunks, and frameworks that turn notices into exceptions abort the whole ingestion. With several such links the recursion grows exponentially.

Suggested fix: in `src/RAG/DataLoader/FileDataLoader.php`, pass a set of visited `realpath()` values through `getDocumentsFromDirectory()` and skip directories already seen. This was validated in a sandbox against the repro and the module's tests; the traversal rewrite proposed for RAG-08 (symlinks outside the root) also covers this case, so the two can be fixed together.

### <a id="rag-06"></a>RAG-06 · Directory loading ingests hidden files such as .env and .git/config

**medium** · security · [`src/RAG/DataLoader/FileDataLoader.php:84`](../src/RAG/DataLoader/FileDataLoader.php#L84) · repro [`FileDataLoaderHiddenFilesTest`](repro/RAG/FileDataLoaderHiddenFilesTest.php) · fix validated

`FileDataLoader` skips only `.` and `..` when walking a directory, so dotfiles and dot-directories are read with the plain-text reader and become documents. Pointing the loader at a docs folder, a project root or an extracted archive embeds `.env` API keys and database passwords, `.git/config` remote URLs with tokens, `.npmrc` or `.aws/credentials` into the vector store, sends them to the embeddings provider, and lets retrieval quote them to any user of the RAG agent. It also adds noise such as `.DS_Store` and compressed `.git/objects` blobs, whose invalid UTF-8 can break embedding or JSON encoding. The input path is chosen by the developer, which is why it is rated medium.

Suggested fix: in `getDocumentsFromDirectory()` in `src/RAG/DataLoader/FileDataLoader.php`, replace the `.`/`..` check with `! str_starts_with($entry, '.')`, so hidden entries are skipped during traversal while a hidden file passed explicitly as the loader path is still loaded. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-07"></a>RAG-07 · FileDataLoader sourceName collides for same-named nested files and differs between modes

**medium** · bug · [`src/RAG/DataLoader/FileDataLoader.php:89`](../src/RAG/DataLoader/FileDataLoader.php#L89) · repro [`FileDataLoaderSourceNameTest`](repro/RAG/FileDataLoaderSourceNameTest.php) · fix validated

In directory mode each document's `sourceName` is the bare file name, while single-file mode uses the path as given. Files with the same name in different subfolders (`tenant-a/readme.txt` and `tenant-b/readme.txt`) therefore share a source, so `reindexBySource()` for one folder silently deletes the other folder's chunks, which can cross tenant boundaries in a shared store. A file first loaded with its directory (`guide.txt`) and later reindexed alone (`/abs/path/guide.txt`), or the reverse, is never matched by the delete filter, leaving stale chunks beside the new ones. The lost data is a derived index that re-ingestion can rebuild.

Suggested fix: in `getDocumentsFromDirectory()` in `src/RAG/DataLoader/FileDataLoader.php`, pass `$fullPath` instead of `$entry` to `getDocument()`. This was validated in a sandbox against the repro and the module's tests; note that `InstructionsNode` puts `Source Name` into the model context, so this extends the existing exposure of absolute server paths to directory mode.

### <a id="rag-08"></a>RAG-08 · Directory loading follows symlinks that point outside the loaded root

**medium** · security · [`src/RAG/DataLoader/FileDataLoader.php:90`](../src/RAG/DataLoader/FileDataLoader.php#L90) · repro [`FileDataLoaderSymlinkEscapeTest`](repro/RAG/FileDataLoaderSymlinkEscapeTest.php) · fix validated

`FileDataLoader` reads files and directories reached through symlinks wherever they point, with no `realpath` containment check. When the loaded tree is not fully trusted, such as extracted user archives, synced or shared folders, per-tenant upload directories or cloned repositories, a link like `escape.txt -> /etc/passwd` or a link to another tenant's folder pulls outside content into the vector store, where retrieval can show it to end users. This breaks confidentiality and tenant isolation. The directory is chosen by the developer and plain HTTP uploads usually cannot create symlinks, which is why it is rated medium; single-file mode is unaffected.

Suggested fix: in `src/RAG/DataLoader/FileDataLoader.php`, replace `getDocumentsFromDirectory()` with a traversal that resolves the root once, follows an entry only when its `realpath()` stays under the root, reads each resolved directory once, and splits the whole tree a single time. This was validated in a sandbox against the repro and the module's tests; it also fixes the symlink-cycle duplication in RAG-05 and skips broken links instead of aborting.

### <a id="rag-09"></a>RAG-09 · FileDataLoader discards reader instance configuration such as the PdfReader binary path

**medium** · design · [`src/RAG/DataLoader/FileDataLoader.php:117`](../src/RAG/DataLoader/FileDataLoader.php#L117) · repro [`PdfReaderInstanceConfigurationTest`](repro/RAG/PdfReaderInstanceConfigurationTest.php) · fix validated

`ReaderInterface::getText()` is static, so `FileDataLoader` calls `$reader::getText($path)` and `PdfReader::getText()` builds a fresh `new static()`. The bin path passed to `new PdfReader($binPath)` and any `setOptions()` or `setTimeout()` are ignored. Where `pdftotext` exists only at the configured path (Windows, custom installs, containers), a single-file load returns zero documents with no error because of the single-file catch, and a directory load throws `pdftotext binary was not found`. Where a system binary exists, it silently replaces the configured one and its options. Custom readers that need per-instance configuration cannot work under the static contract.

Suggested fix: change `ReaderInterface` to an instance method `read(string $filePath): string`, call `$this->readers[$ext]->read($path)` in `FileDataLoader::getContentFromFile()`, implement `read()` on `PdfReader` via `(clone $this)->setPdf($filePath)->text()` and on `TextFileReader`, `HtmlReader` and the test stub. This was validated in a sandbox against the repro and the module's tests; since this is a major version, dropping the static `getText()` entirely is a cleaner alternative that also requires updating `ParseFileTool`.

### <a id="rag-10"></a>RAG-10 · Cohere embeddings provider embeds stored documents with input_type search_query

**medium** · bug · [`src/RAG/Embeddings/CohereEmbeddingsProvider.php:78`](../src/RAG/Embeddings/CohereEmbeddingsProvider.php#L78) · repro [`CohereDocumentInputTypeTest`](repro/RAG/CohereDocumentInputTypeTest.php) · fix validated

Cohere v3/v4 embedding models are asymmetric: stored documents should use `input_type` `search_document` and queries `search_query`. `CohereEmbeddingsProvider::embedDocuments()` hard-codes `search_query`, and `embedDocument()` (used by `ConversationIngestionNode`) is not overridden, so it goes through `embedText()` and also sends `search_query`. Every Cohere-backed index is therefore built with query embeddings and ranks worse, with no error. The constructor `$parameters` override applies to both ingestion and retrieval, so one instance cannot be configured correctly for both. Existing indexes need re-embedding after the fix.

Suggested fix: in `src/RAG/Embeddings/CohereEmbeddingsProvider.php`, default `input_type` to `search_document` in `embedDocuments()` and override `embedDocument()` to return `$this->embedDocuments([$document])[0]`, leaving `embedText()` on `search_query`. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-11"></a>RAG-11 · getRelationshipMap() attributes multi-hop relationships to the root subject and duplicates them

**medium** · bug · [`src/RAG/GraphStore/Neo4jGraphStore.php:108`](../src/RAG/GraphStore/Neo4jGraphStore.php#L108) · repro [`Neo4jGraphStoreRelationshipMapTest`](repro/RAG/Neo4jGraphStoreRelationshipMapTest.php) · fix validated

`Neo4jGraphStore::getRelationshipMap()` unwinds `relationships(path)` but collects only `[type(rel), endNode(rel).id]` grouped by the root `n1.id`, and the PHP code builds every triplet with that root as subject. For `Alice-KNOWS->Bob-KNOWS->Charlie` at depth 2 it returns `Alice KNOWS Bob` twice and `Alice KNOWS Charlie`, a relationship that does not exist. GraphRAG context built from it states false facts to the LLM and wastes tokens on duplicates. `LIMIT` also applies to aggregated subject rows, not relationships, so a dense graph can return a very large context. The existing live test only asserts `count >= 2`, which hides the problem.

Suggested fix: in `src/RAG/GraphStore/Neo4jGraphStore.php`, collect `DISTINCT [startNode(rel).id, type(rel), endNode(rel).id]` and build each `Triplet` from those three values, updating `Neo4jGraphStoreQueryTest` to feed `[start, type, end]` rows. This was validated in a sandbox against the repro and the module's tests; making `LIMIT` cap relationships is a separate point to decide.

### <a id="rag-12"></a>RAG-12 · getRelationshipMap() reads the uninitialized $client property instead of client()

**medium** · bug · [`src/RAG/GraphStore/Neo4jGraphStore.php:119`](../src/RAG/GraphStore/Neo4jGraphStore.php#L119) · repro [`Neo4jRelationshipMapClientTest`](repro/RAG/Neo4jRelationshipMapClientTest.php) · fix validated

Every other `Neo4jGraphStore` method obtains the driver through the lazy `$this->client()` hook, but `getRelationshipMap()` reads `$this->client` directly. When it is the first call on a fresh store with a non-empty subject list, for example a store built per request for retrieval only, it throws `Error: Typed property Neo4jGraphStore::$client must not be accessed before initialization`. Subclasses that supply their client by overriding the public `client()` hook are always affected. Existing tests missed it because their stub assigns `$this->client` in the constructor. Nothing in `src/` currently calls this method, so impact is limited to direct callers.

Suggested fix: in `src/RAG/GraphStore/Neo4jGraphStore.php`, change `$this->client->run(...)` to `$this->client()->run(...)` in `getRelationshipMap()`. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-13"></a>RAG-13 · Neo4jGraphStore ignores the $database constructor argument

**medium** · bug · [`src/RAG/GraphStore/Neo4jGraphStore.php:220`](../src/RAG/GraphStore/Neo4jGraphStore.php#L220) · repro [`Neo4jDatabaseTest`](repro/RAG/Neo4jDatabaseTest.php) · fix validated

`Neo4jGraphStore` accepts `$database` (default `neo4j`), but `client()` never applies it to the session configuration, so every query runs against the server's home database. A store configured with a non-default database, for example to keep a separate graph per tenant or domain, silently writes triples into and reads them from the wrong graph, which can mix or leak data between graphs in a multi-database setup. Users who keep the default on a server whose home database is `neo4j` are not affected.

Suggested fix: in `src/RAG/GraphStore/Neo4jGraphStore.php`, import `Laudis\Neo4j\Databags\SessionConfiguration` and add `->withDefaultSessionConfiguration(SessionConfiguration::default()->withDatabase($this->database))` after `->withDefaultDriver('default')` in `client()`. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-14"></a>RAG-14 · Retrieved content can close the EXTRA-CONTEXT delimiter and reach system-prompt level

**medium** · security · [`src/RAG/Nodes/InstructionsNode.php:38`](../src/RAG/Nodes/InstructionsNode.php#L38) · repro [`InstructionsNodeDelimiterTest`](repro/RAG/InstructionsNodeDelimiterTest.php) · fix validated

`InstructionsNode` concatenates the source type, source name and content of each retrieved document verbatim into a system-level block between `<EXTRA-CONTEXT>` tags. A document or source name containing `</EXTRA-CONTEXT>` followed by instructions closes the data section, so its text appears in the system prompt as if the developer wrote it. Retrieved content is often untrusted (uploads, crawled pages, and conversation memory stored by `ConversationIngestionNode` and read by `SemanticMemoryRetrieval`), and with memory the injected text persists across later turns and threads. Escaping cannot stop indirect prompt injection, but a closable delimiter makes breakout trivial; `buildBlockContent` is private, so applications cannot harden it.

Suggested fix: in `src/RAG/Nodes/InstructionsNode.php`, make `buildBlockContent()` protected and pass source type, source name and content through a protected `neutraliseDelimiters()` that rewrites `<EXTRA-CONTEXT` and `</EXTRA-CONTEXT` case-insensitively to `&lt;...`. This was validated in a sandbox against the repro and the module's tests; moving retrieved context out of the system role or using a per-request boundary token is a longer-term option to discuss.

### <a id="rag-15"></a>RAG-15 · reindexBySource fails for sources whose name or type is a numeric string

**medium** · bug · [`src/RAG/RAG.php:95`](../src/RAG/RAG.php#L95) · repro [`ReindexNumericSourceTest`](repro/RAG/ReindexNumericSourceTest.php) · fix validated

`RAG::reindexBySource()` groups documents in an array keyed by `sourceType` and `sourceName`. PHP converts numeric-string keys such as `'2024'` or `'42'` to integers, so the delete filter becomes `Filter::eq('sourceName', 2024)` and schema validation throws `DocumentSchemaException` (`expects string; int given`) with every backend. Sources named after record ids, years or numeric file names can never be reindexed. The error is loud rather than silent, but in a mixed batch the sources processed before the numeric one have already been replaced, leaving the reindex partially applied.

Suggested fix: in `reindexBySource()` in `src/RAG/RAG.php`, cast the keys back with `(string) $sourceType` and `(string) $sourceName` when building the delete filter. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-16"></a>RAG-16 · Text-less questions crash SimilarityRetrieval with a TypeError

**medium** · bug · [`src/RAG/Retrieval/SimilarityRetrieval.php:33`](../src/RAG/Retrieval/SimilarityRetrieval.php#L33) · repro [`TextlessQuestionTest`](repro/RAG/TextlessQuestionTest.php) · fix validated

`SimilarityRetrieval::retrieve()` passes `$query->getContent()` directly to `embedText(string)`, but `Message::getContent()` returns `null` when the message has no text blocks, such as an image-only or file-only question or a pre-processor that returns a text-less message. The whole RAG turn then aborts with an internal `TypeError` instead of answering without context or raising a descriptive exception. This affects `SimilarityRetrieval`, the default strategy, and `SemanticMemoryRetrieval`, which inherits it. History is not corrupted because pending messages commit only after inference succeeds.

Suggested fix: in `src/RAG/Retrieval/SimilarityRetrieval.php`, return `[]` from `retrieve()` when the query content is `null`, before embedding or searching. This was validated in a sandbox against the repro and the module's tests; the Cohere, Jina and LocalAI rerankers, which send `getContent()` as `query`, likely need the same guard as a follow-up.

### <a id="rag-17"></a>RAG-17 · minLength/minWords merging lets chunks grow far beyond maxLength/maxWords

**medium** · correctness · [`src/RAG/Splitter/DelimiterTextSplitter.php:133`](../src/RAG/Splitter/DelimiterTextSplitter.php#L133) · repro [`MinLengthMergeBoundTest`](repro/RAG/MinLengthMergeBoundTest.php) · fix validated

`DelimiterTextSplitter::enforceMinLength()` and `SentenceTextSplitter::enforceMinWords()` append each too-short chunk to the previous result without checking the maximum, and consecutive short chunks keep piling into the same chunk. With greedy packing this happens whenever the minimum exceeds about half the maximum: `DelimiterTextSplitter(maxLength: 10, minLength: 8)` turns 50 copies of `abcdefg` into one 399-character chunk, and `SentenceTextSplitter(maxWords: 6, minWords: 5)` produces one 80-word chunk. Since the maximum is usually set to fit an embedding model, such chunks are truncated (their tail is never retrievable) or rejected. A single merge going slightly over the limit is pinned by existing tests; the unbounded growth is the defect.

Suggested fix: in both methods, absorb a short chunk only when the previous result has not already exceeded the maximum, which bounds overflow to one short chunk, and document the maximum as a soft limit when a minimum is set. This was validated in a sandbox against the repro and the module's tests; a hard limit would require rebalancing words between neighbours, since "merge only if it fits" makes the minimum a no-op.

### <a id="rag-18"></a>RAG-18 · ChromaVectorStore sends the API key in an Authentication header instead of Authorization

**medium** · bug · [`src/RAG/VectorStore/ChromaVectorStore.php:55`](../src/RAG/VectorStore/ChromaVectorStore.php#L55) · repro [`ChromaAuthHeaderReproTest`](repro/RAG/ChromaAuthHeaderReproTest.php) · fix validated

When a `key` is configured, `ChromaVectorStore` sends `Authentication: Bearer <token>`, but Chroma token auth reads `Authorization` (or `X-Chroma-Token`). Against an auth-enabled Chroma, the normal setup for hosted or production servers, the constructor's `get_or_create` call and every later request are rejected with 401 or 403, so the store is unusable and the error points at the server rather than the typo. It fails closed: no auth is bypassed and the token only goes to the configured host. Local Chroma without auth is unaffected.

Suggested fix: in the constructor of `src/RAG/VectorStore/ChromaVectorStore.php`, rename the header key from `Authentication` to `Authorization`, and add the repro's tests to `ChromaVectorStoreTest.php`. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-19"></a>RAG-19 · Weaviate filter compiler picks valueInt for integer values on number properties

**medium** · bug · [`src/RAG/VectorStore/Compilers/WeaviateFilterCompiler.php:105`](../src/RAG/VectorStore/Compilers/WeaviateFilterCompiler.php#L105) · repro [`WeaviateNumberFilterReproTest`](repro/RAG/WeaviateNumberFilterReproTest.php) · fix validated

`WeaviateFilterCompiler::valueKey()` chooses the Weaviate value key from the PHP type of the value (or the first list element), without schema access. The schema validator accepts integers for `Float` fields, which the store creates as Weaviate `number`, so `Filter::gt('price', 10)` compiles to `valueInt: 10` and `in('price', [1, 2.5])` to `valueIntArray`, which Weaviate rejects for number properties. RAG searches with such a filter fail, and `delete()` with it removes nothing, which callers ignoring the response may not notice. Mixed lists starting with a float happen to work, so behaviour depends on value order. The rejection itself was inferred, not checked against a live server.

Suggested fix: give `WeaviateFilterCompiler` an optional `DocumentSchema` constructor argument, return `valueNumber`/`valueNumberArray` when the field's element type is `DocumentFieldType::Float` before the PHP-type fallback, and pass `$this->schema` from `search()` and `delete()` in `WeaviateVectorStore`. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-20"></a>RAG-20 · Elasticsearch store creates its index without a dense_vector mapping for embedding

**medium** · design · [`src/RAG/VectorStore/ElasticsearchVectorStore.php:85`](../src/RAG/VectorStore/ElasticsearchVectorStore.php#L85) · repro [`ElasticsearchIndexCreationMappingTest`](repro/RAG/ElasticsearchIndexCreationMappingTest.php) · fix validated

`ElasticsearchVectorStore::checkIndexStatus()` creates a missing index with mappings for content, source fields and schema fields, but not for `embedding`; the `dense_vector` mapping is applied only in `mapVectorDimension()`, which runs when the index already exists. The first document then decides the field type through dynamic mapping, which yields `float` on Elasticsearch 8.0 to 8.10 and for dimensions outside 128 to 4096 on later versions. With a `float` field every kNN `search()` fails, later instances hit an undefined-key warning and a rejected `putMapping`, and the only recovery is deleting and reindexing. Elsewhere the server's default vector settings replace the store's explicit cosine mapping.

Suggested fix: in `src/RAG/VectorStore/ElasticsearchVectorStore.php`, add a protected `vectorMapping(int $dimension)` helper, include `'embedding' => $this->vectorMapping(...)` in the create-index properties, set `$this->vectorDimSet = true` after creation, and reuse the helper in `mapVectorDimension()`. This was validated in a sandbox against the repro and the module's tests, after updating `test_first_document_creates_the_index_with_schema_mappings`, which pins the current body.

### <a id="rag-21"></a>RAG-21 · Elasticsearch search omits size, so topK above 10 returns only 10 documents

**medium** · bug · [`src/RAG/VectorStore/ElasticsearchVectorStore.php:208`](../src/RAG/VectorStore/ElasticsearchVectorStore.php#L208) · repro [`ElasticsearchSizeReproTest`](repro/RAG/ElasticsearchSizeReproTest.php) · fix validated

`ElasticsearchVectorStore::search()` sets `knn.k` to `topK` but sends no `size`, and Elasticsearch returns at most `size` hits (default 10). A request for 25 documents silently returns 10, so pipelines that fetch a wider candidate set for reranking or need more context lose recall with no error. The default `topK` of 4 is unaffected. The request shape was confirmed in a sandbox; the truncation itself relies on documented Elasticsearch behaviour, since no live server was available.

Suggested fix: add `'size' => $topK` to the request body in `search()` in `src/RAG/VectorStore/ElasticsearchVectorStore.php`, and update the exact-body assertion in `ElasticsearchVectorStoreTest.php` to expect `'size' => 20`. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-22"></a>RAG-22 · FileVectorStore concurrent add and delete lose documents and crash searches

**medium** · concurrency / data-loss · [`src/RAG/VectorStore/FileVectorStore.php:113`](../src/RAG/VectorStore/FileVectorStore.php#L113) · repro [`FileVectorStoreConcurrencyTest`](repro/RAG/FileVectorStoreConcurrencyTest.php) · fix validated

`FileVectorStore` has no locking. `delete()` streams into a fixed `<name>_tmp<ext>` file, then `unlink()`s the original before `rename()`. Under a multi-worker PHP server, documents appended between delete's read and its rename go to the old inode and are silently lost (the repro lost up to about 100 of 400 lines), a concurrent `search()` hits the missing file and throws a `TypeError` from `fclose(false)`, and two concurrent deleters truncate each other's temp file. This affects setups such as conversation memory ingestion on every turn or `reindexBySource()` running alongside other ingestion. The reported interleaving of large appends did not reproduce on local ext4 and is only a concern on network filesystems.

Suggested fix: in `src/RAG/VectorStore/FileVectorStore.php`, serialise `appendToFile()` and `delete()` with `flock(LOCK_EX)` on a sidecar `.lock` file and remove the `unlink()` so `rename()` replaces the file atomically. This was validated in a sandbox against the repro and the module's tests, except that `test_delete_removes_only_matching_documents_and_leaves_no_temporary_file` must allow the persistent lock file.

### <a id="rag-23"></a>RAG-23 · FileVectorStore::addDocuments([]) writes a blank line that breaks search and delete

**medium** · bug · [`src/RAG/VectorStore/FileVectorStore.php:207`](../src/RAG/VectorStore/FileVectorStore.php#L207) · repro [`FileVectorStoreEmptyBatchReproTest`](repro/RAG/FileVectorStoreEmptyBatchReproTest.php) · fix validated

`FileVectorStore::appendToFile()` always writes `implode(...) . PHP_EOL`, so an empty batch appends a lone newline. That line decodes to `null`, after which every unfiltered `search()` throws `VectorStoreException` (`Document with the following content has no embedding: `), filtered searches and every `delete()` throw a `TypeError` from `filterFields()`, and the store cannot be cleaned through its own API until the file is edited by hand. Existing documents are not lost, and the `RAG` facade never sends an empty batch, but a direct caller passing an empty result from a loader or filter leaves retrieval permanently broken.

Suggested fix: in `addDocuments()` in `src/RAG/VectorStore/FileVectorStore.php`, return `$this` early when `$documents === []`, as other stores do. This was validated in a sandbox against the repro and the module's tests; making `search()` and `delete()` skip undecodable lines is a possible follow-up.

### <a id="rag-24"></a>RAG-24 · FileVectorStore writes documents with invalid UTF-8 as blank lines, corrupting the store

**medium** · bug · [`src/RAG/VectorStore/FileVectorStore.php:207`](../src/RAG/VectorStore/FileVectorStore.php#L207) · repro [`FileVectorStoreEncodingReproTest`](repro/RAG/FileVectorStoreEncodingReproTest.php) · fix validated

`FileVectorStore` validates only that metadata encodes as JSON. When content or a source name contains invalid UTF-8, a realistic output of PDF, HTML or legacy-encoded loaders, `json_encode()` returns `false` and `implode` writes an empty line with no error. The document is silently lost, and from then on every `search()` throws `VectorStoreException` and `delete()` throws a `TypeError`, so the store is unusable until the file is repaired by hand. In a mixed batch the valid documents are written alongside the blank line, and the failure surfaces later, far from its cause.

Suggested fix: in `src/RAG/VectorStore/FileVectorStore.php`, encode each row in a protected `encodeRow()` using `JSON_THROW_ON_ERROR` and wrap `JsonException` in a `VectorStoreException`; since all rows are encoded before `file_put_contents`, a failing batch writes nothing. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-25"></a>RAG-25 · MariaDB upsert re-applies VEC_FromText to an already converted embedding on duplicate ids

**medium** · bug · [`src/RAG/VectorStore/MariaDBVectorStore.php:91`](../src/RAG/VectorStore/MariaDBVectorStore.php#L91) · repro [`MariaDBUpsertEmbeddingReproTest`](repro/RAG/MariaDBUpsertEmbeddingReproTest.php) · fix validated

The `MariaDBVectorStore` upsert uses `embedding = VEC_FromText(VALUES(embedding))` in `ON DUPLICATE KEY UPDATE`. `VALUES(embedding)` already holds the binary `VECTOR` produced by the insert's `VEC_FromText`, so converting it again parses binary as JSON and yields `NULL`. Re-adding a document whose id already exists, the case this statement exists for, then fails under strict `sql_mode` (leaving earlier rows of the batch written, since there is no transaction) or stores an invalid embedding in non-strict mode. Fresh inserts are unaffected. This was established from the MariaDB server source and the prepared SQL, not against a live server.

Suggested fix: in the `addDocuments()` upsert in `src/RAG/VectorStore/MariaDBVectorStore.php`, use `embedding = VALUES(embedding)`. This was validated in a sandbox against the repro and the module's tests; `MariaDBVectorStoreQueryTest` should also assert the full statement rather than its prefix.

### <a id="rag-26"></a>RAG-26 · MariaDB and Chroma report Euclidean-based scores, unlike cosine scores in other stores

**medium** · design · [`src/RAG/VectorStore/MariaDBVectorStore.php:150`](../src/RAG/VectorStore/MariaDBVectorStore.php#L150) · repro [`SimilarityScoreConsistencyTest`](repro/RAG/SimilarityScoreConsistencyTest.php) · fix validated

Every store reports `score = 1 - distance`, but `MariaDBVectorStore` ranks with `VEC_DISTANCE_EUCLIDEAN` and `ChromaVectorStore` creates collections without `hnsw:space: cosine`, so it uses squared L2, while Memory, File, Qdrant, Weaviate and others use cosine. With unit embeddings, the default `0.5` threshold of `FixedThresholdPostProcessor` drops documents below cosine 0.875 on MariaDB and 0.75 on Chroma, versus 0.5 elsewhere. Moving from the in-memory or file store to MariaDB or Chroma silently lowers recall, thresholds do not carry over, and with unnormalised embeddings scores go negative and ranking can differ from cosine.

Suggested fix: in `MariaDBVectorStore`, create the index with `DISTANCE=cosine` and search with `VEC_DISTANCE_COSINE`; in `ChromaVectorStore::initialize()`, add `'metadata' => ['hnsw:space' => 'cosine']`, and update the pinned SQL and request-body tests. This was validated in a sandbox against the repro and the module's tests; existing MariaDB indexes must be rebuilt and Chroma collections recreated, which belongs in the release notes.

### <a id="rag-27"></a>RAG-27 · Meilisearch search silently caps every topK above 20 at 20 results

**medium** · bug · [`src/RAG/VectorStore/MeilisearchVectorStore.php:141`](../src/RAG/VectorStore/MeilisearchVectorStore.php#L141) · repro [`MeilisearchTopKReproTest`](repro/RAG/MeilisearchTopKReproTest.php) · fix validated

`MeilisearchVectorStore::search()` builds the request with `'limit' => min($request->topK ?? $this->topK, 20)`, so any topK above 20 is silently reduced to 20. This applies both to a per-request `SearchRequest` topK and to the store's constructor default (a constructor `topK: 30` also sends `limit` 20). No error or warning is raised. RAG pipelines that over-fetch candidates for a reranker or post-filter quietly lose recall, and Meilisearch behaves differently from every other store behind the same interface.

Suggested fix: in `MeilisearchVectorStore::search()`, send `'limit' => $request->topK ?? $this->topK` and remove the now-unused `use function min;`, leaving Meilisearch's own `maxTotalHits` as the server-side ceiling. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-28"></a>RAG-28 · Meilisearch constructor ignores failed settings tasks and treats any lookup error as missing index

**medium** · error-handling · [`src/RAG/VectorStore/MeilisearchVectorStore.php:236`](../src/RAG/VectorStore/MeilisearchVectorStore.php#L236) · repro [`MeilisearchTaskReproTest`](repro/RAG/MeilisearchTaskReproTest.php) · fix validated

`waitForTask()` returns only on status `succeeded`: a `failed` task (for example an invalid embedder config) is polled ten times with a 500ms sleep and then accepted, and polling errors are swallowed. The constructor therefore blocks for about 5 seconds and returns a store whose embedder or filterable attributes were never applied, so problems surface later as confusing search errors or rejected filters. The constructor also catches any exception from `GET /indexes/{uid}` as "index missing"; a transient 5xx or network error triggers a create-index task that fails with `index_already_exists`, is swallowed, and adds another stall. Auth errors do still surface, but from the wrong request.

Suggested fix: in `MeilisearchVectorStore`, recreate the index only when the lookup's `HttpException` has status 404 and rethrow otherwise, and make `waitForTask()` throw a `VectorStoreException` with the task error message when the status is `failed` or `canceled`. This was validated in a sandbox against the repro and the module's tests; whether to also throw when the 5-second polling budget runs out is left open, since large-index settings tasks can legitimately take longer.

### <a id="rag-29"></a>RAG-29 · MongoDB search casts all metadata values to strings and discards the stored id

**medium** · bug · [`src/RAG/VectorStore/MongoDBVectorStore.php:185`](../src/RAG/VectorStore/MongoDBVectorStore.php#L185) · repro [`MongoDBVectorStoreSearchHydrationTest`](repro/RAG/MongoDBVectorStoreSearchHydrationTest.php) · fix validated

`MongoDBVectorStore::search()` hydrates metadata with `$document->addMetadata($key, (string) $value)`. Integers become numeric strings, `true` becomes `'1'`, `false` becomes `''`, and arrays or nested objects (including filterable `string[]` fields such as `tags`) become `'Array'` with an "Array to string conversion" warning, which makes `search()` throw under error handlers that promote warnings, as Laravel and Symfony do in dev. The `$project` stage also sets `'_id' => 0`, so every result gets a new random UUID. Stored data is intact, but every MongoDB user gets corrupted metadata and uncorrelatable ids on the read path, unlike the other backends.

Suggested fix: in `MongoDBVectorStore::search()`, drop `'_id' => 0` from `$project`, call `setId($item['_id'])`, replace the cast loop with `$document->setMetadata($item['metadata'] ?? [])`, and remove the unused `use function in_array;`. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-30"></a>RAG-30 · OpenSearch search ignores topK and returns the default page of ten hits

**medium** · bug · [`src/RAG/VectorStore/OpenSearchVectorStore.php:210`](../src/RAG/VectorStore/OpenSearchVectorStore.php#L210) · repro [`OpenSearchReproTest`](repro/RAG/OpenSearchReproTest.php) · fix validated

`OpenSearchVectorStore::search()` sends a knn query with `k = max(50, $topK * 4)` but no `size` in the request body, so OpenSearch returns its default page of 10 hits regardless of topK. With the default topK of 4, up to 10 documents are injected into the prompt, costing more tokens and adding noise. With a topK above 10, from the store default or a per-request `SearchRequest`, at most 10 come back and recall is silently capped. In both cases topK has no effect on the result count, unlike the Elasticsearch implementation.

Suggested fix: add `'size' => $topK` to `$searchParams['body']` in `OpenSearchVectorStore::search()`, keeping the `k` oversampling for filtered queries. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-31"></a>RAG-31 · Typesense bulk import silently drops documents rejected by the server

**medium** · bug · [`src/RAG/VectorStore/TypesenseVectorStore.php:180`](../src/RAG/VectorStore/TypesenseVectorStore.php#L180) · repro [`TypesenseImportReproTest`](repro/RAG/TypesenseImportReproTest.php) · fix validated

Typesense answers `documents->import()` with HTTP 200 and one `{"success": false, "error": ...}` line per rejected document, but `TypesenseVectorStore::addDocuments()` discards that result. Documents rejected for a wrong embedding dimension, a type mismatch or a missing required field are left out of the collection with no error, so ingestion pipelines have no signal to retry or alert and retrieval later returns incomplete results. From reading the code only, the Elasticsearch and OpenSearch `bulk()` paths ignore `errors: true` the same way, and `ElasticsearchVectorStore::addDocuments()` initialises `$params` once outside the chunk loop, so batches over 100 re-send earlier chunks.

Suggested fix: pass the records to `import()` as an array and add a protected `assertImported()` in `TypesenseVectorStore` that throws a `VectorStoreException` listing every line with `success !== true`, updating `test_bulk_import_is_split_into_chunks_of_one_hundred_lines` to return realistic `{"success":true}` bodies. This was validated in a sandbox against the repro and the module's tests; the analogous `errors` check for Elasticsearch and OpenSearch, and moving `$params` inside the Elasticsearch chunk loop, were not validated.

### <a id="rag-32"></a>RAG-32 · Typesense, Elasticsearch, OpenSearch and MongoDB search results lose the stored document id

**medium** · design · [`src/RAG/VectorStore/TypesenseVectorStore.php:218`](../src/RAG/VectorStore/TypesenseVectorStore.php#L218) · repro [`StoredIdReproTest`](repro/RAG/StoredIdReproTest.php) · fix validated

Typesense stores `id` but its search mapper never calls `setId()`; Elasticsearch and OpenSearch neither send an `_id` when indexing nor read `_id` back; MongoDB projects `'_id' => 0`. Every returned Document therefore carries a fresh random UUID that changes on each call, so applications cannot cite, dedupe, record feedback on or link results to stored chunks, and behaviour changes when switching from stores that preserve ids such as Pinecone or Qdrant. On Elasticsearch and OpenSearch, re-adding the same Document creates duplicate chunks instead of overwriting, although `reindexBySource` hides this on the main re-ingestion path.

Suggested fix: call `setId()` from the stored id in the search mappers of `TypesenseVectorStore`, `ElasticsearchVectorStore`, `OpenSearchVectorStore` and `MongoDBVectorStore`, send the document id as `id`/`_id` in the Elasticsearch and OpenSearch `addDocument()`/`addDocuments()` calls, and project `_id` in MongoDB, updating the Elasticsearch and OpenSearch request fixtures to match. This was validated in a sandbox against the repro and the module's tests; the MongoDB part overlaps with RAG-29.

### <a id="rag-33"></a>RAG-33 · FileDataLoader never matches readers registered with uppercase or dot-prefixed extensions

**low** · api-inconsistency · [`src/RAG/DataLoader/FileDataLoader.php:52`](../src/RAG/DataLoader/FileDataLoader.php#L52) · repro [`FileDataLoaderReaderKeyTest`](repro/RAG/FileDataLoaderReaderKeyTest.php) · fix validated

`FileDataLoader::getContentFromFile()` lowercases the file extension before looking up a reader, but `addReader()`, `setReaders()` and the constructor store keys exactly as given. A reader registered as `'PDF'`, `'MD'` or `'.md'` is therefore never used, and matching files silently fall back to `TextFileReader`: binary PDFs or DOCX files are indexed as garbage text and HTML keeps its markup. No error or warning appears, so the problem only shows up as poor retrieval quality for developers who chose a non-canonical key.

Suggested fix: in `FileDataLoader`, store keys as `strtolower(ltrim($extension, '.'))` in `addReader()` and route `setReaders()` through `addReader()` with a `(string)` cast on the key. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-34"></a>RAG-34 · PdfReader runs a sibling pdftotext instead of the executable configured with setBinPath

**low** · api-inconsistency · [`src/RAG/DataLoader/PdfReader.php:73`](../src/RAG/DataLoader/PdfReader.php#L73) · repro [`PdfReaderBinPathTest`](repro/RAG/PdfReaderBinPathTest.php) · fix validated

`PdfReader::setBinPath()` checks that the path is executable, but `findPdfToText()` only uses `dirname($binPath) . '/pdftotext'`. A configured executable with another name, such as `pdftotext-24.02` or a wrapper script, is never run: extraction fails with a misleading "pdftotext binary was not found" error, or, when a system `pdftotext` exists in the standard paths, that binary is silently run instead, bypassing any sandboxing, timeouts or version pinning the wrapper provided. A directory also passes the executable check, after which its parent directory is searched.

Suggested fix: in `PdfReader`, make `setBinPath()` also require `is_file()` and have `findPdfToText()` return `$this->binPath ?? $this->findBinary('pdftotext')`, keeping the `dirname()` lookup in `findBinary()` for the sibling `pdfinfo`. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-35"></a>RAG-35 · TextFileReader and HtmlReader do not handle unreadable files

**low** · bug · [`src/RAG/DataLoader/TextFileReader.php:13`](../src/RAG/DataLoader/TextFileReader.php#L13) · repro [`ReaderFailureTest`](repro/RAG/ReaderFailureTest.php) · fix validated

`TextFileReader::getText()` returns `file_get_contents()` directly from a method typed `string`, so an unreadable or vanished file produces a PHP warning followed by a `TypeError` that does not name the file. `HtmlReader::getText()` passes `false` on and returns `''`. In practice, single-file loading swallows the error and returns `[]`, while loading a directory with a dangling symlink, a deleted file or a permission-denied entry aborts the whole ingestion with an unhelpful `TypeError`; with `.html` files the unreadable file is silently indexed as an empty document.

Suggested fix: in `TextFileReader::getText()`, throw a `DataReaderException` naming the path when `file_get_contents()` returns `false`, and have `HtmlReader` read through `TextFileReader::getText()` instead of calling `file_get_contents()` itself. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-36"></a>RAG-36 · OpenAI and Voyage embedDocuments ignore the response index when assigning embeddings

**low** · bug · [`src/RAG/Embeddings/OpenAIEmbeddingsProvider.php:60`](../src/RAG/Embeddings/OpenAIEmbeddingsProvider.php#L60) · repro [`EmbeddingsResponseIndexTest`](repro/RAG/EmbeddingsResponseIndexTest.php) · fix validated

`OpenAIEmbeddingsProvider::embedDocuments()` and `VoyageEmbeddingsProvider::embedDocuments()` assign embeddings by each item's position in `response['data']` and ignore the `index` field the API returns for matching inputs. If a server returns items out of order, embeddings are silently attached to the wrong documents and the vector store is filled with mismatched vectors, so retrieval returns wrong chunks without any error. This affects OpenAI, Mistral, `OpenAILikeEmbeddings` and Voyage users, mostly with compatible or proxy servers, since the official endpoints return items in order. There is also no count check: a short response leaves documents without embeddings and a malformed body raises PHP warnings or `TypeError`s.

Suggested fix: in both `embedDocuments()` methods, assign with `$chunk[$item['index'] ?? $position]->setEmbedding($item['embedding'])`, and change the fixture in `tests/RAG/Embeddings/VoyageEmbeddingsProviderTest.php` to per-request 0-based indexes. This was validated in a sandbox against the repro and the module's tests; an additional count check was not validated.

### <a id="rag-37"></a>RAG-37 · Unused QueryPreProcessEvent class with a docblock referencing a removed node

**low** · dead-code · [`src/RAG/Events/QueryPreProcessEvent.php:12`](../src/RAG/Events/QueryPreProcessEvent.php#L12) · no repro (static evidence) · fix validated

`src/RAG/Events/QueryPreProcessEvent.php` is not referenced anywhere in `src`, tests or docs, and its docblock says it is emitted by `PrepareRAGNode`, a class that no longer exists. Nothing breaks at runtime, but its name differs from the real `QueryPreProcessedEvent` only by "ed", so a developer writing middleware or a custom RAG node could type-hint the unused event and the handler would never run.

Suggested fix: delete `src/RAG/Events/QueryPreProcessEvent.php`; backward compatibility is not a concern on this branch. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-38"></a>RAG-38 · RetrievalNode deduplication keeps the last duplicate's data at the first duplicate's rank

**low** · bug · [`src/RAG/Nodes/RetrievalNode.php:51`](../src/RAG/Nodes/RetrievalNode.php#L51) · repro [`DeduplicationKeepsFirstTest`](repro/RAG/DeduplicationKeepsFirstTest.php) · fix validated

`RetrievalNode` deduplicates with `$docs[md5($document->getContent())] = $document`. PHP keeps the key's original position but overwrites the value, so the best-ranked occurrence is replaced by the last duplicate, with its lower score, different source name and metadata, while staying in the first position. With `CompositeRetrieval` over overlapping sources or a store holding duplicate chunks, the context credits the wrong source and score-based post-processors see a score that does not match the rank, so a threshold processor can drop a document whose best match cleared the threshold. The content text sent to the LLM is unchanged.

Suggested fix: in `RetrievalNode`, keep the first occurrence with `$docs[md5($document->getContent())] ??= $document;`. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-39"></a>RAG-39 · RAG observability events serialize full documents including embedding vectors

**low** · design · [`src/RAG/Observability/Retrieved.php:31`](../src/RAG/Observability/Retrieved.php#L31) · repro [`RAGObservabilityPayloadSizeTest`](repro/RAG/RAGObservabilityPayloadSizeTest.php) · fix validated

The `Retrieved`, `PostProcessing` and `PostProcessed` events put Document objects directly into `toArray()`, which serialize with the complete embedding vector and all metadata. With `LogListener` or any exporter attached and a store that returns embeddings, such as `MemoryVectorStore`, each document adds about 29 KB at 1536 dimensions, repeated for every post-processor, so a single RAG turn can produce several MB of log context, raising ingestion cost and risking record-size limits. The metadata also exposes values, such as a tenant filter value, that `Retrieving` deliberately omits; since content and the question are logged in full anyway, that part is a policy inconsistency rather than a security exposure.

Suggested fix: add a `DocumentsPayload` trait in `src/RAG/Observability/` that serializes documents without the `embedding` key, use it in the three events, and update `RAGObservabilityTest` to read contents from the array payload. This was validated in a sandbox against the repro and the module's tests; whether to also project or drop metadata is a separate decision.

### <a id="rag-40"></a>RAG-40 · Rerankers send a remote API request even when no documents were retrieved

**low** · bug · [`src/RAG/PostProcessor/CohereRerankerPostProcessor.php:43`](../src/RAG/PostProcessor/CohereRerankerPostProcessor.php#L43) · repro [`RerankerEmptyDocumentsTest`](repro/RAG/RerankerEmptyDocumentsTest.php) · fix validated

`CohereRerankerPostProcessor`, `JinaRerankerPostProcessor` and `LocalAIRerankerPostProcessor` always send a rerank request, even when `process()` receives an empty document list, which happens with an empty index, a filter scope that matches nothing, or an earlier threshold processor that drops everything. Every such turn makes a pointless authenticated call, adding latency and, for Cohere and Jina, cost, and it makes the turn depend on remote availability. If the provider rejects the empty list, which is plausible but was not verified against the live services, the resulting `HttpException` fails a turn that should have proceeded with no context.

Suggested fix: return `[]` at the top of `process()` in all three rerankers when `$documents === []`. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-41"></a>RAG-41 · Rerankers mis-send and mis-map documents when the input array is not a list

**low** · bug · [`src/RAG/PostProcessor/CohereRerankerPostProcessor.php:52`](../src/RAG/PostProcessor/CohereRerankerPostProcessor.php#L52) · repro [`RerankerDocumentListTest`](repro/RAG/RerankerDocumentListTest.php) · fix validated

The Cohere, Jina and LocalAI rerankers build the payload with `array_map`, which keeps input keys, and resolve results with `$documents[$item['index']]`. When `process()` receives a non-list array, for example from a custom post-processor that uses `array_filter()` without `array_values()`, the payload is JSON-encoded as an object, and the API's positional index is looked up against the original keys. Depending on the response, the provider rejects the request, the wrong document is silently returned with another document's score, or the turn crashes with `Call to a member function setScore() on null`. The default pipeline is not affected because every built-in stage returns a list.

Suggested fix: make `$documents = array_values($documents);` the first line of `process()` in the three rerankers. This was validated in a sandbox against the repro and the module's tests; declaring `list<Document>` on `PostProcessorInterface` is a broader alternative.

### <a id="rag-42"></a>RAG-42 · Rerankers crash with engine errors on malformed or out-of-range rerank responses

**low** · robustness / input-validation · [`src/RAG/PostProcessor/CohereRerankerPostProcessor.php:59`](../src/RAG/PostProcessor/CohereRerankerPostProcessor.php#L59) · repro [`RerankerResponseValidationTest`](repro/RAG/RerankerResponseValidationTest.php) · fix validated

All three rerankers map `$result['results']` with `$documents[$item['index']]` without any validation. An out-of-range or negative index, from a buggy self-hosted LocalAI, a proxy or an API change, produces an "Undefined array key" warning and then `Error: Call to a member function setScore() on null`; a missing `results` key or a non-JSON body produces a `TypeError` from `array_map()`. The RAG turn dies inside `PostProcessNode` with an engine-level error instead of a catchable domain exception, and duplicate indexes silently return the same document twice with the last score.

Suggested fix: add a `MapsRerankResults` trait in `src/RAG/PostProcessor/` whose `rankedDocuments()` throws `ProviderException` when `results` is missing or an index is unknown, repeated or has a non-numeric score, and use it in `CohereRerankerPostProcessor`, `JinaRerankerPostProcessor` and `LocalAIRerankerPostProcessor`, which also removes the duplicated mapping code. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-43"></a>RAG-43 · Threshold post-processors keep or drop unscored documents depending on the threshold value

**low** · bug · [`src/RAG/PostProcessor/FixedThresholdPostProcessor.php:30`](../src/RAG/PostProcessor/FixedThresholdPostProcessor.php#L30) · repro [`UnscoredDocumentThresholdTest`](repro/RAG/UnscoredDocumentThresholdTest.php) · fix validated

`FixedThresholdPostProcessor` filters with `$document->getScore() >= $threshold` on a nullable score, and PHP compares `null` with a number loosely, so an unscored document is kept at threshold `0.0` but dropped at `-0.5` or `0.5`, although negative cosine thresholds are legitimate. `AdaptiveThresholdPostProcessor` has the same comparison and also counts null scores as `0.0` in the median and MAD, skewing the threshold; with low or negative scores it clamps to 0, keeping unscored documents while dropping scored ones. This contradicts the RAG rule that null means "no score", but only affects custom retrievals or stores, since built-in stores and rerankers always set scores.

Suggested fix: require `getScore() !== null` in the `FixedThresholdPostProcessor` filter, and filter out unscored documents at the start of `AdaptiveThresholdPostProcessor::process()` before the count check. This was validated in a sandbox against the repro and the module's tests; the `max(0, threshold)` clamp in the adaptive processor is a separate point to discuss.

### <a id="rag-44"></a>RAG-44 · SimilarityRetrieval loses documents from stores returning paged or keyed generators

**low** · bug · [`src/RAG/Retrieval/SimilarityRetrieval.php:39`](../src/RAG/Retrieval/SimilarityRetrieval.php#L39) · repro [`PagedStoreResultsReproTest`](repro/RAG/PagedStoreResultsReproTest.php) · fix validated

`SimilarityRetrieval` converts a non-array `search()` result with `iterator_to_array($documents)`, which preserves keys. A store that streams pages with `yield from` restarts keys at 0 on each page, so later documents overwrite earlier ones: pages `[[First, Second], [Third]]` return `[Third, Second]` with no error, and the agent answers with less context than the store returned. A generator keyed by document id yields a non-list array for callers such as `RetrievalTool`. No built-in store returns a generator today, so only custom or third-party stores are affected.

Suggested fix: use `iterator_to_array($documents, false)` in `SimilarityRetrieval`. This was validated in a sandbox against the repro and the module's tests; wrapping the array branch in `array_values()` would also normalise keyed arrays but was not validated.

### <a id="rag-45"></a><a id="rag-47"></a>RAG-45 · Identifier regex in DocumentField and MariaDBFilterCompiler accepts a trailing newline

**low** · security · [`src/RAG/Schema/DocumentField.php:17`](../src/RAG/Schema/DocumentField.php#L17) · repro [`DocumentFieldNameTest`](repro/RAG/DocumentFieldNameTest.php) · fix validated  
Also covers **RAG-47** (low, [`src/RAG/VectorStore/Compilers/MariaDBFilterCompiler.php:166`](../src/RAG/VectorStore/Compilers/MariaDBFilterCompiler.php#L166), repro [`CompilerReproTest`](repro/RAG/CompilerReproTest.php))

`DocumentField` and `MariaDBFilterCompiler::column()` validate identifiers with `/^[A-Za-z_][A-Za-z0-9_]*$/`, and without the `D` modifier `$` also matches before a final newline, so `"tenant\n"` is accepted. The name then flows unescaped into backend filter strings and index settings (Typesense `filter_by`, Meilisearch filterable attributes and others) or into the MariaDB JSON path as `JSON_VALUE(metadata, '$.tenant\n')`, producing malformed or non-matching filters at query time instead of failing at schema build. Only one trailing newline gets through and names come from developer code, so there is no injection today, but this regex is the only guard between field names and interpolated SQL or filter strings.

Suggested fix: add the `D` modifier (or use `\z`) to the pattern in `src/RAG/Schema/DocumentField.php` and `src/RAG/VectorStore/Compilers/MariaDBFilterCompiler.php`. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-46"></a>RAG-46 · SentenceTextSplitter treats lowercase accented letters as sentence starts

**low** · bug · [`src/RAG/Splitter/SentenceTextSplitter.php:137`](../src/RAG/Splitter/SentenceTextSplitter.php#L137) · repro [`SentenceBoundaryTest`](repro/RAG/SentenceBoundaryTest.php) · fix validated

The sentence-boundary lookahead in `SentenceTextSplitter` uses the class `[A-ZÀ-Ÿ]` with `/u`, which spans U+00C0 to U+0178 and therefore includes lowercase Latin-1 and Latin Extended-A letters. A lowercase accented word after a period, `!`, `?` or ellipsis is treated as a new sentence, so `'See fig. école now.'` splits into `'See fig.'` and `'école now.'`, making chunks less coherent for French, Spanish, German and similar text. The class also misses uppercase letters outside that range, such as `Ł` or Greek and Cyrillic capitals, so those real boundaries are not detected. No content is lost.

Suggested fix: replace `[A-ZÀ-Ÿ]` with `\p{Lu}` in the pattern in `SentenceTextSplitter`. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-48"></a>RAG-48 · FileVectorStore name is not validated and can place the store file outside its directory

**low** · security · [`src/RAG/VectorStore/FileVectorStore.php:62`](../src/RAG/VectorStore/FileVectorStore.php#L62) · repro [`FileVectorStoreNameReproTest`](repro/RAG/FileVectorStoreNameReproTest.php) · fix validated

`FileVectorStore::getFilePath()` concatenates directory, `name` and extension without checking for path separators or `..`, and the constructor then `touch()`es that path. A name like `'../escaped'` silently creates the store outside the configured directory, and every add, search and delete reads or writes it; `delete()` also writes `<name>_tmp<ext>` there and renames it into place. Applications that pass a tenant or user id as `name` could let one tenant create, overwrite or read another's `*.store` files, including `FilePersistence` files that share the extension. Fixed or default names are not affected, and the input is a developer-controlled constructor argument.

Suggested fix: in the `FileVectorStore` constructor, before `mkdir`/`touch`, call a protected check that throws a `VectorStoreException` when the file name is empty, `.`, `..` or contains `/`, `\` or a null byte (alternatively `rawurlencode()` the name as `FilePersistence` does), and give `test_store_file_that_cannot_be_created_is_reported` another way to make `touch()` fail. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-49"></a>RAG-49 · Memory and file stores reject raw filters only when a document reaches them

**low** · design · [`src/RAG/VectorStore/Filter/FilterEvaluator.php:41`](../src/RAG/VectorStore/Filter/FilterEvaluator.php#L41) · repro [`RawFilterEagerRejectionTest`](repro/RAG/RawFilterEagerRejectionTest.php) · fix validated

`FilterValidator` lets any `RawFilter` through, and `FilterEvaluator` throws only when it actually evaluates one. On an empty store, or when an `allOf`/`anyOf` group short-circuits before the raw node, `MemoryVectorStore` and `FileVectorStore` `search()` and `delete()` silently accept a filter written for another backend. This breaks the "fail loud on a store swap" rule: a test suite using an in-memory store can pass with a non-portable filter that fails elsewhere or only after data changes. Results are never wrong. When `FileVectorStore::delete()` does reach the raw node, it throws after opening the `_tmp` file and leaves it orphaned.

Suggested fix: add a tree walk to `FilterEvaluator` that rejects any `RawFilter` up front and call it right after `validateFilters()` in `search()` and `delete()` of both stores, before the tmp file is opened in `FileVectorStore::delete()`. This was validated in a sandbox against the repro and the module's tests; as proposed it adds a public `assertEvaluable()` method, which needs approval, and a protected trait helper is an alternative.

### <a id="rag-50"></a>RAG-50 · A stored row containing a reserved metadata key breaks the whole search

**low** · bug · [`src/RAG/VectorStore/MariaDBVectorStore.php:175`](../src/RAG/VectorStore/MariaDBVectorStore.php#L175) · repro [`MariaDBReservedMetadataReproTest`](repro/RAG/MariaDBReservedMetadataReproTest.php) · fix validated

`MariaDBVectorStore`, `WeaviateVectorStore` and `MongoDBVectorStore` hydrate metadata by skipping only `content`, `sourceType`, `sourceName`, `score`, `embedding` and `id`, then calling `addMetadata()`, which throws for the other keys in `DocumentSchema::RESERVED_FIELDS` (`metadata`, `_neuron_metadata`, `_vectors`, `_rankingScore`, `vector_distance`). A single row whose metadata contains one of these keys, written by another tool or before the keys were reserved, makes every search that returns it throw `DocumentSchemaException`, so one bad row takes down retrieval for all matching queries until the data is cleaned by hand.

Suggested fix: in the three stores' hydration loops, replace the hard-coded list with `in_array($key, DocumentSchema::RESERVED_FIELDS, true)`; all three already import `DocumentSchema`. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-51"></a>RAG-51 · Qdrant store sends integer document ids as strings, which Qdrant rejects

**low** · bug · [`src/RAG/VectorStore/QdrantVectorStore.php:97`](../src/RAG/VectorStore/QdrantVectorStore.php#L97) · repro [`QdrantIdReproTest`](repro/RAG/QdrantIdReproTest.php) · fix validated

Qdrant point ids must be unsigned integers or UUIDs, but `QdrantVectorStore::addDocuments()` casts every id with `(string)`. A Document with `setId(42)`, for example to line points up with relational rows, is sent as `"42"`, and every upsert fails with a 400 "Unable to parse UUID". Integer ids are a supported Document input, so users relying on them cannot store documents in Qdrant at all. Default UUID ids and explicit UUID strings are unaffected, and the failure is loud rather than silent.

Suggested fix: send `'id' => $document->getId()` without the cast in `QdrantVectorStore::addDocuments()`, so integers stay JSON integers and UUID strings pass through unchanged. This was validated in a sandbox against the repro and the module's tests.

### <a id="rag-52"></a>RAG-52 · Typesense addDocuments silently replaces a document with invalid UTF-8 by a blank import line

**medium** · bug · [`src/RAG/VectorStore/TypesenseVectorStore.php:166`](../src/RAG/VectorStore/TypesenseVectorStore.php#L166) · repro [`TypesenseInvalidUtf8ReproTest`](repro/RAG/TypesenseInvalidUtf8ReproTest.php) · fix validated

`TypesenseVectorStore::addDocuments()` builds each NDJSON import line with `json_encode([...])` without flags or error checking. When content, `sourceType`, `sourceName` or scalar metadata contain invalid UTF-8, common in text extracted from PDFs or legacy files, `json_encode` returns `false` and the line becomes an empty string in the bulk body. Typesense rejects that line, the per-line import results are ignored, and `addDocuments()` returns normally. The document is lost from search without any signal to the caller or to observability, while the rest of the batch imports normally.

Suggested fix: pass `JSON_THROW_ON_ERROR` to the per-document `json_encode` in `src/RAG/VectorStore/TypesenseVectorStore.php` (matching the existing `@throws JsonException` docblock), so a bad document rejects the batch before any import request is sent. This was validated in a sandbox against the repro and the module's tests. `JSON_INVALID_UTF8_SUBSTITUTE` would keep the document instead, but conflicts with `MetadataMapper`, which throws on the same input.

### <a id="rag-53"></a>RAG-53 · FileVectorStore::delete() uses a fixed temp path that overwrites sibling stores and follows symlinks

**medium** · bug · [`src/RAG/VectorStore/FileVectorStore.php:92`](../src/RAG/VectorStore/FileVectorStore.php#L92) · repro [`FileVectorStoreTemporaryFileFilesystemSecurityTest`](repro/RAG/FileVectorStoreTemporaryFileFilesystemSecurityTest.php) · fix validated

`FileVectorStore::delete()` writes surviving rows to `<name>_tmp<ext>` with `fopen('w')` and renames it over the store. That path is also the file of a store named `<name>_tmp`, so a single, non-concurrent delete on store `docs` (including via `RAG::reindexBySource()`) destroys every document in `docs_tmp`, whose next search then crashes with an `fopen` warning and a `TypeError`. Because `fopen('w')` follows symlinks, anyone who can write to the store directory can plant a link there: the store rows are written to its target and the store file itself becomes that symlink. The symlink case needs local write access and is defense-in-depth; the name collision needs no attacker.

Suggested fix: in `src/RAG/VectorStore/FileVectorStore.php::delete()`, create the temp file with `tempnam()` in the store directory, drop the `unlink()` before `rename()`, and remove the temp file in a `finally` block. This was validated in a sandbox against the repro and the module's tests; creating the directory and store file with `0700`/`0600` is optional hardening.

## <a id="module-structuredoutput"></a>Structured output

StructuredOutput has 30 findings, none rated high. Together they show that the schema, the Deserializer and the Validator disagree with each other. The key ones are [STRUCTUREDOUTPUT-01](#structuredoutput-01) (malformed output skips the retry loop as a TypeError), [STRUCTUREDOUTPUT-02](#structuredoutput-02) (model output writes protected, private and static properties), [STRUCTUREDOUTPUT-03](#structuredoutput-03) (required fields are not enforced) and [STRUCTUREDOUTPUT-05](#structuredoutput-05) ("false" becomes true). Several schema features the framework advertises cannot actually be deserialized: pure enums, enum arrays, unions, DateTime and int-backed enums ([STRUCTUREDOUTPUT-06](#structuredoutput-06), [STRUCTUREDOUTPUT-07](#structuredoutput-07), [STRUCTUREDOUTPUT-09](#structuredoutput-09), [STRUCTUREDOUTPUT-10](#structuredoutput-10), [STRUCTUREDOUTPUT-11](#structuredoutput-11)).

### <a id="structuredoutput-01"></a>STRUCTUREDOUTPUT-01 · Malformed model output escapes the Deserializer as TypeError or Error, skipping structured-output retries

**medium** · bug · [`src/StructuredOutput/Deserializer/Deserializer.php:66`](../src/StructuredOutput/Deserializer/Deserializer.php#L66) · repro [`DeserializerTypeMismatchTest`](repro/StructuredOutput/DeserializerTypeMismatchTest.php) · fix validated

`StructuredOutputNode` only retries on `AgentException` or `DeserializerException`, but several ordinary model mistakes surface as a raw `TypeError` or `Error` from the `Deserializer`: a scalar where a nested object is expected (`setValue` fails), a scalar for an array property or scalar items in typed or `anyOf` arrays (`array_map` and the `fn (array $item)` closures), `"1"` for an int-backed enum (`tryFrom` under `strict_types`), and an object for an enum or abstract class ("Cannot instantiate enum"). The run aborts on the first attempt and `maxRetries` has no effect; the same applies to tools whose `ObjectProperty` maps arguments to a class. A top-level scalar or `null` JSON cannot reach the Deserializer through the agent, because `JsonExtractor` turns it into a retried `AgentException`, but direct callers of `Deserializer::fromJson` get a `TypeError` instead of the documented `DeserializerException`.

Suggested fix: in `src/StructuredOutput/Deserializer/Deserializer.php`, reject non-array input in `fromJson()`, reject abstract and enum classes in `deserializeObject()`, check enums before classes and reject non-array values in `handleSingleObject()`, validate array and item shapes in `handleArray()`, and catch `TypeError` from `tryFrom` in `handleEnum()`, raising `DeserializerException` in every case. This was validated in a sandbox against the repro and the module's tests; whether numeric strings should be coerced for int-backed enums is left open.

### <a id="structuredoutput-02"></a>STRUCTUREDOUTPUT-02 · Deserializer hydrates protected, private and static properties from untrusted model output

**medium** · security · [`src/StructuredOutput/Deserializer/Deserializer.php:82`](../src/StructuredOutput/Deserializer/Deserializer.php#L82) · repro [`DeserializerMassAssignmentTest`](repro/StructuredOutput/DeserializerMassAssignmentTest.php) · fix validated

`deserializeObject` iterates `$reflection->getProperties()` without any visibility or static filter and writes every matching JSON key (exact, snake_case or camelCase) through `ReflectionProperty::setValue`. A prompt-injected or hallucinated response can therefore overwrite internal state the schema never exposed, such as a protected `$isAdmin` flag or a private token, and can overwrite static properties, which changes process-wide state shared by later requests in long-lived workers. `JsonSchema::generateClassSchema` also lists public static properties, inviting the model to fill them. The same path hydrates class-typed tool arguments via `ObjectProperty`. Exploitation requires a DTO with non-public or static state whose integrity matters.

Suggested fix: in `Deserializer::deserializeObject()` use `getProperties(ReflectionProperty::IS_PUBLIC)` and skip static properties, and skip static properties in `JsonSchema::generateClassSchema()` (optionally also in `Validation/Validator.php`). This was validated in a sandbox against the repro and the module's tests; promoted non-public constructor properties are no longer hydrated, which matches the schema.

### <a id="structuredoutput-03"></a>STRUCTUREDOUTPUT-03 · Deserializer silently leaves missing or null required properties uninitialized

**medium** · correctness / design (schema-vs-runtime contract mismatch) · [`src/StructuredOutput/Deserializer/Deserializer.php:91`](../src/StructuredOutput/Deserializer/Deserializer.php#L91) · repro [`DeserializerRequiredPropertyTest`](repro/StructuredOutput/DeserializerRequiredPropertyTest.php) · fix validated

`deserializeObject` skips every `null` value, so a required property the model omits, or sends as `null`, stays uninitialized without any error, even though `JsonSchema` advertises it as required. `Agent::structured()` then returns a partially initialized object instead of retrying (the verifier saw a single provider call where a retry was expected), and the caller later hits "must not be accessed before initialization" far from the cause, unless every field also carries `IsNotNull` or `NotBlank`. A minor, debatable side effect is that an explicit `null` for a nullable property with a non-null default keeps the default. Existing tests currently codify the lenient behaviour, so this is also a design decision.

Suggested fix: in `Deserializer::deserializeObject()` throw `DeserializerException` when a required property (non-nullable, no default, honouring `SchemaProperty` required and promoted constructor defaults) is missing or null, and assign explicit nulls to nullable properties; `JsonSchema` should apply the same promoted-default rule. This was validated in a sandbox against the repro and the module's tests, but about 12 existing StructuredOutput fixtures that omit required properties must be updated.

### <a id="structuredoutput-04"></a>STRUCTUREDOUTPUT-04 · Readonly promoted constructor properties crash deserialization with "Cannot modify readonly property"

**medium** · bug · [`src/StructuredOutput/Deserializer/Deserializer.php:109`](../src/StructuredOutput/Deserializer/Deserializer.php#L109) · repro [`ReadonlyPromotedPropertyTest`](repro/StructuredOutput/ReadonlyPromotedPropertyTest.php) · fix validated

`deserializeObject` first assigns every property with `setValue`, then invokes a public constructor with zero required parameters, passing the promoted values again. For a DTO such as `__construct(public readonly string $title = 'untitled')`, the constructor re-initializes the readonly property and PHP throws `Error: Cannot modify readonly property`. This happens precisely when the model supplies the value, which is the normal case, and since it is not a `DeserializerException`, `Agent::structured()` fails without retrying even though the answer was valid. Immutable DTOs with defaults are a common pattern; the workaround is dropping `readonly`.

Suggested fix: in `Deserializer::deserializeObject()`, decide before the property loop whether the constructor will run, and in that case pass promoted values only as constructor arguments instead of pre-assigning them. This was validated in a sandbox against the repro and the module's tests; a non-promoted readonly property assigned in the constructor body remains uncovered and could be handled separately.

### <a id="structuredoutput-05"></a>STRUCTUREDOUTPUT-05 · Lossy scalar casts turn "false" into true and non-numeric strings into 0

**medium** · bug · [`src/StructuredOutput/Deserializer/Deserializer.php:178`](../src/StructuredOutput/Deserializer/Deserializer.php#L178) · repro [`DeserializerLossyCoercionTest`](repro/StructuredOutput/DeserializerLossyCoercionTest.php) · fix validated

`castToSingleType` converts model values with plain PHP casts, so `"false"` and `"maybe"` become `true`, `"unknown"` becomes `0` for an int, `2.7` is truncated to `2`, `"n/a"` becomes `0.0`, and arrays or objects become the string `'Array'` with a warning. The resulting values have the correct PHP type, usually pass validation, and reach the caller as wrong data, while the correction-and-retry loop never runs. The inverted boolean is the most serious case: an approval or consent flag the model explicitly set to `"false"` reads as `true`. Scalar union types are also affected, because the first member's cast never fails, so `float|int` given `"a lot"` yields `0`.

Suggested fix: in `src/StructuredOutput/Deserializer/Deserializer.php`, replace the four casts in `castToSingleType` with type-checked helpers (`castToString`, `castToInt`, `castToFloat`, `castToBool` using `filter_var`) that throw `DeserializerException` on lossy conversions, which also lets the union loop fall through correctly. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-06"></a>STRUCTUREDOUTPUT-06 · Single-type anyOf with a backed enum crashes the Deserializer with TypeError

**medium** · bug · [`src/StructuredOutput/Deserializer/Deserializer.php:213`](../src/StructuredOutput/Deserializer/Deserializer.php#L213) · repro [`SingleEnumArrayTest`](repro/StructuredOutput/SingleEnumArrayTest.php) · fix validated

Declaring `#[SchemaProperty(anyOf: [SomeBackedEnum::class])] public array $values` makes `JsonSchema` emit items as `{type: string, enum: [...]}`, but `Deserializer::handleArray` sees `class_exists()` return true for the enum and sends every item to the `fn (array $item)` closure, which throws a `TypeError` on the first string. A model answer that exactly matches the schema therefore aborts the run, and invalid values also produce a `TypeError` rather than a `DeserializerException`, so `StructuredOutputNode` never retries. This breaks a natural, schema-advertised way to request a list of enum values; typing the property differently avoids it.

Suggested fix: in `Deserializer::handleArray()`, check `enum_exists($elementType)` before the `class_exists` branch and map each item through `handleEnum()`, which already throws `DeserializerException` for invalid values. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-07"></a>STRUCTUREDOUTPUT-07 · Pure enum case names advertised by the schema are rejected by the Deserializer

**medium** · bug · [`src/StructuredOutput/Deserializer/Deserializer.php:307`](../src/StructuredOutput/Deserializer/Deserializer.php#L307) · repro [`PureEnumRoundTripTest`](repro/StructuredOutput/PureEnumRoundTripTest.php) · fix validated

`JsonSchema::processEnum` deliberately emits case names for non-backed enums, so the model answers with a valid value such as `"A"`. `Deserializer::handleEnum` only supports `BackedEnum` and throws `Cannot create BackedEnum from: ...` for any pure enum. In `StructuredOutputNode` a correct answer is sent back as a correction on every attempt, each retry costs a provider call, and the call fails once `maxRetries` is exhausted. Structured output with a pure-enum property can never succeed unless the enum is changed to a backed one.

Suggested fix: in `Deserializer::handleEnum()`, resolve pure enums by matching the value against `$typeName::cases()` names (a small `findEnumCaseByName` helper) and return `UnitEnum`, keeping `tryFrom` for backed enums. This was validated in a sandbox against the repro and the module's tests; rejecting pure enums at schema generation is the alternative.

### <a id="structuredoutput-08"></a>STRUCTUREDOUTPUT-08 · JsonExtractor scanner stops at mb_strlen while indexing bytes, losing trailing JSON

**medium** · bug · [`src/StructuredOutput/JsonExtractor.php:208`](../src/StructuredOutput/JsonExtractor.php#L208) · repro [`MultibyteExtractionTest`](repro/StructuredOutput/MultibyteExtractionTest.php) · fix validated

`findJSONLikeStrings` indexes `$text[$i]` byte by byte but bounds the loop with `mb_strlen($text)`, so each extra byte of a multibyte character removes one byte from the end of the scan. When the earlier extractors fail (for example a `{bozza}` draft precedes the answer), non-ASCII text such as `è`, `ü`, CJK or emoji before or inside a trailing object causes the final `}` to be missed, and `getJson` returns `null` although valid JSON is present. `StructuredOutputNode` then retries, spending provider calls, and can end with "The response does not contains a valid JSON Object". The trigger needs multiple brace groups plus non-ASCII text, but it mostly affects non-English deployments.

Suggested fix: in `JsonExtractor::findJSONLikeStrings()` bound the loop with `strlen($text)` and import `strlen`; the scanner only matches ASCII characters, so byte iteration is safe. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-09"></a>STRUCTUREDOUTPUT-09 · Union and self property types crash schema generation or deserialize to the wrong type

**medium** · bug · [`src/StructuredOutput/JsonSchema.php:139`](../src/StructuredOutput/JsonSchema.php#L139) · repro [`UnionTypesTest`](repro/StructuredOutput/UnionTypesTest.php) · fix validated

`JsonSchema::processProperty` calls `getName()` on the property type, which throws `Error: Call to undefined method ReflectionUnionType::getName()` for any public union-typed property such as `int|string`, so `Agent::structured()` fails before calling the provider. The `Deserializer` does handle unions, but it takes the first member whose cast does not throw, and scalar casts never throw, so `{"identifier": 5}` becomes `'5'`. A recursive `?self` property is described as `['string','null']` because `class_exists('self')` is false, and an object answer then fails with a `TypeError` in `setValue`, outside the retry loop.

Suggested fix: in `JsonSchema::processProperty()` emit an `anyOf` for `ReflectionUnionType` and resolve `self` to the declaring class; in `Deserializer::castValue()` prefer the union member matching the decoded JSON type, and resolve `self` in `castToSingleType()`. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-10"></a>STRUCTUREDOUTPUT-10 · DateTime properties get an unsatisfiable object schema; empty classes encode properties as list

**medium** · bug · [`src/StructuredOutput/JsonSchema.php:169`](../src/StructuredOutput/JsonSchema.php#L169) · repro [`DateTimeSchemaTest`](repro/StructuredOutput/DateTimeSchemaTest.php) · fix validated

A `DateTime` or `DateTimeImmutable` property goes through the `class_exists` branch of `JsonSchema::processProperty` and is described as an object with no properties and `additionalProperties: false`. The model can only answer `{}`, which the `Deserializer` always rejects, so such properties can never be filled and every retry is wasted; the same schema reaches tools through `ObjectProperty`. Separately, any class with no public properties produces `"properties":[]`, which is invalid JSON Schema and can be rejected by strict providers. The reported numeric-string timestamp issue was not accepted: swapping the checks would misread compact dates such as `"20240101"`.

Suggested fix: in `src/StructuredOutput/JsonSchema.php`, describe `DateTime` and `DateTimeImmutable` as `{type: string, format: date-time}`, encode empty `properties` as `new stdClass()` in `generateClassSchema()`, and cast to array before spreading in `injectDiscriminator()`. This was validated in a sandbox against the repro and the module's tests; `JsonSchemaTest::test_class_without_public_properties` must be updated.

### <a id="structuredoutput-11"></a>STRUCTUREDOUTPUT-11 · Int-backed enums are described as type string with integer enum values

**medium** · bug · [`src/StructuredOutput/JsonSchema.php:216`](../src/StructuredOutput/JsonSchema.php#L216) · repro [`IntBackedEnumSchemaTest`](repro/StructuredOutput/IntBackedEnumSchemaTest.php) · fix validated

`JsonSchema::processEnum` always emits `'type' => 'string'`, so an int-backed enum produces `{"type":"string","enum":[1,2,3]}` (or `['string','null']` when nullable), which no value can satisfy. Strict-schema providers may reject it or push the model to emit strings; when the model returns `"1"`, `tryFrom` under `strict_types` throws a `TypeError` rather than a `DeserializerException`, so the retry loop is bypassed and structured extraction fails. Lenient providers may still return integers and work by chance. String-backed and pure enums are not affected.

Suggested fix: in `JsonSchema::processEnum()` derive the type from the backing type, emitting `integer` when `getBackingType()` is `int`. This was validated in a sandbox against the repro and the module's tests; turning the `tryFrom` `TypeError` into a `DeserializerException` is covered by the Deserializer type-mismatch finding.

### <a id="structuredoutput-12"></a>STRUCTUREDOUTPUT-12 · SchemaProperty::resolve reads the declaring class, ignoring subclass runtime schema maps

**medium** · bug · [`src/StructuredOutput/SchemaProperty.php:34`](../src/StructuredOutput/SchemaProperty.php#L34) · repro [`InheritedSchemaPropertiesTest`](repro/StructuredOutput/InheritedSchemaPropertiesTest.php) · fix validated

`SchemaProperty::resolve()` calls `schemaProperties()` on `$property->getDeclaringClass()` instead of the class being processed. When a subclass implements `SchemaPropertiesInterface` for properties declared in its parent, the runtime entries are ignored; when a parent implements it and the child overrides `schemaProperties()`, the parent's map wins. The schema sent to the model then lacks or misstates descriptions, required flags and constraints, and the `Deserializer` loses the `anyOf` item class for inherited array properties, returning raw arrays instead of typed objects. Nothing reports an error; it only occurs with inheritance combined with this interface.

Suggested fix: change the signature to `SchemaProperty::resolve(ReflectionClass $class, ReflectionProperty $property)` and pass the processed class down from `JsonSchema::generateClassSchema()` and `Deserializer::deserializeObject()` through `castValue`, `castToSingleType` and `handleArray`, updating `SchemaPropertyTest` and the AGENTS.md note. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-13"></a>STRUCTUREDOUTPUT-13 · ArrayOf with several scalar types only checks the first type

**medium** · bug · [`src/StructuredOutput/Validation/Rules/ArrayOf.php:89`](../src/StructuredOutput/Validation/Rules/ArrayOf.php#L89) · repro [`ArrayOfMultipleScalarTypesTest`](repro/StructuredOutput/ArrayOfMultipleScalarTypesTest.php) · fix validated

In `ArrayOf::validate()`, the inner loop over `$this->types` sets `$error = true` and breaks as soon as the first type fails, so later types are never tried. `#[ArrayOf(['string','integer'])]` therefore rejects `['a', 1]`, and `['integer','null']` rejects nulls. Object items work only because the object check uses `in_array` over all types. Valid model output is rejected, the agent spends retries on corrections that cannot succeed, and structured output fails once `maxRetries` is exhausted.

Suggested fix: in `src/StructuredOutput/Validation/Rules/ArrayOf.php`, continue to the next item as soon as any type matches and set the error only after the inner loop finds no match. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-14"></a>STRUCTUREDOUTPUT-14 · Count and Json rules throw instead of reporting violations, aborting the agent run

**medium** · bug · [`src/StructuredOutput/Validation/Rules/Count.php:41`](../src/StructuredOutput/Validation/Rules/Count.php#L41) · repro [`NullableCountedArrayTest`](repro/StructuredOutput/NullableCountedArrayTest.php) · fix validated

`#[Count(max: N)]` on an optional `?array` property throws `StructuredOutputException('... must be an array or a Countable object')` when the value is null or missing, because the empty check only runs when `min` is set. `StructuredOutputNode` does not catch this exception, so a model that legitimately omits an optional list crashes the run with no correction or retry. `#[Json]` likewise throws for non-scalar values, but only on untyped or `mixed` properties that receive an object or array; string-typed properties are converted to `'Array'` first and produce an ordinary violation.

Suggested fix: in `Count.php`, return early for null (adding a violation only when `min > 0`) and turn the non-countable case into a violation; in `Json.php`, replace the non-scalar throw with a violation, keeping throws only for misconfigured rules. This was validated in a sandbox against the repro and the module's tests; three data-set cases in `tests/StructuredOutput/Validation/RulesTest.php` move from the throws provider to the violations provider.

### <a id="structuredoutput-15"></a>STRUCTUREDOUTPUT-15 · Comparison rule messages omit the field and bound; LowerThan says greater than

**medium** · bug · [`src/StructuredOutput/Validation/Rules/LowerThan.php:22`](../src/StructuredOutput/Validation/Rules/LowerThan.php#L22) · repro [`ComparisonRuleMessagesTest`](repro/StructuredOutput/ComparisonRuleMessagesTest.php) · fix validated

The violation messages of `EqualTo`, `NotEqualTo`, `GreaterThan`, `GreaterThanEqual`, `LowerThan` and `LowerThanEqual` have no `{name}` placeholder and insert `get_debug_type($reference)` as the bound, producing text such as `must be greater than int`. `LowerThan` and `LowerThanEqual` reuse the "greater than" text, telling the model the opposite constraint, and `GreaterThanEqual` omits "or equal"; `OutOfRange` messages also omit the field name. Pass/fail logic is correct, but the correction prompt cannot tell the model which field failed or which bound applies, so retries and tokens are wasted and `structured()` can fail after max retries. The same text appears in the `Validated` event and exception messages.

Suggested fix: in the six rule classes under `src/StructuredOutput/Validation/Rules/`, use templates such as `{name} must be lower than {compare}` with `json_encode($this->reference)` as the compare value, and prefix the `OutOfRange` templates with `{name}`. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-16"></a>STRUCTUREDOUTPUT-16 · Validator ignores validation rules declared on nested object properties

**medium** · design · [`src/StructuredOutput/Validation/Validator.php:26`](../src/StructuredOutput/Validation/Validator.php#L26) · repro [`ValidatorNestedObjectTest`](repro/StructuredOutput/ValidatorNestedObjectTest.php) · fix validated

`Validator::validate()` only evaluates attributes on the root object's public properties; only arrays annotated with `#[ArrayOf(Class)]` recurse. Rules on a nested object, such as `#[NotBlank]` on `Address::$street` inside `Person::$address`, are never checked, so a model answer with an empty nested object passes validation, the correction retry never fires, and the caller receives an object that violates its own declared constraints. This is inconsistent with `ArrayOf`, which does validate the same class inside an array.

Suggested fix: rewrite `src/StructuredOutput/Validation/Validator.php` to recurse into object-valued public properties, prefixing violations with a dotted path such as `address.street`, skipping enums and tracking visited objects to avoid cycles. This was validated in a sandbox against the repro and the module's tests; the path format and always-on recursion versus an opt-in `#[Valid]`-style attribute are design choices to decide.

### <a id="structuredoutput-17"></a>STRUCTUREDOUTPUT-17 · JsonExtractor::getJson docblock promises an empty string but the method returns null

**low** · docs-mismatch · [`src/StructuredOutput/JsonExtractor.php:69`](../src/StructuredOutput/JsonExtractor.php#L69) · no repro (static evidence) · fix validated

The docblock of `JsonExtractor::getJson` says it returns an empty string on failure, while the `?string` signature and the implementation return `null`, and an existing test pins that. There is no runtime impact inside the framework, since `StructuredOutputNode` checks both `null` and `''`. Developers who call or subclass `getJson` based on the docblock may check `=== ''`, which never matches, and pass `null` onward into string-typed code.

Suggested fix: update the docblock in `src/StructuredOutput/JsonExtractor.php` to state that it returns `null` when no candidate parses; optionally simplify the check in `StructuredOutputNode` to `$json === null`. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-18"></a>STRUCTUREDOUTPUT-18 · JsonExtractor re-encoding turns empty JSON objects into empty arrays

**low** · bug · [`src/StructuredOutput/JsonExtractor.php:97`](../src/StructuredOutput/JsonExtractor.php#L97) · repro [`EmptyObjectExtractionTest`](repro/StructuredOutput/EmptyObjectExtractionTest.php) · fix validated

`tryParse` decodes with `assoc=true` and `getJson` re-encodes with `json_encode`, so every `{}` becomes `[]`: `'{"meta":{}}'` comes back as `'{"meta":[]}'` and `'{}'` as `'[]'`. The framework's own flow is unaffected, because the `Deserializer` decodes with `assoc=true` again. The wrong shape is visible in the `$json` carried by the `Extracted`, `Validating` and `Validated` observability events, and to anyone who calls `getJson` directly and forwards the result to a consumer that distinguishes objects from lists, such as a schema validator expecting `type: object`.

Suggested fix: in `JsonExtractor::tryParse()` decode without `assoc` and accept only arrays or objects, adjusting the imports. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-19"></a>STRUCTUREDOUTPUT-19 · A stray closing brace in prose breaks the JSON-like fallback scanner

**low** · bug · [`src/StructuredOutput/JsonExtractor.php:219`](../src/StructuredOutput/JsonExtractor.php#L219) · repro [`StrayClosingBraceExtractionTest`](repro/StructuredOutput/StrayClosingBraceExtractionTest.php) · fix validated

`findJSONLikeStrings` decrements `$bracketCount` on every `}` outside a string, even when the counter is already zero. After an unmatched brace in the prose, such as `:}`, the counter stays one below its true value, no later object is ever captured, and `getJson` returns `null` although valid JSON follows. This only matters when the earlier extractors also fail (no fenced block, and either a leading brace or several objects in the text), which is a realistic answer shape. `StructuredOutputNode` then retries, costing extra provider calls, or fails after `maxRetries`; no data is corrupted.

Suggested fix: in `JsonExtractor::findJSONLikeStrings()` only decrement the counter when `$bracketCount > 0`. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-20"></a>STRUCTUREDOUTPUT-20 · Promoted constructor properties with defaults are marked required and lose their schema default

**low** · bug · [`src/StructuredOutput/JsonSchema.php:104`](../src/StructuredOutput/JsonSchema.php#L104) · repro [`PromotedDefaultSchemaTest`](repro/StructuredOutput/PromotedDefaultSchemaTest.php) · fix validated

`ReflectionProperty::hasDefaultValue()` is false for promoted properties, because the default lives on the constructor parameter. `JsonSchema` therefore marks `__construct(public string $title = 'untitled')` as required and emits no `default`, while the `Deserializer` fills the omitted field from the constructor default. The schema is stricter than the runtime, the model never sees the default, and users must add `#[SchemaProperty(required: false)]` to keep such fields optional. The Deserializer only invokes the constructor when it is public with no required parameters, so in a mixed required/optional constructor the promoted default does not apply and marking the property required is correct.

Suggested fix: in `src/StructuredOutput/JsonSchema.php`, route the required check and the `default` emission through protected `hasDefault()`/`defaultValue()` helpers that honour a promoted parameter's default only when the constructor is public and has zero required parameters. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-21"></a>STRUCTUREDOUTPUT-21 · Enum property defaults are emitted as enum objects, breaking schema encoding for pure enums

**low** · bug · [`src/StructuredOutput/JsonSchema.php:142`](../src/StructuredOutput/JsonSchema.php#L142) · repro [`EnumDefaultSchemaTest`](repro/StructuredOutput/EnumDefaultSchemaTest.php) · fix validated

`JsonSchema::processProperty` copies `getDefaultValue()` into `default` as-is, so an enum-typed default is stored as the enum case object. For a pure enum, `json_encode` of the schema fails ("Non-backed enums have no default serialization"); the Curl and Amp HTTP clients do not check the result, so the request body is sent empty or broken and the provider fails with a confusing error. For backed enums the encoded payload is correct, but the in-memory schema holds an object where a scalar is expected, which can affect code that inspects or compares the schema.

Suggested fix: in `JsonSchema::processProperty()`, convert enum defaults to the representation `processEnum()` uses: `->value` for `BackedEnum` and `->name` for `UnitEnum`. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-22"></a>STRUCTUREDOUTPUT-22 · Multi-type anyOf accepts enums, drops unknown classes and allows colliding discriminators

**low** · design · [`src/StructuredOutput/JsonSchema.php:297`](../src/StructuredOutput/JsonSchema.php#L297) · repro [`AnyOfContractTest`](repro/StructuredOutput/AnyOfContractTest.php) · fix validated

`generateAnyOfSchema` emits enum members without a discriminator, and a schema-conforming answer with an enum item then crashes the `Deserializer` with a `TypeError` in the `fn (array $item)` closure, bypassing `StructuredOutputNode`'s retry loop; the same happens for class-only `anyOf` when the model returns a non-object item. Two classes with the same short name in different namespaces get the same discriminator value, and every item is silently hydrated as the last listed class. A misspelled or non-existent class is silently dropped from the schema. Each case requires a misconfiguration or a malformed answer.

Suggested fix: in `JsonSchema::generateAnyOfSchema()` throw `StructuredOutputException` for members that are not non-enum classes and for duplicate discriminator values; in `Deserializer`, accept `mixed` in the multi-type closure and throw `DeserializerException` from `deserializeObjectWithDiscriminator()` for non-array items. This was validated in a sandbox against the repro and the module's tests; the uncommitted `test_discriminator_is_only_injected_into_object_items` must be updated or removed.

### <a id="structuredoutput-23"></a>STRUCTUREDOUTPUT-23 · Property named like the discriminator overwrites its schema and makes required a JSON object

**low** · bug · [`src/StructuredOutput/JsonSchema.php:313`](../src/StructuredOutput/JsonSchema.php#L313) · repro [`DiscriminatorNameCollisionTest`](repro/StructuredOutput/DiscriminatorNameCollisionTest.php) · fix validated

`injectDiscriminator` builds the properties as `[discriminator => {...}, ...$schema['properties']]`, so an `anyOf` class with a property of the same name (for example `kind` with `new JsonSchema('kind')`) overwrites the injected definition, removing the `enum` and the instruction the model needs. `array_unique([discriminator, ...required])` keeps the original keys, so `required` encodes as `{"0":"kind","2":"label"}`, which strict providers reject. With multiple `anyOf` types the `Deserializer` also unsets the discriminator key before hydration, leaving the class's own property uninitialized. With the default `__classname__` a collision is unlikely.

Suggested fix: in `JsonSchema::injectDiscriminator()` throw `StructuredOutputException` when the class already defines the discriminator property, and build `required` without `array_unique`, removing the import. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-24"></a>STRUCTUREDOUTPUT-24 · Count misconfiguration error message names the Length rule instead of Count

**low** · api-inconsistency · [`src/StructuredOutput/Validation/Rules/Count.php:32`](../src/StructuredOutput/Validation/Rules/Count.php#L32) · repro [`CountMisconfigurationMessageTest`](repro/StructuredOutput/CountMisconfigurationMessageTest.php) · fix validated

Using `#[Count]` without `exactly`, `min` or `max` throws `Either option "min" or "max" must be given for validation rule "Length"`, a copy-paste leftover that sends developers looking at the wrong attribute. Validation results for correctly configured rules are unchanged. A related wording issue is that `Length` rejects non-strings with "must be a scalar or a stringable object", although its check accepts only strings and `Stringable`.

Suggested fix: change the message in `src/StructuredOutput/Validation/Rules/Count.php` to name the `Count` rule, and optionally change the `Length.php` violation text to "must be a string or a stringable object". This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-25"></a>STRUCTUREDOUTPUT-25 · Enum and ArrayOf violation messages contain unresolved {values} and {types} placeholders

**low** · bug · [`src/StructuredOutput/Validation/Rules/Enum.php:51`](../src/StructuredOutput/Validation/Rules/Enum.php#L51) · repro [`UnresolvedPlaceholdersTest`](repro/StructuredOutput/UnresolvedPlaceholdersTest.php) · fix validated

`Enum::validate` passes the allowed values under the key `choices` for null values while the template uses `{values}`, and `ArrayOf`'s non-array branch calls `buildMessage` without the `types` variable. The resulting messages, such as `number must be one of the following allowed values: {values}.` and `tags must be an array of {types}`, reach the model on retry and appear in `AgentException` and `Validated` events. Rejection still works, but the model does not learn the allowed values or types, which makes self-correction less effective and can use up retries.

Suggested fix: in `Enum.php` pass the key `values` instead of `choices`, and in `ArrayOf.php` pass `['types' => implode(', ', $this->types)]` to `buildMessage`. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-26"></a>STRUCTUREDOUTPUT-26 · GreaterThanEqual(0) lets null or missing values pass validation

**low** · bug · [`src/StructuredOutput/Validation/Rules/GreaterThanEqual.php:21`](../src/StructuredOutput/Validation/Rules/GreaterThanEqual.php#L21) · repro [`GreaterThanEqualNullValueTest`](repro/StructuredOutput/GreaterThanEqualNullValueTest.php) · fix validated

`GreaterThanEqual` checks `is_null($this->reference) || $value < $this->reference` but never checks the value for null. With PHP loose comparison, `null < 0` is false, so with a falsy reference (`0` or `0.0`) a missing or null value passes, while `GreaterThan`, `LowerThan` and `LowerThanEqual` reject it. `#[GreaterThanEqual(0)]` is the usual way to express "non-negative", so an omitted field skips the correction retry and the application receives an uninitialized typed property or a null where a number was expected. Non-zero references are not affected.

Suggested fix: in `src/StructuredOutput/Validation/Rules/GreaterThanEqual.php`, change the condition to `is_null($value) || is_null($this->reference) || $value < $this->reference`, keeping the existing null-reference guard. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-27"></a>STRUCTUREDOUTPUT-27 · OutOfRange strict mode accepts excluded bounds when value and bound types differ

**low** · bug · [`src/StructuredOutput/Validation/Rules/OutOfRange.php:20`](../src/StructuredOutput/Validation/Rules/OutOfRange.php#L20) · repro [`OutOfRangeStrictFloatBoundTest`](repro/StructuredOutput/OutOfRangeStrictFloatBoundTest.php) · fix validated

With `strict: true`, `OutOfRange` excludes the bounds using `$value === $this->min` and `$value === $this->max`. The identity check fails whenever the value and the bound differ in PHP type, and neither the `<` nor the `>` branch fires, so the excluded bound is accepted. The common case is a `float` property with int bounds, such as `#[OutOfRange(0, 100, strict: true)]` on `public float $score`: the Deserializer turns `{"score": 0}` into `0.0`, which passes validation. An int property with float bounds fails the same way. `StructuredOutputNode` never asks the model for a correction, and the application receives a value its declared contract forbids.

Suggested fix: in `src/StructuredOutput/Validation/Rules/OutOfRange.php`, change the two strict-bound checks from `===` to `==`, matching the numeric semantics the `<` and `>` checks already use. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-28"></a>STRUCTUREDOUTPUT-28 · Url validation rule accepts non-web schemes such as javascript://, file:// and gopher://

**low** · security · [`src/StructuredOutput/Validation/Rules/Url.php:20`](../src/StructuredOutput/Validation/Rules/Url.php#L20) · repro [`UrlSchemeTest`](repro/StructuredOutput/UrlSchemeTest.php) · fix validated

`#[Url]` relies only on `filter_var($value, FILTER_VALIDATE_URL)`, which accepts any syntactically valid URL regardless of scheme. Values such as `javascript://x/%0Aalert(1)`, `file:///etc/passwd`, `gopher://127.0.0.1:6379/_FLUSHALL` and `data://text/plain;base64,...` produce no violation. The existing `javascript:alert(1)` test passes only because that value has no authority component. The documentation promises only a valid URL format, so no documented contract is broken, but the rule is meant for untrusted LLM output: an application that renders the value as an `href` or fetches it server-side with a scheme-agnostic client is exposed to stored XSS, local file reads or SSRF. The framework itself does not use the value.

Suggested fix: in `src/StructuredOutput/Validation/Rules/Url.php`, add a constructor parameter `array $schemes = ['http', 'https']` and, after `filter_var`, check the `parse_url(..., PHP_URL_SCHEME)` result case-insensitively against that list. Other schemes would then need an explicit `#[Url(schemes: ['ftp'])]`. This adds a public constructor parameter and needs your approval; the `SKILL.md` description of the rule would change too. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-29"></a>STRUCTUREDOUTPUT-29 · WordsCount miscounts words separated by tabs or NBSP and has an ungrammatical message

**low** · bug · [`src/StructuredOutput/Validation/Rules/WordsCount.php:48`](../src/StructuredOutput/Validation/Rules/WordsCount.php#L48) · repro [`WordsCountWhitespaceTest`](repro/StructuredOutput/WordsCountWhitespaceTest.php) · fix validated

`WordsCount` splits words with `/[ \-\r\n]+/`, so words separated by a tab, a non-breaking space or other Unicode whitespace count as one word. With `WordsCount(max: 1)`, `"one\ttwo"` and `"one\u{00A0}two"` produce no violation, so max limits are not enforced and min limits can fail when they should pass. Separately, the exact-count rule reports too few words as `{name} must have exactly {exact} words long` but too many as `{name} must have exactly {exact} words`. This message is sent back to the LLM on retry and reaches callers, so the too-few version is ungrammatical and code matching the text sees two different strings.

Suggested fix: in `src/StructuredOutput/Validation/Rules/WordsCount.php`, split on `/[\s\-]+/u` and drop `long` from the too-few message. With the `/u` flag, invalid UTF-8 makes `preg_split` return `false` and `count()` throw a `TypeError`, so either fall back to the non-`u` pattern in that case or use `/[\s\-]+/` without `/u`, which handles tabs but not NBSP. This was validated in a sandbox against the repro and the module's tests.

### <a id="structuredoutput-30"></a>STRUCTUREDOUTPUT-30 · Validator instantiates every property attribute and crashes on unrelated or uninstantiable attributes

**low** · bug · [`src/StructuredOutput/Validation/Validator.php:38`](../src/StructuredOutput/Validation/Validator.php#L38) · repro [`ValidatorForeignAttributeTest`](repro/StructuredOutput/ValidatorForeignAttributeTest.php) · fix validated

`Validator::validate()` calls `$property->getAttributes()` with no filter and runs `newInstance()` on every attribute before checking `instanceof ValidationRuleInterface`. PHP resolves an attribute class only at `newInstance()`, so an unrelated attribute that cannot be built throws an `Error`. Examples are a class from a package not installed in production (`Attribute class "..." not found`) or a class-only attribute placed on a property (`cannot target property`). Unrelated attributes with constructor side effects also run during validation. The `Error` is neither an `AgentException` nor a `DeserializerException`, so it escapes the correction loop in `StructuredOutputNode`, and the structured call fails after the provider request was already made. Direct `Validator::validate()` calls and `ArrayOf` nested validation fail the same way.

Suggested fix: in `src/StructuredOutput/Validation/Validator.php`, use `getAttributes(ValidationRuleInterface::class, ReflectionAttribute::IS_INSTANCEOF)`, which only returns loaded validation-rule attributes. Then call `newInstance()->validate(...)` directly and remove the now-redundant `instanceof` check. This was validated in a sandbox against the repro and the module's tests.

## <a id="module-httpclient"></a>HTTP client

HttpClient has 11 findings, and the default curl client carries the security weight. It accepts file:// and other non-HTTP schemes ([HTTPCLIENT-05](#httpclient-05)), forwards custom API-key headers to another host on redirect ([HTTPCLIENT-06](#httpclient-06)), allows CR/LF header injection ([HTTPCLIENT-08](#httpclient-08)) and silently sends an empty body when JSON encoding fails ([HTTPCLIENT-07](#httpclient-07)). [HTTPCLIENT-04](#httpclient-04) treats a 103 Early Hints response as final, so later errors on the streaming path go unreported. The Amp and Guzzle adapters break the shared contract on error statuses, multipart uploads, truncated streams and client options ([HTTPCLIENT-01](#httpclient-01), [HTTPCLIENT-02](#httpclient-02), [HTTPCLIENT-03](#httpclient-03), [HTTPCLIENT-09](#httpclient-09)).

### <a id="httpclient-01"></a>HTTPCLIENT-01 · AmpHttpClient returns 4xx/5xx responses instead of throwing HttpException

**medium** · bug · [`src/HttpClient/Amp/AmpHttpClient.php:64`](../src/HttpClient/Amp/AmpHttpClient.php#L64) · repro [`AmpErrorStatusTest`](repro/HttpClient/AmpErrorStatusTest.php) · fix validated

`HttpClientInterface` and `src/HttpClient/AGENTS.md` promise that `request()` and `stream()` throw `HttpException` with the response attached for any status of 400 or above, and the Curl and Guzzle clients follow that rule. `AmpHttpClient::request()` returns a 422 as an ordinary `HttpResponse`, and `stream()` returns an `AmpStream` over the error body. When a user injects `AmpHttpClient` into a provider, vector store or MCP transport, `StreamableHttpTransport` no longer maps 404 to session expiry or 401/403 to its auth errors, the catch block in `SseHttpTransport` is skipped, and provider `stream()` calls parse an error JSON body as SSE. The result is confusing downstream failures or empty output instead of a clear HTTP error with the status and body available for retries or reporting. The adapter is opt-in, which is why severity is medium.

Suggested fix: In `src/HttpClient/Amp/AmpHttpClient.php`, throw `HttpException::statusError($request, $httpResponse)` from `request()` when the status is 400 or above. In `stream()`, buffer the body into an `HttpResponse` and throw the same exception before returning the `AmpStream`. The existing `catch (HttpException $e) { throw $e; }` branches pass it through unchanged. This was validated in a sandbox against the repro and the module's tests.

### <a id="httpclient-02"></a>HTTPCLIENT-02 · Guzzle and Amp clients break the documented multipart upload contract that curl follows

**medium** · api-inconsistency · [`src/HttpClient/Amp/AmpHttpClient.php:110`](../src/HttpClient/Amp/AmpHttpClient.php#L110) · repro [`MultipartContractTest`](repro/HttpClient/MultipartContractTest.php) · fix validated

The module docs say that an array body containing resources or `['contents' => ...]` parts is sent as multipart, with filenames taken from the underlying file. `HttpRequest::isMultipart()` and `CurlHttpClient` implement this, but the other adapters diverge in three ways. First, `AmpHttpClient::executeMultipart` adds a bare file resource with `addStream` and no filename, so it arrives as a plain form field. Second, Amp ignores a part's `'headers' => ['Content-Type' => ...]`. Third, `isMultipartData()` in both Guzzle and Amp requires `contents` to be a resource, so a string part such as `['contents' => 'col1,col2', 'filename' => 'data.csv']` is JSON-encoded. In practice, users who inject `AmpHttpClient` into the OpenAI, ElevenLabs or ZAI speech-to-text providers get rejected uploads, because the audio arrives with no filename or file part. The same request behaves differently depending on the injected client. No framework caller uses the string-part shape today, and `MultipartBodyTest` only covers the client/case pairs that pass.

Suggested fix: In `GuzzleHttpClient::runRequest` and `AmpHttpClient::execute`, replace the `isMultipartData()` checks with `$request->isMultipart()` and delete both helpers. Rewrite `AmpHttpClient::executeMultipart` to mirror curl: pass a filename taken from the stream URI or the part, honour the part's `Content-Type`, and wrap string contents in `BufferedContent`. Then add `amp` to both data providers in `tests/HttpClient/MultipartBodyTest.php`. This was validated in a sandbox against the repro and the module's tests.

### <a id="httpclient-03"></a>HTTPCLIENT-03 · Guzzle and Amp streams treat a dropped connection as a complete response

**medium** · bug · [`src/HttpClient/Amp/AmpStream.php:62`](../src/HttpClient/Amp/AmpStream.php#L62) · repro [`TruncatedStreamTest`](repro/HttpClient/TruncatedStreamTest.php) · fix validated

When a connection drops mid-response (proxy idle timeout, server crash, load-balancer reset), `AmpStream::read()` and `readLine()` catch Amp's `StreamException` and turn it into EOF. `GuzzleStream` stops at the bytes it received because PHP's stream wrapper cannot tell a dropped connection from a complete body. Callers therefore accept a truncated LLM answer, tool-call arguments or MCP result as final, with no error or retry signal. `CurlStream`, the default, correctly throws `HttpException`. Separately, `GuzzleHttpClient::handleException` treats any exception that carries a response as a status error. A truncated 200 is reported as `HTTP 200 error during GET ...` and a redirect loop as `HTTP 302 error`, each with a non-error `HttpResponse` attached. Callers that rely on the documented rule (a response is attached only for status of 400 or above) misclassify a retryable network failure. Only users who opt into the Amp or Guzzle adapters are affected.

Suggested fix: Give `AmpStream` the `HttpRequest` and throw `HttpException::networkError` from its `StreamException` catches. Have `GuzzleStream` compare the bytes received against the declared `Content-Length`, which `GuzzleHttpClient::stream()` passes in, and throw a network error on a short body. In `GuzzleHttpClient::handleException`, map only `BadResponseException` to a status error. This was validated in a sandbox against the repro and the module's tests.

### <a id="httpclient-04"></a>HTTPCLIENT-04 · CurlHeaderCollector treats a 1xx informational header block as the final response

**medium** · bug · [`src/HttpClient/Curl/CurlHeaderCollector.php:49`](../src/HttpClient/Curl/CurlHeaderCollector.php#L49) · repro [`CurlInformationalResponseTest`](repro/HttpClient/CurlInformationalResponseTest.php) · fix validated

At the end of a header block, `CurlHeaderCollector::ingestLine()` sets `complete = statusCode < 300 || statusCode >= 400`, which is true for 1xx codes. When a server or intermediary sends `103 Early Hints` (common with CDNs such as Cloudflare) or an unsolicited `100 Continue`, `CurlStream::awaitHeaders()` returns right after that block. `CurlHttpClient::stream()` then checks 103 against the error threshold, so a following 4xx/5xx is not thrown as `HttpException`. The caller gets a stream whose body is the error payload, and the `onResponse` hooks, `getStatusCode()` and `getHeaders()` report the 103 status and headers. This affects the default client's streaming path, which covers all provider SSE streaming and the MCP streamable/SSE transports. `request()` and the Guzzle/Amp adapters behave correctly.

Suggested fix: In `src/HttpClient/Curl/CurlHeaderCollector.php`, change the end-of-block rule to `$this->complete = $this->statusCode >= 200 && ($this->statusCode < 300 || $this->statusCode >= 400);`. The next status line already resets the collected state. Also add a raw-socket fixture, `tests/HttpClient/fixtures/early_hints_server.php`, that sends 103 followed by 500, to cover the case. This was validated in a sandbox against the repro and the module's tests.

### <a id="httpclient-05"></a>HTTPCLIENT-05 · CurlHttpClient accepts file:// and other non-HTTP schemes, enabling local file reads

**medium** · security · [`src/HttpClient/Curl/CurlHttpClient.php:313`](../src/HttpClient/Curl/CurlHttpClient.php#L313) · repro [`CurlFileSchemeTest`](repro/HttpClient/CurlFileSchemeTest.php) · fix validated

`CurlHttpClient::buildOptions()` never restricts `CURLOPT_PROTOCOLS`, so libcurl serves any scheme it was built with when that scheme is used directly in the URL: `file`, `gopher`, `dict`, `ftp`, `ldap` and others. A request for `file:///path` returns the file contents with status `0`, and because `0` is below 400, no exception is thrown. Both `request()` and `stream()` are affected. Nothing inside the framework lets an LLM or remote server supply a full URL. The risk is an application that passes an untrusted URL without a base URI, for example letting tenants register their own MCP server through `McpConnector::make(['url' => ...])`. The `FILTER_VALIDATE_URL` check in `StreamableHttpTransport` accepts `file://`, so an attacker could read local files or smuggle raw bytes to internal services such as Redis through `gopher://` or `dict://`. Guzzle and Amp already refuse these schemes.

Suggested fix: In `CurlHttpClient::buildOptions()`, set `CURLOPT_PROTOCOLS` and `CURLOPT_REDIR_PROTOCOLS` to `CURLPROTO_HTTP | CURLPROTO_HTTPS`. The integer constants keep PHP 8.1 support, and user `curlOptions` can still override them. After the change, such URLs raise `HttpException::networkError` with no response. This was validated in a sandbox against the repro and the module's tests.

### <a id="httpclient-06"></a>HTTPCLIENT-06 · Curl and Guzzle forward custom API-key headers to another origin on redirect

**medium** · security · [`src/HttpClient/Curl/CurlHttpClient.php:319`](../src/HttpClient/Curl/CurlHttpClient.php#L319) · repro [`RedirectCredentialLeakTest`](repro/HttpClient/RedirectCredentialLeakTest.php) · fix validated

Both clients follow redirects by default, but libcurl and Guzzle strip only `Authorization` and `Cookie` when the host changes. Providers and services that authenticate with custom headers send the secret to whatever origin a 3xx points at, following up to 5 hops. This includes Anthropic (`x-api-key`), Azure (`api-key`), Gemini (`x-goog-api-key`), Pinecone, Qdrant, Supadata and MCP `headers`. The secret is also sent in cleartext after an https-to-http downgrade. It leaks whether it is set per request or as a client default header, through both `request()` and `stream()`. Exploiting it requires a redirect: an open redirect on the API host, a misconfigured proxy or gateway, a compromised self-hosted endpoint, or a hostile MCP server. Whoever controls the target then obtains the key. Bearer-token providers and `AmpHttpClient` are not affected.

Suggested fix: Add an `isSameOrigin()` comparison to `ResolvesHttpRequest`. Make `CurlHttpClient` wrap its header callback so it throws `HttpException::networkError` on a cross-origin `Location`. Make `GuzzleHttpClient::runRequest` default `allow_redirects` to an `on_redirect` guard that does the same, while a user-set value still takes precedence. The curl and Guzzle cases of `HttpClientSecurityTest::test_authorization_is_not_forwarded_to_another_host_on_redirect` then need to expect that exception. This was validated in a sandbox against the repro and the module's tests.

### <a id="httpclient-07"></a>HTTPCLIENT-07 · CurlHttpClient sends an empty JSON body when array body encoding fails

**medium** · bug · [`src/HttpClient/Curl/CurlHttpClient.php:331`](../src/HttpClient/Curl/CurlHttpClient.php#L331) · repro [`CurlUnencodableJsonBodyTest`](repro/HttpClient/CurlUnencodableJsonBodyTest.php) · fix validated

`CurlHttpClient::buildOptions()` assigns `json_encode($request->body)` to `CURLOPT_POSTFIELDS` without checking the result. When the body contains invalid UTF-8, such as binary or truncated multibyte tool output or text from a badly encoded document, `json_encode` returns `false`. curl then sends an empty body labelled `Content-Type: application/json`, through both `request()` and `stream()`. The provider answers with a misleading 400 (for example, missing messages or model) that hides the real cause, and a lenient endpoint may accept the empty payload and act on nothing. This affects every user of the default client. Guzzle and Amp both fail loudly in the same situation.

Suggested fix: In `CurlHttpClient::buildOptions()`, call `json_encode($request->body, JSON_THROW_ON_ERROR)` and import `JSON_THROW_ON_ERROR`, so a `JsonException` is raised before any network I/O. The same change at `src/HttpClient/Amp/AmpHttpClient.php:224` would optionally replace Amp's `TypeError` with a `JsonException`. This was validated in a sandbox against the repro and the module's tests.

### <a id="httpclient-08"></a>HTTPCLIENT-08 · CurlHttpClient allows header injection through CR/LF in header names or values

**medium** · security · [`src/HttpClient/Curl/CurlHttpClient.php:342`](../src/HttpClient/Curl/CurlHttpClient.php#L342) · repro [`CurlHeaderInjectionTest`](repro/HttpClient/CurlHeaderInjectionTest.php) · fix validated

`HttpRequest` rejects line breaks in the URI but not in headers. `CurlHttpClient::buildOptions()` builds raw `"{$name}: {$value}"` lines for `CURLOPT_HTTPHEADER`, and libcurl sends them as written. A CR/LF in a header name or value therefore adds arbitrary headers to the outbound request or splits it. The repro received an extra `X-Injected: yes` header on the server. This applies to per-request headers, client default headers, headers added by `onRequest` hooks, and header maps passed to MCP transports and providers, through both `request()` and `stream()`. The framework's own providers use developer config, so the exposure is limited to applications that put untrusted data (tenant ids, user tokens, user-configured MCP headers) into headers. The default client is the only adapter that permits it, since Guzzle and Amp reject such values.

Suggested fix: In `CurlHttpClient::buildOptions()`, throw `InvalidArgumentException("Header {$name} must not contain line breaks")` when `strpbrk("{$name}{$value}", "\r\n\0")` matches, before building each line. The message does not repeat the value. Checking there covers every path, including headers changed after construction. An additional early check in the `HttpRequest` constructor is optional. This was validated in a sandbox against the repro and the module's tests.

### <a id="httpclient-09"></a>HTTPCLIENT-09 · GuzzleHttpClient::stream() ignores client options such as verify, proxy and cert

**medium** · bug · [`src/HttpClient/Guzzle/GuzzleHttpClient.php:89`](../src/HttpClient/Guzzle/GuzzleHttpClient.php#L89) · repro [`GuzzleStreamOptionsTest`](repro/HttpClient/GuzzleStreamOptionsTest.php) · fix validated

`GuzzleHttpClient::request()` spreads `$this->options` into the Guzzle request options, but `stream()` builds its options without them. Every streaming call made through this client, including provider `stream()` and the MCP SSE/streamable transports, silently drops the constructor-level `verify` CA bundle, `proxy` and `cert`/`ssl_key` settings. With a private CA or a TLS-inspecting proxy, streaming fails TLS while non-streaming calls work. With a mandatory egress proxy, streamed traffic bypasses network policy and auditing. With mTLS, streamed requests go out without the client certificate. Because `chat()` works while `stream()` fails, the problem is hard to diagnose. Only users of `GuzzleHttpClient` who pass `options` are affected.

Suggested fix: In `GuzzleHttpClient::stream()`, add `...$this->options,` as the first entry of the options array, matching `request()`. It is also worth adding a stream case next to `test_client_options_reach_the_handler` in `tests/HttpClient/GuzzleHttpClientTest.php`. This was validated in a sandbox against the repro and the module's tests.

### <a id="httpclient-10"></a>HTTPCLIENT-10 · AmpStream::close() does not close the wrapped Amp response stream

**low** · bug · [`src/HttpClient/Amp/AmpStream.php:104`](../src/HttpClient/Amp/AmpStream.php#L104) · repro [`AmpStreamCloseTest`](repro/HttpClient/AmpStreamCloseTest.php) · fix validated

`AmpStream::close()` only sets the EOF flag and clears its local buffer. It never calls `close()` on the wrapped Amp `ReadableStream`. A consumer that stops reading early, for example to cancel an LLM SSE stream, leaves the response body and its connection open and still receiving data until garbage collection. Amp's `ResponseBodyStream` then cancels it in its destructor, so this is a delayed release, not a permanent leak. By contrast, `GuzzleStream` and `CurlStream` release their resources right away. The impact is limited to applications that opt into `AmpHttpClient` and call `close()` themselves, since no framework code currently closes a provider or MCP stream. It can matter in long-running async workers that hold stream references.

Suggested fix: In `src/HttpClient/Amp/AmpStream.php`, add `$this->stream->close();` to `close()`. Callers see no change afterwards, because `readLine()` returns an empty string once EOF is set, and the existing `StreamException` catch handles the `ClosedException` that `read()` can raise. This was validated in a sandbox against the repro and the module's tests.

### <a id="httpclient-11"></a>HTTPCLIENT-11 · HttpResponse::json() throws TypeError when the body is a scalar JSON value

**low** · bug · [`src/HttpClient/HttpResponse.php:30`](../src/HttpClient/HttpResponse.php#L30) · repro [`HttpResponseScalarJsonTest`](repro/HttpClient/HttpResponseScalarJsonTest.php) · fix validated

`HttpResponse::json()` returns `json_decode($this->body, true) ?? []`. The `?? []` fallback only replaces `null`, so a valid JSON body with a scalar at the top level (`42`, `"ok"`, `true`) is returned as is. That violates the `array` return type and raises `TypeError`. More than 40 provider, embeddings, vector-store, reranker and toolkit call sites call `->json()` on remote responses. A misbehaving proxy, gateway or mock server that returns such a body with a 2xx status therefore produces a PHP `Error` that bypasses `catch (Exception)` handlers and the framework's provider exceptions, instead of an empty array. Real APIs rarely send such bodies, and nothing is leaked or lost, which is why severity is low.

Suggested fix: In `src/HttpClient/HttpResponse.php`, decode into a variable and return `is_array($decoded) ? $decoded : []`. This was validated in a sandbox against the repro and the module's tests.

## <a id="module-mcp"></a>MCP

MCP has 15 findings. The legacy SSE transport follows redirects with the bearer token ([MCP-12](#mcp-12)), opens non-HTTP URLs ([MCP-13](#mcp-13)) and ignores events that are already buffered ([MCP-11](#mcp-11)). JSON-RPC error responses are ignored or crash the client ([MCP-01](#mcp-01), [MCP-02](#mcp-02), [MCP-10](#mcp-10)). StdioTransport runs its command through /bin/sh ([MCP-03](#mcp-03)) and hands the whole application environment to third-party servers ([MCP-07](#mcp-07)). [MCP-15](#mcp-15) sends tool results to the model as a JSON string, which drops isError and turns images into base64 text.

### <a id="mcp-01"></a>MCP-01 · McpClient ignores a JSON-RPC error response to `initialize` and opens the session anyway

**medium** · bug · [`src/MCP/McpClient.php:111`](../src/MCP/McpClient.php#L111) · repro [`McpInitializeErrorTest`](repro/MCP/McpInitializeErrorTest.php) · fix validated

When an MCP server answers `initialize` with a JSON-RPC `error` (for example an unsupported protocol version, invalid params, or a server-side auth or config failure), `McpClient::initialize()` falls back to the default protocol version via `$response['result']['protocolVersion'] ?? ...`, sends `notifications/initialized` to the server that refused the session, and the constructor returns normally. The server's error message is discarded, and the problem surfaces later in unrelated places, such as `listTools()` failing with an undefined-key warning and a `TypeError`. Anyone using `McpConnector` or `McpClient` against a server that rejects the handshake gets a hard-to-diagnose failure instead of a clear one.

Suggested fix: in `src/MCP/McpClient.php` `initialize()`, right after the `exchange('initialize', ...)` call and before `setProtocolVersion()`, throw an `McpException` carrying `$response['error']['message']` when the response has an `error` key. Because it throws before `openSession()`, the session is never marked open and `notifications/initialized` is not sent; this was validated in a sandbox against the repro and the module's tests.

### <a id="mcp-02"></a>MCP-02 · `McpClient::listTools()` crashes with a `TypeError` when `tools/list` returns a JSON-RPC error

**medium** · bug · [`src/MCP/McpClient.php:133`](../src/MCP/McpClient.php#L133) · repro [`McpListToolsErrorTest`](repro/MCP/McpListToolsErrorTest.php) · fix validated

`listTools()` reads `$response['result']['tools']` without checking for an `error` member. When a server answers `tools/list` with an error (no tools capability `-32601`, an invalid pagination cursor `-32602`, or an internal error `-32603`), on the first or on a later page, PHP emits two `Undefined array key` warnings and then `array_merge()` throws a `TypeError`. `McpConnector::tools()` therefore aborts agent construction with an opaque engine error that callers cannot catch as `McpException`, and the server's diagnostic message is lost. `invokeTool()` already maps error responses correctly; `listTools()` does not.

Suggested fix: in `src/MCP/McpClient.php` `listTools()`, immediately after each `tools/list` request, throw `new McpException($response['error']['message'])` when the response contains `error`, mirroring `invokeTool()`, and update the `@throws` docblock. This was validated in a sandbox against the repro and the module's tests.

### <a id="mcp-03"></a>MCP-03 · `StdioTransport` runs the server command through `/bin/sh`, breaking paths and orphaning servers

**medium** · correctness / process-lifecycle (secondary: shell interpretation of config) · [`src/MCP/StdioTransport.php:78`](../src/MCP/StdioTransport.php#L78) · repro [`StdioCommandShellTest`](repro/MCP/StdioCommandShellTest.php) · fix validated

`StdioTransport::connect()` escapes the `args` but concatenates the unescaped `command` into a single string, so `proc_open` runs it through `sh -c`. As a result, an executable path containing spaces (common under "Program Files" or "Application Support") fails with "MCP server process has terminated unexpectedly."; `proc_terminate()` in `disconnect()` signals the shell wrapper rather than the server, so a server that does not exit promptly on stdin EOF keeps running as an orphan, which accumulates in long-running workers and in children forked by `parallelToolCalls()`; and shell metacharacters in `command` are interpreted, which matters only when the command path comes from a partly trusted config source.

Suggested fix: in `src/MCP/StdioTransport.php` `connect()`, pass an argv array (`[$command, ...$args]`) to `proc_open` instead of a shell string, suppress the spawn warning with `@` so the existing `is_resource` check throws `McpException`, remove the unused `escapeshellarg` import, and widen the message regex in `StdioTransportTest::test_a_command_that_cannot_run_fails_the_handshake`. A `command` that bundles arguments in one string (for example `npx -y server`) will no longer work and must use `args`; this was validated in a sandbox against the repro and the module's tests.

### <a id="mcp-04"></a>MCP-04 · `listTools()` pagination loops forever when a server repeats the same `nextCursor`

**low** · robustness / resource-exhaustion · [`src/MCP/McpClient.php:131`](../src/MCP/McpClient.php#L131) · repro [`McpPaginationLoopTest`](repro/MCP/McpPaginationLoopTest.php) · fix validated

`McpClient::listTools()` follows `nextCursor` until it is `null`, with no check for a cursor it has already followed, and keeps merging every page into memory. A buggy server that echoes its cursor, or a hostile one, makes the first `tools()` call (run when the agent sets up its tools) send `tools/list` requests indefinitely until PHP reaches `max_execution_time` or `memory_limit`; the per-request timeouts do not help because each page answers quickly. In the repro the client requested all 100 pages the fake offered and stopped only when the fake ran out of responses. The effect is a denial of service during tool discovery, with no data exposure; since the server is configured by the developer and has other ways to stall the client, the severity is low.

Suggested fix: in `src/MCP/McpClient.php` `listTools()`, record each cursor already followed and throw an `McpException` when the server returns one twice. A cap on the page count would also stop servers that invent fresh cursors, but that is a policy choice left open; the repeated-cursor check was validated in a sandbox against the repro and the module's tests.

### <a id="mcp-05"></a>MCP-05 · `McpConnector` tool filters use loose `in_array` and `tools()` returns a non-list array

**low** · correctness · [`src/MCP/McpConnector.php:117`](../src/MCP/McpConnector.php#L117) · repro [`McpConnectorLooseFilterTest`](repro/MCP/McpConnectorLooseFilterTest.php) · fix validated

`McpConnector::tools()` filters with `in_array()` without `strict`, and PHP compares numeric strings numerically, so `only(['100'])` also keeps server tools named `1e2` or `100.0`, and `exclude(['100'])` also drops them. In addition, `array_filter` preserves keys, so after filtering the result can be `[0 => ..., 2 => ...]` rather than a list, which breaks list destructuring, index access and `json_encode` (which emits an object) for code using the result directly. The normal `Agent` path is unaffected because `array_merge` renumbers the keys, and there is no real security exposure since the server already controls tool names; the impact is inexact name matching for numeric-looking names and a non-list return value.

Suggested fix: in `src/MCP/McpConnector.php` `tools()`, pass `true` as the strict flag to both `in_array` calls and wrap the filtered array in `array_values()` before mapping it to tools. This was validated in a sandbox against the repro and the module's tests.

### <a id="mcp-06"></a>MCP-06 · `SseHttpTransport` misses a closed event stream and waits out the full timeout

**low** · bug · [`src/MCP/SseHttpTransport.php:296`](../src/MCP/SseHttpTransport.php#L296) · repro [`SseClosedStreamTest`](repro/MCP/SseClosedStreamTest.php) · fix validated

On the non-blocking SSE stream, `fread()` returns `''` rather than `false` at EOF, so the `feof()` branch that throws "SSE stream closed by server" in `receive()` is never reached. When the server drops the stream (restart, proxy idle cut, crash), every pending `receive()` blocks for the full configured timeout (30 s by default) and then reports a misleading "Timeout waiting for response from server", stalling an agent's tool call; `waitForEndpoint()` has the same blind spot and blocks for its hard-coded 10 s. Both loops also sleep 10 ms after each read of at most 8 KB while a message is incomplete, capping throughput at roughly 800 KB/s for large results. This affects only the legacy HTTP+SSE transport (`'async' => true`) and causes latency and a wrong error, not data loss.

Suggested fix: in `src/MCP/SseHttpTransport.php`, treat `$data === false` or an empty read with `feof($this->sseStream)` as a closed stream in both `receive()` and `waitForEndpoint()`, and sleep only when the read returned nothing. Making `waitForEndpoint()` honour `config['timeout']` is an optional follow-up; the fix was validated in a sandbox against the repro and the module's tests.

### <a id="mcp-07"></a>MCP-07 · `StdioTransport` passes the entire application environment, including secrets, to MCP servers

**low** · security-hardening · [`src/MCP/StdioTransport.php:76`](../src/MCP/StdioTransport.php#L76) · repro [`StdioEnvironmentTest`](repro/MCP/StdioEnvironmentTest.php) · fix validated

`StdioTransport` builds the child environment with `array_merge(getenv(), $env)`, so every stdio MCP server inherits all variables of the PHP process: provider API keys, database passwords, cloud credentials and `APP_KEY`. With Laravel's default putenv adapter, the whole `.env` ends up there. Stdio servers are often third-party `npx`/`uvx` packages, and many read variables such as `OPENAI_API_KEY` or `GITHUB_TOKEN` on their own, so they can silently use, log or report the application's credentials. The official MCP SDKs inherit only a small allowlist plus the configured `env`. Severity is low because a deliberately malicious server running as the same OS user could read these secrets from disk anyway; this is defense in depth against accidental exposure.

Suggested fix: in `src/MCP/StdioTransport.php`, intersect `getenv()` with a protected constant allowlist (`HOME`, `LOGNAME`, `PATH`, `SHELL`, `TERM`, `USER` plus the Windows equivalents) before merging the configured `env`, and document in `src/MCP/AGENTS.md` that credentials must be passed through `env`. Case-insensitive key matching on Windows was not tested; the fix was validated in a sandbox against the repro and the module's tests.

### <a id="mcp-08"></a>MCP-08 · `StdioTransport` ignores `config['timeout']` and always sleeps 500 ms on disconnect

**low** · bug · [`src/MCP/StdioTransport.php:131`](../src/MCP/StdioTransport.php#L131) · repro [`StdioTransportTimingTest`](repro/MCP/StdioTransportTimingTest.php) · fix validated

`StdioTransport::receive()` hard-codes a 30 s timeout, unlike the HTTP transports, so a stdio tool call that takes longer (code execution, browser automation, long searches) always fails, and callers cannot set a shorter timeout to fail fast; in the repro a `timeout` of `0.3` was ignored. `disconnect()` also calls `usleep(500000)` unconditionally while the process is running, so every stdio `McpClient` teardown costs half a second, slowing scripts, CLI commands, forked children and the test suite. The reported byte-versus-character check in `send()` (`mb_strlen` instead of `strlen`) is not reachable in practice, because default `json_encode` output is ASCII; it is only misleading code.

Suggested fix: in `src/MCP/StdioTransport.php`, read the timeout from `(float) ($this->config['timeout'] ?? 30)`, replace the fixed sleep in `disconnect()` with a poll of `proc_get_status()` bounded at 500 ms, and switch the write check to `strlen`. This was validated in a sandbox against the repro and the module's tests.

### <a id="mcp-09"></a>MCP-09 · `StdioTransport` aborts the session on any non-JSON or scalar stdout line

**low** · robustness · [`src/MCP/StdioTransport.php:159`](../src/MCP/StdioTransport.php#L159) · repro [`StdioNonJsonOutputTest`](repro/MCP/StdioNonJsonOutputTest.php) · fix validated

`StdioTransport::receive()` decodes every non-empty stdout line with `JSON_THROW_ON_ERROR` and returns the result directly. A stdio server that prints a startup banner, log line or version string to stdout (forbidden by the spec but common among third-party servers) makes `new McpClient(...)` fail during the `initialize` handshake with a bare `JsonException: Syntax error`, and a stray scalar JSON line such as `1.0` produces a `TypeError` on the `array` return type. Neither is an `McpException`, so callers handling MCP failures miss them, the offending line is not shown, and `McpConnector::tools()` and the agent fail; the same line mid-session breaks a tool call. `SseHttpTransport` and the official SDKs skip such lines.

Suggested fix: in `src/MCP/StdioTransport.php` `receive()`, decode each line without throwing, return it only when it is an array, and otherwise continue to the next line; then drop the `JsonException` and `JSON_THROW_ON_ERROR` imports and change the docblock to `@throws McpException`. This was validated in a sandbox against the repro and the module's tests.

### <a id="mcp-10"></a>MCP-10 · `StreamableHttpTransport::receive()` throws `TypeError` on a scalar JSON body or SSE payload

**low** · bug · [`src/MCP/StreamableHttpTransport.php:164`](../src/MCP/StreamableHttpTransport.php#L164) · repro [`StreamableScalarBodyTest`](repro/MCP/StreamableScalarBodyTest.php) · fix validated

`StreamableHttpTransport::decodeMessages()` wraps whatever `json_decode` returns in a one-element list, so a 2xx body of `null`, `42`, `"ok"` or `true` makes `receive()` return a scalar and fail its `array` return type with a `TypeError`. The SSE path has the same flaw: a frame such as `data: 42` makes the typed closure `fn (string $json): array` throw. This is the default transport for `url` configs, so agent code that catches `McpException` to degrade gracefully (for example skipping an unavailable MCP toolset) gets an uncaught `TypeError` instead. The trigger requires a misbehaving server or proxy answering 2xx with scalar JSON, since 4xx/5xx replies become `HttpException` earlier; nothing is lost or exposed.

Suggested fix: in `src/MCP/StreamableHttpTransport.php`, route both the plain-JSON and SSE decode paths through a single protected `decodeMessage()` helper that throws `McpException('Invalid JSON response: ...')` when the decoded value is not an array. This was validated in a sandbox against the repro and the module's tests.

### <a id="mcp-11"></a>MCP-11 · SseHttpTransport ignores complete events already buffered, so responses wait for more traffic or time out

**medium** · bug · [`src/MCP/SseHttpTransport.php:300`](../src/MCP/SseHttpTransport.php#L300) · repro [`SseBufferedEventsReproTest`](repro/MCP/SseBufferedEventsReproTest.php) · fix validated

`receive()` and `waitForEndpoint()` in `SseHttpTransport` parse `sseBuffer` only when `fread()` has just returned new bytes, and both stop at the first event they need. When the server writes several events in one segment, such as a `notifications/progress` notification together with the tool result, or the `endpoint` event together with an early message, the remaining complete events stay in the buffer until unrelated bytes arrive. Against a server without heartbeats the call fails with `Timeout waiting for response from server` (30 s by default) although the server already answered. `McpClient::exchange()` discards mismatched ids, so the verified impact is delay or timeout, not wrong results. The bundled fixture's 20 ms heartbeat hides the problem.

Suggested fix: in `src/MCP/SseHttpTransport.php`, extract two protected helpers, `nextBufferedEvent()` to pop one complete event from `sseBuffer` and `readIntoBuffer()` to read from the stream, and make both loops drain buffered events before reading again, sleeping only when nothing new arrived. This was validated in a sandbox against the repro and the module's tests; as a side effect `waitForEndpoint()` also reports a closed stream immediately.

### <a id="mcp-12"></a>MCP-12 · SseHttpTransport follows cross-host redirects and sends the bearer token and custom headers

**medium** · security · [`src/MCP/SseHttpTransport.php:109`](../src/MCP/SseHttpTransport.php#L109) · repro [`SseRedirectFilesystemSecurityTest`](repro/MCP/SseRedirectFilesystemSecurityTest.php) · fix validated

`SseHttpTransport::connect()` opens the event stream with `fopen()` and an HTTP stream context carrying `Authorization: Bearer <token>` and all configured headers, without disabling `follow_location`. PHP therefore follows redirects to any host and resends the full header set, so the redirect target receives the MCP token and custom headers such as API or tenant keys. The transport then rejects the response because the first status line is not 200, so following the redirect only leaks. Triggers are an open redirect on the MCP host, a proxy or canonical-host redirect to another name, or an MCP URL a tenant can influence. The HTTP clients already drop `Authorization` on cross-host redirects; this GET stream does not.

Suggested fix: add `'follow_location' => 0` to the `http` stream context in `SseHttpTransport::connect()` in `src/MCP/SseHttpTransport.php`; `connect()` still throws `SSE connection failed: HTTP/1.1 302 Found`. This was validated in a sandbox against the repro and the module's tests.

### <a id="mcp-13"></a>MCP-13 · SseHttpTransport accepts non-HTTP URLs and opens file, php://filter and data streams

**low** · security · [`src/MCP/SseHttpTransport.php:95`](../src/MCP/SseHttpTransport.php#L95) · repro [`SseSchemeFilesystemSecurityTest`](repro/MCP/SseSchemeFilesystemSecurityTest.php) · fix validated

`SseHttpTransport::connect()` validates the configured URL only with `FILTER_VALIDATE_URL`, which accepts any scheme, and then passes it to `fopen()`, which picks a stream wrapper by scheme (`file://`, `php://filter`, `data://`, `ftp://`, `compress.*`). A `data:` URL or a planted SSE-formatted file completes the handshake, and its later `message` events are accepted by `McpClient` as genuine JSON-RPC responses. Other files give an existence oracle through different errors and timing, and `ftp://` reaches internal hosts. This matters only where untrusted users can set the MCP URL, such as multi-tenant platforms; arbitrary file contents like `/etc/passwd` are not read back directly.

Suggested fix: in `SseHttpTransport::connect()` in `src/MCP/SseHttpTransport.php`, additionally require `parse_url(..., PHP_URL_SCHEME)` to be `http` or `https` and otherwise throw `McpException('Invalid URL format')`. This was validated in a sandbox against the repro and the module's tests; the untracked `SseHttpTransportInjectionSecurityTest.php`, which uses `file://` as a fake stream, would need an HTTP stand-in, and `StreamableHttpTransport::connect()` should get the same check.

### <a id="mcp-14"></a>MCP-14 · Basic-auth credentials in URLs are echoed in exception messages

**low** · security · [`src/MCP/SseHttpTransport.php:124`](../src/MCP/SseHttpTransport.php#L124) · repro [`UrlCredentialsTrustBoundarySecurityTest`](repro/MCP/UrlCredentialsTrustBoundarySecurityTest.php) · fix validated

The framework supports basic-auth credentials as URL userinfo for MCP transports and for provider or vector-store base URIs, but failure messages interpolate the raw URI. `SseHttpTransport::connect()` reports `Failed to open SSE connection to: <url>`, `HttpException::statusError()` and `networkError()` (used by the Curl client and stream) and the duplicated messages in `GuzzleHttpClient::handleException()` include the full request URI, and the MCP transports wrap these in `McpException`. On any HTTP error, network failure or unopenable SSE stream, the plaintext password reaches logs, error trackers, `WorkflowError::toArray()` via `LogListener`, and any UI that prints the error. Credentials sent in headers are not affected.

Suggested fix: add a userinfo redactor to `src/Exceptions/HttpException.php` and use it in `statusError()` and `networkError()`, make `GuzzleHttpClient::handleException()` use those factories, and redact the URL in `SseHttpTransport::connect()`. This was validated in a sandbox against the repro and the module's tests; the validated patch makes `HttpException::redactUserInfo()` public, which needs your approval, otherwise it can be a protected helper with `SseHttpTransport` keeping its own small redaction.

### <a id="mcp-15"></a>MCP-15 · MCP tool results reach the model as JSON text, dropping isError and image content

**medium** · design · [`src/MCP/McpConnector.php:168`](../src/MCP/McpConnector.php#L168) · repro [`McpToolResultContractTest`](repro/MCP/McpToolResultContractTest.php) · fix validated

`McpConnector::invokeTool()` returns the raw `CallToolResult` `content` array and ignores `isError`, and `Tool::setResult()` JSON-encodes that array. A failed MCP tool therefore reaches Anthropic and Bedrock as a successful tool result without `is_error`, image and audio results are sent as base64 inside JSON text, wasting tokens and unusable as images by vision models, and the MCP content-item shape leaks into chat history, contrary to the rule in `src/MCP/AGENTS.md` that nothing MCP-specific passes `ToolInterface`. `ToolOutput`, its error flag and image blocks in tool results already exist in the framework. No data is lost and the core flow still runs.

Suggested fix: make `invokeTool()` in `src/MCP/McpConnector.php` return a `ToolOutput`, mapping `text` to `TextContent`, `image`/`audio` to `ImageContent`/`AudioContent` with base64 source, other item types to `TextContent` holding their JSON, and carrying `isError` into the error flag. This was validated in a sandbox against the repro and the module's tests; 5 tests in `tests/MCP/McpConnectorTest.php` that pin the old output need updated expectations.

## <a id="module-evaluation"></a>Evaluation

Evaluation has 27 findings, and the ones that matter most can make a CI gate lie or hang. [EVALUATION-05](#evaluation-05) silently skips evaluators declared final or readonly. [EVALUATION-03](#evaluation-03) loops forever on a mistyped approval decision. [EVALUATION-01](#evaluation-01) lets a shared judge carry chat history across dataset items. One invalid UTF-8 byte or NaN score suppresses the whole JSON report ([EVALUATION-21](#evaluation-21), [EVALUATION-25](#evaluation-25)). Judge prompts and transcripts can be forged by the output under test ([EVALUATION-10](#evaluation-10), [EVALUATION-26](#evaluation-26)), and the remaining items are assertion validation and reporting accuracy.

### <a id="evaluation-01"></a>EVALUATION-01 · Shared AgentJudge accumulates chat history, so verdicts depend on earlier dataset items

**medium** · bug · [`src/Evaluation/Assertions/AgentJudge.php:51`](../src/Evaluation/Assertions/AgentJudge.php#L51) · repro [`AgentJudgeIndependenceTest`](repro/Evaluation/AgentJudgeIndependenceTest.php) · fix validated

`AgentJudge::evaluate()` calls `$this->judge->structured()` on the injected agent and never clears its history. When one judge is reused across dataset items, as the module docs recommend, the second judgment request carries three messages: the previous prompt, the previous verdict and the new prompt. The same happens when several judge assertions on one item share an agent. Scores then depend on dataset order, token cost grows with every item, text injected into one output stays in the judge's context for every later item, and sequential runs disagree with `--concurrency=N`, where each forked child starts with a fresh judge.

Suggested fix: in `src/Evaluation/Assertions/AgentJudge.php::evaluate()`, call `$this->judge->resetConversation()` before each judgment, as `UserSimulator` does, guarded by `$this->judge->getThreadId() !== null` because `getChatHistory()` throws before the agent's first execution. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-02"></a>EVALUATION-02 · Corrupted FileEvaluationCache entry is served as cached output false instead of a miss

**medium** · bug · [`src/Evaluation/Cache/FileEvaluationCache.php:45`](../src/Evaluation/Cache/FileEvaluationCache.php#L45) · repro [`CorruptedCacheEntryTest`](repro/Evaluation/CorruptedCacheEntryTest.php) · fix validated

`FileEvaluationCache::has()` only checks `is_file()`, and `get()` unserializes the raw file without checking for failure. A truncated or corrupted `.cache` file (disk full, partial CI cache restore) therefore makes `unserialize()` emit a warning and return `false`. `EvaluatorRunner::runItem()` trusts it: `run()` is skipped, `evaluate()` runs against `false`, and the item is flagged `cachedRun`. This usually produces a false failure, and a lenient assertion can even pass. It contradicts the interface contract that a miss is acceptable and a crash is not, and the bad entry stays in place, so every later cached run gives the same wrong verdict until `--fresh` is used.

Suggested fix: in `src/Evaluation/Cache/FileEvaluationCache.php`, add a protected `read()` that unserializes inside a try/catch, treats a `false` result whose raw data is not `serialize(false)` as corrupt, deletes that file and returns `null`, and build `has()` and `get()` on it so a legitimately cached `false` stays a hit. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-03"></a>EVALUATION-03 · Invalid approval decision values make Conversation re-suspend and loop forever

**medium** · bug · [`src/Evaluation/Conversation/Conversation.php:193`](../src/Evaluation/Conversation/Conversation.php#L193) · repro [`ConversationInvalidDecisionTest`](repro/Evaluation/ConversationInvalidDecisionTest.php) · fix validated

`Conversation::assertCompleteDecisionSet()` exists to stop a bad decision set from looping the runner, but it only checks that every pending callId is present as a key. The payload is then resumed through `ExecutionRequest::resume()`, bypassing the `ApprovalTranslator` that `Agent::submitApprovalDecisions()` uses. A policy returning `['call_1' => 'approved']`, `true` or `'yes'` passes validation, `ToolNode` ignores the value, the call stays pending and the agent re-suspends on the same request. The loop then calls the policy and the agent again without end, so evaluations and CI jobs hang instead of failing with an `EvaluationException`, possibly repeating provider calls.

Suggested fix: at the end of `assertCompleteDecisionSet()` in `src/Evaluation/Conversation/Conversation.php`, skip an empty payload and otherwise run `(new ApprovalTranslator())->translate($payload, $request)`, rethrowing `InputTranslationException` as `EvaluationException`. The translator is stricter than `ToolNode` (it also rejects unknown callIds and `['reject']` without a reason); if that is unwanted, validate only the pending values. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-04"></a>EVALUATION-04 · UserSimulator crashes when the simulator answer omits or nulls the optional message

**medium** · bug · [`src/Evaluation/Conversation/UserSimulator.php:72`](../src/Evaluation/Conversation/UserSimulator.php#L72) · repro [`UserSimulatorNullMessageTest`](repro/Evaluation/UserSimulatorNullMessageTest.php) · fix validated

`UserSimulator::nextTurn()` intends a null or empty `message` to end the conversation. However, when the simulator model answers `{"stop": false}` or `"message": null`, both valid against the schema, the Deserializer leaves `SimulatorOutput::$message` uninitialized: it bypasses the constructor because `SimulatorOutput` has a required parameter, so promoted defaults are lost. Reading `$output->message` then throws `Error: Typed property ... must not be accessed before initialization`, aborting the simulated conversation evaluation instead of ending it cleanly. The root cause is general: any structured-output class with a required constructor parameter loses the defaults of its optional promoted parameters when the model omits them.

Suggested fix: in `src/StructuredOutput/Deserializer/Deserializer.php::deserializeObject()`, assign the declared default to each promoted constructor parameter whose property is still uninitialized; the local alternative is `($output->message ?? '') === ''` at `UserSimulator.php` line 72. The same Deserializer change is part of the fix for EVALUATION-06. Both options were validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-05"></a>EVALUATION-05 · EvaluatorDiscovery skips evaluator classes declared final or readonly

**medium** · bug · [`src/Evaluation/EvaluatorDiscovery.php:86`](../src/Evaluation/EvaluatorDiscovery.php#L86) · repro [`EvaluatorDiscoveryModifiersTest`](repro/Evaluation/EvaluatorDiscoveryModifiersTest.php) · fix validated

`EvaluatorDiscovery::getClassesFromFile()` matches classes with `/^class\s+(\w+)/m`, which only succeeds when the line starts with `class`. Evaluators declared `final class`, `readonly class` or `final readonly class`, common with php-cs-fixer's `final_class` rule, are silently dropped. `vendor/bin/neuron evaluation` then reports that no evaluator was found, or runs only a subset while the rest appear fine, so a CI evaluation gate can pass without ever evaluating the affected agent.

Suggested fix: in `src/Evaluation/EvaluatorDiscovery.php`, change the pattern to `/^(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m`; abstract classes are still rejected later by `isEvaluatorClass()`. Tokenizing with `PhpToken::tokenize` would be sturdier (attributes on the same line, unusual formatting) but needs more code. The regex change was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-06"></a>EVALUATION-06 · Missing required judge or simulator fields crash with an Error instead of triggering retry

**medium** · bug · [`src/Evaluation/JudgeScoreOutput.php:23`](../src/Evaluation/JudgeScoreOutput.php#L23) · repro [`StructuredOutputRequiredFieldsTest`](repro/Evaluation/StructuredOutputRequiredFieldsTest.php) · fix validated

`JudgeScoreOutput::$reasoning` and `SimulatorOutput::$stop` are declared required, but a structured answer that omits them, or sends them as JSON null, is still deserialized without calling the constructor, leaving the typed property uninitialized. No validation violation is raised, so the structured-output retry loop never asks the model to correct itself, unlike an out-of-range score. `AgentJudge` and `UserSimulator` then read the property and throw `Error: Typed property ... must not be accessed before initialization`, which the runner records as an internal item error. This is most likely with providers or modes that do not enforce the JSON schema strictly.

Suggested fix: add `#[IsNotNull]` to `JudgeScoreOutput::$reasoning` and `SimulatorOutput::$stop` so a missing value becomes a validation error that `StructuredOutputNode` retries, and in `Deserializer::deserializeObject()` apply promoted-parameter defaults when the constructor cannot be invoked (the same change that fixes EVALUATION-04). This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-07"></a>EVALUATION-07 · Trajectory outputs render as {} in JSON and verbose console reports

**medium** · design · [`src/Evaluation/Output/JsonOutput.php:153`](../src/Evaluation/Output/JsonOutput.php#L153) · repro [`TrajectoryOutputRenderingTest`](repro/Evaluation/TrajectoryOutputRenderingTest.php) · fix validated

`Conversation::run()` returns a `Trajectory`, the documented output for multi-turn, tool-calling and approval evaluations. `Trajectory` has only protected properties and does not implement `JsonSerializable`, so `JsonOutput::formatOutput()` and `ConsoleOutput::formatOutput()` encode it as `{}`. The JSON report's `output` field and the verbose console `Output:` line therefore never show the transcript, tool calls or final answer the judges assessed. Verdicts are unaffected, but diagnosing a failed item requires re-running it or adding custom logging.

Suggested fix: at the start of `formatOutput()` in both `src/Evaluation/Output/JsonOutput.php` and `src/Evaluation/Output/ConsoleOutput.php`, return `$output->toTranscript()` when `$output` is a `Trajectory`. Implementing `JsonSerializable` on `Trajectory` is the alternative, at the cost of more public API and more verbose console output. The transcript branch was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-08"></a>EVALUATION-08 · AssertionFailure::isAIJudgeFailure() and getAIJudgeScore() always return false and null

**low** · dead-code · [`src/Evaluation/AssertionFailure.php:72`](../src/Evaluation/AssertionFailure.php#L72) · repro [`AgentJudgeFailureDetectionTest`](repro/Evaluation/AgentJudgeFailureDetectionTest.php) · fix validated

`isAIJudgeFailure()` compares the assertion method with `'assertWithAIJudge'`, but `RuleExecutor` records `$rule->getName()` (for example `AgentJudge`, or each subclass's own name), so it is always false. No assertion ever puts `judge_score` in its context, so `getAIJudgeScore()` always returns null, and it would throw a `TypeError` if a custom assertion stored another value under that key. Custom reports built on these methods show judge failures as ordinary failures without a score. Built-in outputs do not use them, and the score is already available through `snapshot()->scores`.

Suggested fix: delete both methods from `src/Evaluation/AssertionFailure.php` and the test `test_a_non_judge_failure_has_no_judge_score()` in `tests/Evaluation/AssertionFailureTest.php`. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-09"></a>EVALUATION-09 · AgentJudge accepts out-of-range and non-finite thresholds without error

**low** · input-validation / api-inconsistency · [`src/Evaluation/Assertions/AgentJudge.php:30`](../src/Evaluation/Assertions/AgentJudge.php#L30) · repro [`AgentJudgeThresholdTest`](repro/Evaluation/AgentJudgeThresholdTest.php) · fix validated

`ClassifierJudge` rejects thresholds that are not finite or outside [0, 1], but `AgentJudge` and all its subclasses (`HelpfulnessJudge`, `CorrectnessJudge`, `FaithfulnessJudge`, `RelevanceJudge`, `TaskCompletionJudge`) accept any value. Since the judge score is limited to [0, 1] and compared with `>=`, a threshold such as `1.5`, `70`, `NAN` or `INF` can never pass, and a negative threshold always passes. A configuration typo therefore turns a metric permanently red or green instead of raising an error, which can produce CI gates that check nothing.

Suggested fix: in the `AgentJudge` constructor in `src/Evaluation/Assertions/AgentJudge.php`, throw `InvalidArgumentException` when `!is_finite($threshold) || $threshold < 0 || $threshold > 1`, as `ClassifierJudge` does; subclasses inherit the check through `parent::__construct`. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-10"></a>EVALUATION-10 · Judge prompt interpolates graded output without delimiters, allowing forged prompt sections

**low** · security · [`src/Evaluation/Assertions/AgentJudge.php:93`](../src/Evaluation/Assertions/AgentJudge.php#L93) · repro [`AgentJudgePromptIsolationTest`](repro/Evaluation/AgentJudgePromptIsolationTest.php) · fix validated

`AgentJudge::buildPrompt()` pastes the untrusted output, or the full transcript for a `Trajectory`, directly after `**Actual Output:**`, between trusted Markdown sections. An output that contains its own `**Criteria:**` or a forged `**Examples of graded outputs:**` block with a perfect score is indistinguishable from the evaluator's own prompt: the verifier showed that such output produces a prompt byte-for-byte identical to a legitimate calibration example. This matters mainly when evaluating prompt-injection resistance or tool results from external data, where an inflated score lets a regression pass. `UserSimulator::buildPrompt()` has the same pattern. Nothing leaks; the worst outcome is a corrupted eval score.

Suggested fix: in `src/Evaluation/Assertions/AgentJudge.php`, wrap the output in `<actual_output>` tags, escape any closing tag inside it, and state in the closing line that the tag content is data to grade, not instructions; update the three exact-prompt assertions in `AgentJudgeTest`, and apply a `<transcript>` fence to `UserSimulator::buildPrompt()`. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-11"></a>EVALUATION-11 · MatchesRegex reports invalid patterns and PCRE failures as failed agent verdicts

**low** · bug · [`src/Evaluation/Assertions/MatchesRegex.php:19`](../src/Evaluation/Assertions/MatchesRegex.php#L19) · repro [`MatchesRegexInvalidPatternTest`](repro/Evaluation/MatchesRegexInvalidPatternTest.php) · fix validated

`preg_match()` returns `false` for an invalid pattern and for engine failures such as hitting the backtrack limit, but `MatchesRegex` folds that into `=== 1` and returns `AssertionResult::fail(0.0)`. A pattern typo is therefore reported as the agent failing on every item, which looks like a model regression, and a catastrophic-backtracking pattern gives output-dependent false failures that vary with `pcre.backtrack_limit` and JIT settings. The only hint is a PHP warning for compile errors; engine failures give no signal. This contradicts the module contract that evaluator errors are exceptions, not verdicts.

Suggested fix: in `src/Evaluation/Assertions/MatchesRegex.php::evaluateString()`, throw `InvalidArgumentException` naming the pattern and the PCRE error when `preg_match()` returns `false`, and keep the pass/fail branches for `1` and `0`. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-12"></a>EVALUATION-12 · StringContains assertions are case-insensitive only for ASCII letters

**low** · bug · [`src/Evaluation/Assertions/StringContains.php:20`](../src/Evaluation/Assertions/StringContains.php#L20) · repro [`StringContainsMultibyteCaseTest`](repro/Evaluation/StringContainsMultibyteCaseTest.php) · fix validated

`StringContains`, `StringContainsAll` and `StringContainsAny` document case-insensitive matching but lowercase with `strtolower()`, which since PHP 8.2 only affects ASCII. `strtolower('ÉCOLE')` returns `École`, so `new StringContains('ÉCOLE')` fails on `Une école à Paris`, and `ÜBER` does not match `über`. Evaluations of agents answering in accented Latin, German, Greek, Cyrillic and similar languages produce false failures with misleading messages whenever keyword and output differ only in the case of a non-ASCII letter.

Suggested fix: replace `strtolower` with `mb_strtolower` for both haystack and keywords in `src/Evaluation/Assertions/StringContains.php`, `StringContainsAll.php` and `StringContainsAny.php`; `mb_stripos()` would work equally well. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-13"></a>EVALUATION-13 · StringContainsAll and StringContainsAny silently skip non-string keywords

**low** · design · [`src/Evaluation/Assertions/StringContainsAll.php:29`](../src/Evaluation/Assertions/StringContainsAll.php#L29) · repro [`StringContainsNonStringKeywordTest`](repro/Evaluation/StringContainsNonStringKeywordTest.php) · fix validated

Both assertions skip non-string keywords with `continue`. `new StringContainsAll(['order', 123])` passes on `order confirmed` without checking `123`, a false pass. `new StringContainsAny([123])` fails on `order 123` with the message "Expected 'order 123' to contain any of: 123", which contradicts itself. Keywords often come from JSON datasets where values such as order IDs decode as integers, so an evaluation can report a pass it never checked or a failure its own message disproves, against the module rule that wrong input types fail with `InvalidArgumentException`.

Suggested fix: in the constructors of `src/Evaluation/Assertions/StringContainsAll.php` and `StringContainsAny.php`, throw `InvalidArgumentException` for any non-string keyword and remove the skip from `evaluateString()`; the two `StringContainsAnyTest` cases that lock in the skipping must become tests expecting the exception. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-14"></a>EVALUATION-14 · StringDistance with maxDistance 0 throws DivisionByZeroError on identical strings

**low** · bug · [`src/Evaluation/Assertions/StringDistance.php:25`](../src/Evaluation/Assertions/StringDistance.php#L25) · repro [`StringDistanceMaxDistanceTest`](repro/Evaluation/StringDistanceMaxDistanceTest.php) · fix validated

With `maxDistance` set to `0`, the natural way to require an exact match, an identical output reaches the scoring line with distance `0` and computes `1.0 - (0 / 0)`, throwing `DivisionByZeroError`. The runner records an item error, so exactly the correct outputs are not counted as passed, while differing outputs fail normally. The existing `test_with_zero_max_distance` actually uses `1`, which hid the problem. A negative `maxDistance` is also accepted silently and makes every input fail with a confusing message instead of reporting the misconfiguration.

Suggested fix: in `src/Evaluation/Assertions/StringDistance.php`, throw `InvalidArgumentException` in the constructor when `maxDistance < 0`, and compute the score as `1.0` when `maxDistance === 0`. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-15"></a>EVALUATION-15 · TrajectoryMatches Strict and Subset modes fail when expected names are not a list

**low** · bug · [`src/Evaluation/Assertions/Trajectory/TrajectoryMatches.php:80`](../src/Evaluation/Assertions/Trajectory/TrajectoryMatches.php#L80) · repro [`TrajectoryMatchesNonListExpectedTest`](repro/Evaluation/TrajectoryMatchesNonListExpectedTest.php) · fix validated

`TrajectoryMatches` stores `$expected` without reindexing. Strict mode compares `$actual === $this->expected`, keys included, and Subset mode reads `$this->expected[$position]`. An expected list built with `array_filter()` or `unset()`, such as `[1 => 'search', 2 => 'refund_order']`, therefore fails Strict and fails Subset with an "Undefined array key 0" warning, while Unordered and Superset pass for the same correct trajectory. The result is a false negative in the default mode, and the warning can be escalated to an error by strict error handlers.

Suggested fix: in the constructor of `src/Evaluation/Assertions/Trajectory/TrajectoryMatches.php`, assign `$this->expected = array_values($expected)`. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-16"></a>EVALUATION-16 · FileEvaluationCache keys can escape the cache directory via path traversal

**low** · security-hardening · [`src/Evaluation/Cache/FileEvaluationCache.php:72`](../src/Evaluation/Cache/FileEvaluationCache.php#L72) · repro [`FileEvaluationCacheKeyConfinementTest`](repro/Evaluation/FileEvaluationCacheKeyConfinementTest.php) · fix validated

`FileEvaluationCache::path()` concatenates the key into `$directory . '/' . $key . '.cache'` without validation, so `set('../escaped', ...)` writes outside the cache directory, and `has()`/`get()` can read and unserialize (with all classes allowed) a `.cache` file elsewhere. The built-in runner is not affected because `CacheKey` always produces sha256 hex keys. The exposure is limited to application code or third-party runners that use this public class with keys derived from dataset content or other unsanitised input, and writes are confined to the `.cache` suffix and the process's own permissions.

Suggested fix: in `src/Evaluation/Cache/FileEvaluationCache.php`, add a protected `isValidKey()` matching `/^[A-Za-z0-9_-]+$/` and treat an invalid key as a miss in `has()`, `get()` and `set()`, following the interface's miss-never-crash contract. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-17"></a>EVALUATION-17 · ConfigLoader re-executes evaluation.php on every getter call

**low** · design · [`src/Evaluation/Config/ConfigLoader.php:33`](../src/Evaluation/Config/ConfigLoader.php#L33) · repro [`ConfigLoaderSingleLoadTest`](repro/Evaluation/ConfigLoaderSingleLoadTest.php) · fix validated

`ConfigLoader::load()` runs `require $file` on every call, and `getOutputDrivers()`, `getResolver()`, `getRunner()` and `getCachePath()` each call it, so `vendor/bin/neuron evaluation` executes `evaluation.php` up to four times. A config that declares a helper function or class dies with a "Cannot redeclare" fatal error on the second read. A config that bootstraps an application container builds a new container each time, paying the startup cost repeatedly and handing the resolver and the runner's child-process hooks different application instances, which can cause state mismatches.

Suggested fix: in `src/Evaluation/Config/ConfigLoader.php`, memoise the loaded array in a protected `?array $config` property with `return $this->config ??= $this->readConfig();`, moving the current body into `readConfig()`. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-18"></a>EVALUATION-18 · JsonDataset accepts non-list JSON, causing an internal TypeError in the runner

**low** · input-validation · [`src/Evaluation/Dataset/JsonDataset.php:46`](../src/Evaluation/Dataset/JsonDataset.php#L46) · repro [`JsonDatasetItemsTest`](repro/Evaluation/JsonDatasetItemsTest.php) · fix validated

`JsonDataset::load()` only checks `is_array($data)`, although its error message says the dataset must be an array of objects. A single object, an object keyed by name, or a list of scalars is accepted. `EvaluatorRunner` then calls `runItem()` outside the per-item try and throws a `TypeError` about `runItem()` arguments (string index or non-array item). `EvaluationCommand` catches it per evaluator, so other evaluators still run, but every item of that evaluator is lost and the message does not point at the dataset file; with `--concurrency` the error surfaces inside a forked child.

Suggested fix: in `src/Evaluation/Dataset/JsonDataset.php`, throw the existing `InvalidArgumentException('Dataset must be an array of objects')` unless the data is a list whose every item is an array, using `array_is_list()` plus a per-item check. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-19"></a>EVALUATION-19 · EvaluatorDiscovery returns evaluators in unsorted filesystem iteration order

**low** · design · [`src/Evaluation/EvaluatorDiscovery.php:54`](../src/Evaluation/EvaluatorDiscovery.php#L54) · repro [`EvaluatorDiscoveryOrderTest`](repro/Evaluation/EvaluatorDiscoveryOrderTest.php) · fix validated

`EvaluatorDiscovery::getPhpFiles()` collects paths from `RecursiveDirectoryIterator` and never sorts them, so evaluators are discovered, run and reported in raw directory order. The verifier observed a hash order on ext4 that was neither alphabetical nor creation order, and other filesystems order entries differently. Evaluator run order, the console breakdown and the JSON `evaluators` list can therefore differ between a developer machine and CI, making report diffs noisy and order-dependent evaluator side effects hard to reproduce.

Suggested fix: in `src/Evaluation/EvaluatorDiscovery.php`, call `sort($files, SORT_STRING)` at the end of `getPhpFiles()`; `test_discovers_concrete_evaluators_recursively` could then use `assertSame` to pin the order. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-20"></a>EVALUATION-20 · ConsoleOutput prints untrusted text with raw terminal control sequences

**low** · security · [`src/Evaluation/Output/ConsoleOutput.php:238`](../src/Evaluation/Output/ConsoleOutput.php#L238) · repro [`ConsoleOutputEscapingTest`](repro/Evaluation/ConsoleOutputEscapingTest.php) · fix validated

`ConsoleOutput` echoes agent outputs, item and evaluator error messages, and assertion failure messages (which usually embed the output) without escaping control characters. An LLM answer, provider error body or exception message containing ESC sequences or `\r` can clear the screen, move the cursor, overwrite earlier lines or recolor text in a terminal or ANSI-rendering CI log viewer, for example hiding failures behind a forged green `OK`. Item errors are printed even without `--verbose`. The exit code and `JsonOutput` are unaffected, so automated gating is not bypassed; the risk is misleading a human reading the log.

Suggested fix: in `src/Evaluation/Output/ConsoleOutput.php`, add a protected `escapeControlCharacters()` using `addcslashes($text, "\0..\10\13..\37\177")` and apply it at the evaluator error line, the item error line, `formatOutput()` and the failure summary line. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-21"></a>EVALUATION-21 · One invalid UTF-8 string or NaN score aborts the entire JSON report

**low** · robustness · [`src/Evaluation/Output/JsonOutput.php:41`](../src/Evaluation/Output/JsonOutput.php#L41) · repro [`JsonOutputRobustnessTest`](repro/Evaluation/JsonOutputRobustnessTest.php) · fix validated

`JsonOutput::output()` encodes the whole report with `JSON_THROW_ON_ERROR` and no substitution flags. A single item with invalid UTF-8 in its input, output or error message (for example a `substr()`-truncated multibyte string or Latin-1 data), or a NaN/INF score from a custom assertion, throws `RuntimeException` for the whole report. `OutputPipeline` only writes this to `error_log` and the exit code does not reflect it, so CI finds no JSON report and the results of every other evaluator and item are missing from it. The console output still works; no built-in assertion produces NaN.

Suggested fix: in `src/Evaluation/Output/JsonOutput.php::output()`, add `JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR` to the flags. With the partial-output flag a NaN/INF score is written as `0`, so mapping non-finite floats to `null` before encoding would be more precise. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-22"></a>EVALUATION-22 · Assertion failures always report BaseEvaluator line 67 instead of the assert() line

**low** · bug · [`src/Evaluation/RuleExecutor.php:76`](../src/Evaluation/RuleExecutor.php#L76) · repro [`AssertionFailureLineTest`](repro/Evaluation/AssertionFailureLineTest.php) · fix validated

`RuleExecutor::recordFailure()` reads the line from `debug_backtrace()[3]`, the `evaluate()` frame, whose line is where `evaluate()` was called in `BaseEvaluator::performEvaluation` (line 67), not where `$this->assert()` was called in the user's evaluator. Every `AssertionFailure` therefore reports line 67, a line in a different file. `getFullDescription()` points developers at the wrong place, and ConsoleOutput's "Assertion Failure Summary" groups all of an evaluator's failures under one location. Pass/fail results and scores are unaffected.

Suggested fix: in `src/Evaluation/RuleExecutor.php::recordFailure()`, take `line` from frame 2 (the `assert()` call) and keep `class` from frame 3. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-23"></a>EVALUATION-23 · Concurrent evaluation runs return results in completion order instead of dataset order

**low** · bug · [`src/Evaluation/Runner/EvaluatorRunner.php:119`](../src/Evaluation/Runner/EvaluatorRunner.php#L119) · repro [`ConcurrentResultOrderTest`](repro/Evaluation/ConcurrentResultOrderTest.php) · fix validated

`EvaluatorRunner::runParallel()` returns the `Fork::run()` output unchanged. spatie/fork keys each output by task order but inserts it when the child finishes, so the array iterates in completion order, contrary to the method's docblock. `EvaluationResults::getResults()` and the methods built on it, `EvaluationReport::getResults()`, the console failure listing and the JSON `results` array all follow that order, so two `--concurrency` runs of the same evaluation can list results differently. Diffs become noisy, positional consumers break, and position-based tests become flaky. Counts, pass rates and scores are unaffected, and each result keeps its correct index.

Suggested fix: in `src/Evaluation/Runner/EvaluatorRunner.php::runParallel()`, apply `ksort()` to the Fork output before returning it and correct the docblock. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-24"></a>EVALUATION-24 · Runner item errors keep only the exception message, losing class and location

**low** · design · [`src/Evaluation/Runner/EvaluatorRunner.php:166`](../src/Evaluation/Runner/EvaluatorRunner.php#L166) · repro [`EvaluatorRunnerErrorDiagnosticsTest`](repro/Evaluation/EvaluatorRunnerErrorDiagnosticsTest.php) · fix validated

`EvaluatorRunner::runItem()` catches every `Throwable` and stores only `$e->getMessage()`. Console and JSON reports therefore show messages such as `Division by zero` or `Typed property X::$y must not be accessed before initialization` with no exception class and no file and line, and nothing in verbose mode adds them. Users cannot tell an evaluator bug from a provider failure or a framework bug without re-running outside the runner, which is harder in concurrent runs where the exception exists only in a forked child. Results are correct; only diagnosability suffers.

Suggested fix: in `src/Evaluation/Runner/EvaluatorRunner.php`, record the error through a protected `describeError()` that formats class, message, file and line with `sprintf`; the ten exact `getError()` assertions in `EvaluatorRunnerTest` and `EvaluatorRunnerCacheTest` become `assertStringContainsString`, and the evaluator-level catch in `EvaluationCommand` could use the same format. This was validated in a sandbox against the repro and the module's tests.

### <a id="evaluation-25"></a>EVALUATION-25 · One invalid UTF-8 byte in model output aborts the JSON report and breaks Trajectory serialisation

**medium** · bug · [`src/Evaluation/Output/JsonOutput.php:41`](../src/Evaluation/Output/JsonOutput.php#L41) · repro [`EvaluationOutputEncodingReproTest`](repro/Evaluation/EvaluationOutputEncodingReproTest.php) · fix validated

`JsonOutput` encodes the whole report with `JSON_THROW_ON_ERROR` but without `JSON_INVALID_UTF8_SUBSTITUTE`, so a single invalid UTF-8 byte in any item's output throws `Failed to encode summary to JSON` after the full run, and no results reach the file or stdout. `Trajectory::__serialize()` uses `json_decode((string) json_encode(...))`; with invalid UTF-8 the messages become `null` and `__unserialize()` throws a `TypeError`. With `--concurrency>1` this surfaces as an uncaught error in the parent and can crash the run, and with `--cache` the poisoned entry fails on every read. Realistic triggers are app-side byte truncation or legacy-encoded tool output, since provider text is normally valid UTF-8.

Suggested fix: add `JSON_INVALID_UTF8_SUBSTITUTE` to the `json_encode` in `src/Evaluation/Output/JsonOutput.php`, and in `Trajectory::__serialize()` encode with `JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE` and decode with `JSON_THROW_ON_ERROR`, so remaining failures reach the existing `ensureSerializable` and cache fallbacks. This was validated in a sandbox against the repro and the module's tests; applying the same flag in `ConsoleOutput` is an optional follow-up that needs a test data set update.

### <a id="evaluation-26"></a>EVALUATION-26 · Assistant or tool text can forge user turns and approved tool calls in evaluation transcripts

**low** · security · [`src/Evaluation/Conversation/Trajectory.php:218`](../src/Evaluation/Conversation/Trajectory.php#L218) · repro [`TranscriptForgingReproTest`](repro/Evaluation/TranscriptForgingReproTest.php) · fix validated

`Trajectory::toTranscript()` renders turns as `User: ...`, `Assistant: ...`, `Tool call: name(args) [approved]` and `Tool result (name): ...` lines and inserts message content and tool results verbatim. Content containing a newline followed by `User: ` or a `Tool call: ... [approved]` line is indistinguishable from a genuine entry. This transcript feeds `TaskCompletionJudge`, the `AgentJudge` trajectory mode and `UserSimulator`, so the agent under test or untrusted data it fetched can make a judge score a failed task as complete or make the simulator stop early. `AgentJudge::buildPrompt()` appends the judged output raw after `**Actual Output:**` with the same weakness. Impact is limited to evaluation results; nothing runs in production.

Suggested fix: in `src/Evaluation/Conversation/Trajectory.php`, add a protected `indentContinuation()` helper that indents continuation lines and route message content, approval and rejection reasons and tool results through it, so only real entries start at column 0. This was validated in a sandbox against the repro and the module's tests; the `AgentJudge::buildPrompt()` change, wrapping the output in a clearly delimited data block, was not validated. Unambiguous formatting removes structural forgery but not every prompt-injection risk.

### <a id="evaluation-27"></a>EVALUATION-27 · Evaluation console report writes terminal control sequences from model output unescaped

**low** · security · [`src/Evaluation/Output/ConsoleOutput.php:237`](../src/Evaluation/Output/ConsoleOutput.php#L237) · repro [`ConsoleOutputEscapeReproTest`](repro/Evaluation/ConsoleOutputEscapeReproTest.php) · fix validated

`ConsoleOutput` echoes model-controlled text raw: string outputs in `formatOutput()` and assertion failure messages in verbose mode, and item and evaluator errors in every mode. Failure messages often embed the model output. A model or tool that emits ESC or OSC sequences (cursor-up plus erase-line, clear screen, terminal title, OSC 8 hyperlinks) can rewrite what a developer or CI log viewer shows, for example erasing `FAILURES!` lines and printing a fake `OK` (CWE-150). Exit codes and JSON output stay correct, so automated gating is not bypassed; only people reading the console can be misled.

Suggested fix: add a protected `escapeControlCharacters()` helper to `src/Evaluation/Output/ConsoleOutput.php` using `addcslashes($text, "\0..\x08\x0B..\x1F\x7F")`, which keeps newlines and tabs, and route outputs, errors and failure messages through it. This was validated in a sandbox against the repro and the module's tests; C1 controls such as 8-bit CSI are not covered.

## <a id="module-console"></a>Console

The make:* generator has three findings. [CONSOLE-01](#console-01) crashes when a PSR-4 prefix maps to several directories, and [CONSOLE-03](#console-03) writes to the first matching prefix instead of the most specific one. [CONSOLE-02](#console-02) accepts any class name, which allows path traversal and produces invalid PHP. Because the input comes from the developer's own CLI argument, it is a robustness problem, not a vulnerability.

### <a id="console-01"></a>CONSOLE-01 · `make:*` crashes with a `TypeError` when a PSR-4 prefix maps to several directories

**medium** · bug · [`src/Console/Make/MakeCommand.php:159`](../src/Console/Make/MakeCommand.php#L159) · repro [`MakeCommandComposerPsr4ListTest`](repro/Console/MakeCommandComposerPsr4ListTest.php) · fix validated

Composer allows a PSR-4 prefix to map to a list of directories, for example `"App\\": ["src/", "lib/"]`. `MakeCommand::loadPsr4Config()` returns the raw config typed as `array<string, string>`, and `getFilePath()` then calls `rtrim($directory, '/')` on the array. The resulting `TypeError` is caught by `run()`, which prints the raw PHP message and exits with code 1. Every `make:*` command is therefore unusable for that prefix, including calls with a bare class name when the array-mapped prefix is the first one. When no prefix matches, `printAvailableNamespaces()` prints `App\ -> Array` with an `Array to string conversion` warning. Nothing is written or corrupted.

Suggested fix: normalise the map once in `loadPsr4Config()` so it returns `array<string, non-empty-list<string>>` (wrapping strings in a list and dropping empty lists), use the first directory in `getFilePath()`, and join all directories in `printAvailableNamespaces()`. This was validated in a sandbox against the repro and the module's tests.

### <a id="console-02"></a>CONSOLE-02 · `make:*` accepts invalid class names and writes broken or misplaced files

**low** · input-validation · [`src/Console/Make/MakeCommand.php:95`](../src/Console/Make/MakeCommand.php#L95) · repro [`MakeCommandNameValidationTest`](repro/Console/MakeCommandNameValidationTest.php) · fix validated

`generateClass()` and `parseNamespaceAndClass()` never validate the name, whose segments go straight into both the file path and the generated source. Names with `..` segments (such as `App\..\..\..\Escaped`) write the file above the project, a leading or trailing backslash, a leading digit or a reserved word produces a file that fails `php -l`, and text such as `Foo{}echo(1);class Bar` is copied verbatim into the class. In every case the command exits 0 and reports `Success`. The input is a CLI argument from the developer, so this is a robustness and input-validation defect for developers and scripts driving the generator, not a security vulnerability; existing files are not overwritten.

Suggested fix: in `src/Console/Make/MakeCommand.php`, reject the name in `generateClass()` with an error and exit code 1 unless a new `protected isValidClassName()` accepts it: every `\`-separated segment must match `/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/`, and the class segment must not be in a `RESERVED_CLASS_NAMES` list. Once each segment is an identifier the path cannot leave the PSR-4 directory, so no symlink or `realpath` check is needed. This was validated in a sandbox against the repro and the module's tests.

### <a id="console-03"></a>CONSOLE-03 · `make:*` writes to the first matching PSR-4 prefix, not the most specific

**low** · bug · [`src/Console/Make/MakeCommand.php:152`](../src/Console/Make/MakeCommand.php#L152) · repro [`MakeCommandMostSpecificPrefixTest`](repro/Console/MakeCommandMostSpecificPrefixTest.php) · fix validated

`getFilePath()` returns the first composer `psr-4` prefix that matches, in declaration order. With `{"App\\": "src/", "App\\Tests\\": "tests/"}`, `make:agent App\Tests\MyAgent` creates `src/Tests/MyAgent.php` instead of `tests/MyAgent.php`, although Composer's `ClassLoader` gives the longest prefix precedence. The file still autoloads through the broader prefix, but it lands in the wrong source tree, so developers with nested or overlapping prefixes (broader one declared first) must move it by hand or end up with test or domain code under `src/`. Declaring the specific prefix first hides the problem.

Suggested fix: in `getFilePath()` of `src/Console/Make/MakeCommand.php`, sort the loaded config with `uksort` by key length, longest first, before the matching loop; `getDefaultNamespace()` and `printAvailableNamespaces()` keep declaration order. This was validated in a sandbox against the repro and the module's tests.

## <a id="module-classifier"></a>Classifier

The only finding is [CLASSIFIER-01](#classifier-01). ProbabilityDistribution keeps integer probabilities when every input is an int, which contradicts its float contract. Chosen options and scores are unaffected; only strict comparisons and serialization see the difference.

### <a id="classifier-01"></a>CLASSIFIER-01 · `ProbabilityDistribution` keeps integer probabilities when every input value is an int

**low** · docs-mismatch · [`src/Classifier/ProbabilityDistribution.php:47`](../src/Classifier/ProbabilityDistribution.php#L47) · repro [`ProbabilityDistributionFloatTest`](repro/Classifier/ProbabilityDistributionFloatTest.php) · fix validated

Normalization computes `$probability / $total`. When every input is an int, which in practice means a one-hot distribution such as `['yes' => 1, 'no' => 0]` from a provider, a `FakeClassifier` or user code, `array_sum` returns int `1` and PHP's exact int division keeps the results as ints. The public readonly `$probabilities` is documented as `array<array-key, float>`, so consumers reading `ChoiceResult`/`ScoreResult->distribution->probabilities` with strict comparisons (`=== 1.0`), `is_float()` checks or serialization see different values depending on whether the provider sent `1` or `1.0`. Scores and chosen options are still computed correctly, and mixed int/float inputs already produce floats.

Suggested fix: in the normalization loop of `src/Classifier/ProbabilityDistribution.php`, cast each value with `$probabilities[$id] = (float) ($probability / $total);`. This was validated in a sandbox against the repro and the module's tests.

## <a id="module-exceptions"></a>Exceptions

The only finding is [EXCEPTIONS-01](#exceptions-01). RunInFlightException throws a TypeError when it is built for a Suspended status without an interrupt. The framework never creates that combination, so only a corrupted control record or direct construction reaches it.

### <a id="exceptions-01"></a>EXCEPTIONS-01 · `RunInFlightException` throws `TypeError` for a Suspended status without an interrupt

**low** · robustness · [`src/Exceptions/RunInFlightException.php:44`](../src/Exceptions/RunInFlightException.php#L44) · repro [`RunInFlightSuspendedWithoutInterruptTest`](repro/Exceptions/RunInFlightSuspendedWithoutInterruptTest.php) · fix validated

The constructor accepts `?InterruptRequest $interrupt = null`, but for `WorkflowStatus::Suspended` `describeGeneration()` passes it to `describeInterrupt(InterruptRequest $request)`, which rejects null. Constructing `new RunInFlightException('thread-1', 'run-a', WorkflowStatus::Suspended, 2)` therefore throws a `TypeError` instead of the intended `WorkflowException` with a resume hint, and callers catching `WorkflowException` or `RunInFlightException` miss it. No framework code path creates a Suspended control record without an interrupt, so this is only reachable from a corrupted or hand-written `__control` record reaching `WorkflowEngine::ignite()`, or from user code building the exception directly. Normal operation is unaffected.

Suggested fix: in `src/Exceptions/RunInFlightException.php`, change `describeInterrupt()` to accept `?InterruptRequest` and return a generic description such as `an unknown interrupt` for null. This was validated in a sandbox against the repro and the module's tests. An alternative is to have `WorkflowControl::suspended()` refuse a null interrupt, which changes the Workflow core while leaving the exception still accepting the combination.

## <a id="module-docs"></a>Documentation

There are two documentation findings. [DOCS-02](#docs-02) matters most: the documented conversation-memory example keeps every thread's conversations in the shared RAG store with the default retrieval, so one user's exchanges can surface in another user's context. [DOCS-01](#docs-01) says hidden tools stay callable, although the Agent refuses them; the doc should be corrected together with the toolkit case in [AGENT-01](#agent-01).

### <a id="docs-01"></a>DOCS-01 · neuron-tool skill doc claims hidden tools stay callable, but the Agent rejects them

**low** · docs-mismatch · [`skills/neuron-tool/SKILL.md:365`](../skills/neuron-tool/SKILL.md#L365) · repro [`HiddenToolDocContractTest`](repro/Docs/HiddenToolDocContractTest.php)

The Tool Visibility section of `skills/neuron-tool/SKILL.md` says hidden tools are "still callable". In fact `Agent::resolveTools()` leaves tools marked `visible(false)` out of the registry that `ToolNode` resolves against, so a model call to one fails with `ToolException` (`The tool secret is not registered on this agent`). The code matches `src/Agent/AGENTS.md` and is pinned by `AgentTest::test_a_hidden_tool_cannot_be_called_by_the_model`; only the doc is wrong. Developers and coding agents following the skill, for example to hide an "internal" tool, get a runtime exception. The behaviour fails closed, so nothing is exposed. A related, untested inconsistency: `resolveTools()` does not apply `isVisible()` to tools coming from a toolkit, so a hidden toolkit tool is still offered and executed.

Suggested fix: rewrite the Tool Visibility section of `skills/neuron-tool/SKILL.md` to state that a hidden tool is neither offered nor executable, drop the "Internal tools called by other tools" use case, and either document that visibility has no effect inside toolkits or make `Agent::resolveTools()` filter toolkit tools with `isVisible()` as a separate change.

### <a id="docs-02"></a>DOCS-02 · Documented shared-store conversation memory leaks every thread's conversations into other threads

**medium** · security · [`skills/neuron-agent/references/conversation-memory.md:44`](../skills/neuron-agent/references/conversation-memory.md#L44) · repro [`SharedStoreConversationMemoryContractTest`](repro/Docs/SharedStoreConversationMemoryContractTest.php)

The `RememberingAssistant` example in `skills/neuron-agent/references/conversation-memory.md` stores conversations with `ConversationIngestionNode` in the RAG's own vector store and keeps the default retrieval. `ResolveRetrieval::retrieval()` returns a whole-store `SimilarityRetrieval` with no `sourceType` or thread scope, so every thread's `conversation` documents become ordinary similarity hits in other threads' EXTRA-CONTEXT. In the repro, Bob's prompt contains Alice's locker code. Multi-user apps following the example leak secrets and personal data across users, bypassing the thread-allowlist model the same document describes; the `CompositeRetrieval` example leaks the same way if both stores are one. The code behaves as written; the documented setup is the defect.

Suggested fix: change the example to inject a dedicated conversation store into `ConversationIngestionNode`, add a warning that an unfiltered retrieval over a shared store returns all threads' conversations, and correct the "store only" sentence. A code alternative, excluding `SemanticMemoryRetrieval::SOURCE_TYPE` in `ResolveRetrieval::retrieval()`, fixes the repro but breaks 4 tests that pin the default filters and adds a filter to every default search, so it is a design decision.

## Other observations

A few design notes came up during the review that were not filed as findings, because the current behaviour is documented or only matters under developer misuse, but they are worth a conversation.

`ToolPropertyFactory` silently drops the JSON Schema keywords it does not model (`format`, `pattern`, `minimum`/`maximum`, `minLength`, `default`, `additionalProperties`). `src/Tools/AGENTS.md` lists the supported keywords, so this is a documented limitation, but for MCP tools it means the model never learns constraints the server will enforce, and the server then rejects calls the model had no way to get right. A warning, or passing unknown keywords through verbatim, would be safer.

The speech-to-text providers (`ElevenLabsSpeechToText`, `ZAITranscription`, `OpenAISpeechToText`) open the audio path with `fopen()` and never check the result, so a missing or unreadable file produces a PHP warning and uploads `false` as the multipart file instead of throwing a `ProviderException` that names the path. The same code opens any PHP stream wrapper, which is consistent with the documented "readable file path" contract, but the provider reference should say explicitly that the path must never come from user input.

`MariaDBVectorStore` interpolates its table name into SQL without the identifier validation that `SQLMessageStore` applies and the quoting that `DatabasePersistence` applies. The value is developer configuration, so it is not exploitable from model or user input, but aligning the three SQL-backed components on one identifier policy would remove the inconsistency.

## Rejected suspicions

These were reported during the review, but the verifier concluded they are intended behaviour, not reproducible, or not worth changing.

| Reported by | Suspicion | Location | Why it was rejected |
|---|---|---|---|
| chat | The text estimate counts JSON-escaped characters, overestimating non-ASCII text about 5x | `src/Chat/History/TokenCounter.php:91` | The mechanics are reproduced exactly. In the sandbox, a test asserting that 400 'é' estimate the same as 400 'e' fails with "Failed asserting that 611 is identical to 111". So json_encode escaping does inflate the character count, and mb_strlen adds nothing. The claim that this is an overestimate is wrong, though. It compares the result against ASCII text of the same length, not against real tokenizer output. BPE to… |
| evaluation | FileEvaluationCache::get() unserializes cache files without allowed_classes (object injection from a tampered or restored cache) | `src/Evaluation/Cache/FileEvaluationCache.php:45` | The repro does what it says. In my sandbox (evaluation-f5), a serialized CacheGadget written to `<dir>/key.cache` has its __wakeup run when get() is called: "Failed asserting that true is false." That is simply how PHP's unserialize() behaves. It does not cross a trust boundary in this framework, for three reasons. (1) Precondition: the attacker needs write access to `.neuron/cache/evaluation` inside the project che… |
| http-mcp | McpConnector serialization writes MCP secrets (token, headers, env) into persisted workflow state | `src/MCP/McpConnector.php:66` | The report depends on the claim that "McpTools, and through them the connector, are serialized into interrupt/resume state". That was true in an older architecture. On this branch it is false. I checked it in the sandbox. I built an Agent with InMemoryPersistence and an McpConnector configured with a 'token' and an 'X-Api-Key' header (using FakeMcpTransport as the custom transport). I made the MCP tool require appro… |
| misc | HttpException exposes the credential-bearing request and embeds the full response body in its message | `src/Exceptions/HttpException.php:19` | I confirmed both behaviours in a sandbox at /tmp/claude-0/-home-user-neuron-ai/0289977e-0c20-549b-933a-6d8742d0a541/scratchpad/sandboxes/misc-f5 (since removed): - `$exception->request->headers['Authorization']` holds 'Bearer sk-live'. - A 1 MB body produces a 1,000,051-byte message. Neither is a framework defect. **(1) The stored request is intended.** It is documented and pinned by tests, and it matches the common… |
| misc | ListenerRegistry does not run listeners of different event classes in registration order | `src/Observability/ListenerRegistry.php:26` | The observed behaviour is accurate. I placed the repro at tests/Observability/ListenerRegistryOrderTest.php in the sandbox, and on current code it fails with [$first, $third, $second], for the reason the reporter gives: listeners are grouped per class in the order each class was first registered. It is not a bug, though. No doc, docblock or AGENTS.md promises an order across classes. PSR-14 leaves ordering to the pr… |
| prov-a | HttpException exposes the full request, including API-key headers, as a public property | `src/Exceptions/HttpException.php:18` | The repro runs and fails as described: print_r($exception->request) contains the x-api-key header. That result is expected, because the test asserts a contract the framework never promised. The request is a deliberate public part of the exception, and the leak only happens if an application dumps arbitrary exception properties itself. The suggested redaction would also not remove the exposure, so it is not a sound f… |
| prov-b | Audio providers fopen() any path or stream wrapper taken from AudioContent | `src/Providers/ElevenLabs/ElevenLabsSpeechToText.php:70` | The mechanics are real. The sandbox repro shows that `php://filter/.../resource=data://...` is passed straight to fopen() and uploaded, with no ProviderException. But the report treats a developer-supplied file path as untrusted input. By design, these speech-to-text providers read the file the developer names, so the value has the same trust level as an argument to fopen() or file_get_contents(). An attacker could … |
| rag-ingest | getRelationshipMap() depth and limit are not validated or bounded | `src/RAG/GraphStore/Neo4jGraphStore.php:106` | The reported facts are accurate. My sandbox repro confirms that depth: -1 and limit: -5 are put into the query text unchanged: "[*1..-1]" and "LIMIT -5". But this is not a defect or a security weakness: (1) There is no injection. Both values are strictly typed ints, so they cannot change the query structure. The reporter says so too. (2) Invalid values do not corrupt data or behave wrongly without notice. Neo4j reje… |
| rag-ingest | PdfReader passes the PDF path to pdftotext without guarding against a leading dash | `src/RAG/DataLoader/PdfReader.php:173` | I confirmed the argv shape in a sandbox. A subclass captured the command, and `setPdf('-layout.pdf')` (a file created in a temp cwd) produced argv[1] = '-layout.pdf'. That shape is not harmful, though. The pdftotext parser matches options by exact name, so '-layout.pdf' stays a filename. It could only turn into an option if a readable file in the cwd were named exactly like an option (e.g. `-v`, `-opw`) and were pas… |
| rag-vector | MariaDB boolean filters cast JSON true/false text to UNSIGNED (eq(true) likely never matches; eq(false) may match everything) | `src/RAG/VectorStore/Compilers/MariaDBFilterCompiler.php:176` | I rewrote the repro so it runs without the vector engine. The reporter's version needs VEC_FromText, which only exists in MariaDB 11.7 and later. The new test compiles filters with MariaDBFilterCompiler, using a schema that declares 'draft' as a boolean. It runs the generated WHERE clause and bindings through PDO against a JSON metadata table on a real MariaDB server. The table holds three rows: draft:true, draft:fa… |
| rag-vector | Meilisearch waitForTask hard-codes usleep(500ms), which makes task polling untestable | `src/RAG/VectorStore/MeilisearchVectorStore.php:236` | The report says polling is "untestable" offline and that the mutant `=== 'succeeded'` -> `!== 'enqueued'` survives. I disproved both claims in sandbox rag-vector-f22. I wrote an offline test that uses the existing RecordsVectorStoreRequests fake. Its task responses are 'processing' then 'succeeded', and it asserts that GET /tasks/11 is sent twice. On current code it passes in about 0.5s, because only one poll pays t… |
| tools-api | is_numeric() heuristic reads compact ISO dates and bare years as Unix timestamps | `src/Tools/Toolkits/Calendar/FormatDateTool.php:49` | I ran the repro in sandbox tools-api-f9 and it fails as reported: FormatDateTool('20240115', 'Y-m-d') returns '1970-08-23', not '2024-01-15'. The code works the way it was designed to, though, so this is not a bug. (1) The input contract says 'date string or timestamp', and '20240115' is a valid timestamp (1970-08-23T…). Any rule has to pick one reading, and the framework documents that numeric input means epoch sec… |
| tools-sensitive | Destructive toolkit tools declare no approval policy | `src/Tools/Toolkits/FileSystem/FileSystemToolkit.php:33` | The reporter describes the code correctly but calls intended behaviour a weakness. The docs and skills show many times that approval for these toolkits is added by the developer at attach time (requireApproval(), with(), exclude(), only()). The Agent guide uses DeleteFileTool::make()->requireApproval() as its main example, which only makes sense if the default is ungated. No documented contract is broken, so this is… |
| wf-core | WorkflowEnd::toArray() puts the entire application state into every log record | `src/Workflow/Observability/WorkflowEnd.php:23` | The reporter read the code correctly: WorkflowEnd::toArray() returns $state->all(), and LogListener logs toArray() as context. But the module guidance explicitly describes this behaviour and justifies it ("the end record retains application data under `state`"), and the tests pin it in the exact payload shape for every terminal status and for both listener paths. The two lifecycle events are meant to differ: the int… |
| wf-core | Unreachable code in Node::consumePayload and in the NodeSignature ReflectionException handler | `src/Workflow/Node.php:65` | This is not a defect. (1) The consumePayload guard is reachable through the protected extension API (shown in the sandbox), so the claim that it "can never be taken" holds only for the framework's own call path. It is a reasonable invariant check on a protected helper. (2) The ReflectionException catch in NodeSignature is unreachable with the current reflection calls, but it is a harmless defensive wrapper that keep… |
| wf-exec | AsyncBranchRunner reports BranchStart/BranchEnd for branches that a current interruption keeps from running | `src/Workflow/Executor/AsyncBranchRunner.php:38` | I rebuilt the scenario in the sandbox (tests/Workflow/Executor/BranchSpanTest.php) and recorded every observer event for both runners and both branch orders. 1. The observation holds. Under AsyncBranchRunner, the resume segment reports two image spans, whichever branch is declared first. For example: "branch-start@text workflow-node-start@text branch-start@image branch-end@image workflow-node-end@text branch-end@tex… |
