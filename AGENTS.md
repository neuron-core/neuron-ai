# Neuron AI - Project Overview

This repository contains Neuron AI, a PHP framework for building agentic applications.

## The Mental Model: Neuron Is a Composable Workflow

Before writing any Neuron code, internalize this — it shapes every correct decision:

1. **Workflow is the foundation.** Everything else is built on it. A Workflow is an event-driven graph of nodes that you can compose freely to create any agentic behaviour.

2. **A catalog of components, wired by interfaces.** Neuron ships a large collection of standalone, interchangeable building blocks — AI providers, the messaging layer, chat history, tools & toolkits, structured output, RAG, vector stores, data loaders, MCP, observability. They are connected by small, focused interfaces, so each can be used on its own or swapped for your own implementation.

3. **`Agent` is an inspiration, not a cage.** The `Agent` class is itself a Workflow: it composes the components above (chat/stream/structured nodes, tools, history, middleware) into a ready-to-use agentic entity. Study it to see how a Workflow is assembled. Then choose your path:

   - **Extend `Agent`** when its defaults fit your use case (most common starting point).
   - **Customize `Agent`** by swapping nodes or adding middleware when you need targeted changes without rebuilding from scratch.
   - **Compose your own Workflow** from the component catalog when you need bespoke control flow — `Agent` and `RAG` can even be used as nodes inside it.

You never fight the framework: the architecture you use on day one is the same one running your most complex system later.

## Development Commands

```bash
composer test          # Run tests (PHPUnit)
composer format        # Fix code style (PHP CS Fixer)
composer analyse       # Static analysis (PHPStan level 5)
```

Individual tests: `vendor/bin/phpunit tests/AgentTest.php` or `--filter testMethodName`

## Architecture

**Layered Foundation**:
```
RAG ─────► extends Agent ─────► built on Workflow
                              │
Tools ◄───────────────────────┤
Providers ◄───────────────────┤
Chat ◄────────────────────────┴──► shared across all
```

**Workflow** is the foundation. **Agent** composes workflow nodes for AI interactions. **RAG** extends Agent with document retrieval. All share **Chat** for messaging and **Providers** for AI backends.

## Modules

| Module | Purpose                                                       | Dependencies |
|--------|---------------------------------------------------------------|--------------|
| `src/Workflow/` | Event-driven orchestration, nodes, interruptions, persistence | None |
| `src/Agent/` | AI agent with chat/stream/structured modes                    | Workflow, Chat, Providers, Tools |
| `src/Chat/` | Messages, stream content blocks, chat history                 | None |
| `src/Providers/` | AI provider abstractions (Anthropic, OpenAI, etc.)            | Chat, HttpClient |
| `src/Tools/` | Tool system and built-in toolkits                             | None |
| `src/RAG/` | Document retrieval and vector stores                          | Agent, VectorStore |
| `src/StructuredOutput/` | JSON schema extraction                                        | Chat |
| `src/HttpClient/` | HTTP client abstraction                                       | None |
| `src/MCP/` | Model Context Protocol connector                              | HttpClient |
| `src/Observability/` | PSR-14 event dispatching and observers                        | None |
| `src/Console/` | CLI commands (make:*, evaluation)                             | Evaluation |
| `src/Evaluation/` | AI evaluation framework (incl. multi-turn conversation eval)  | Agent, Chat, Workflow, Tools |
| `src/Testing/` | Test fakes and utilities                                      | Providers |

## Context Discovery

Read module-specific `AGENTS.md` files when working on that area:

- Working with workflows/interruptions? → `src/Workflow/AGENTS.md`
- Working with agents/chat/stream? → `src/Agent/AGENTS.md`
- Working with messages/history? → `src/Chat/AGENTS.md`
- Adding/modifying AI providers? → `src/Providers/AGENTS.md`
- Creating tools/toolkits? → `src/Tools/AGENTS.md`
- Working with RAG/vectors? → `src/RAG/AGENTS.md`
- CLI commands/code generation? → `src/Console/AGENTS.md`
- AI evaluation/testing? → `src/Evaluation/AGENTS.md`

## Code Standards

- Strict types: `declare(strict_types=1)`
- PSR-12 formatting
- PHPStan level 5
- 100% type coverage (params, returns, properties)
- PHP 8.1+ features (enums, constructor promotion)
- Use **protected** visibility for non-public properties and methods (never private)

## Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

## Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.
