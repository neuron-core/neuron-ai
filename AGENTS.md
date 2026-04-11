# AGENTS.md

Neuron is a PHP Agentic framework for building AI agents with chat, tools, RAG, structured output, and workflow orchestration.

## Development Commands

```bash
composer test          # Run tests (PHPUnit)
composer analyse       # Static analysis (PHPStan level 5)
composer format        # Fix code style (PHP CS Fixer)
composer refactor      # Refactor code (Rector)
composer install       # Install dependencies
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

| Module | Purpose | Dependencies |
|--------|---------|--------------|
| `src/Workflow/` | Event-driven orchestration, nodes, interruptions, persistence | None |
| `src/Agent/` | AI agent with chat/stream/structured modes | Workflow, Chat, Providers, Tools |
| `src/Chat/` | Messages, content blocks, chat history | None |
| `src/Providers/` | AI provider abstractions (Anthropic, OpenAI, etc.) | Chat, HttpClient |
| `src/Tools/` | Tool system and built-in toolkits | None |
| `src/RAG/` | Document retrieval and vector stores | Agent, VectorStore |
| `src/StructuredOutput/` | JSON schema extraction | Chat |
| `src/HttpClient/` | HTTP client abstraction | None |
| `src/MCP/` | Model Context Protocol connector | HttpClient |
| `src/Observability/` | EventBus and observers | None |
| `src/Console/` | CLI commands (make:*, evaluation) | Evaluation |
| `src/Evaluation/` | AI evaluation framework | None |
| `src/Testing/` | Test fakes and utilities | Providers |

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
- Protected visibility for non-public members (never private)
- Focus on minimal code implementation, don't be verbose

## Key Patterns

- `StaticConstructor` trait → `::make()` factory method
- `HasHttpClient` trait → HTTP client injection
- Provider injection via `ResolveProvider` trait
- Node signature: `__invoke(SpecificEvent $event, WorkflowState $state): NextEvent`
- Content blocks: `TextContent`, `ImageContent`, `FileContent`, etc.
