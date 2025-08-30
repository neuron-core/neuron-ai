# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Neuron AI is a PHP framework for creating AI agents with features like chat history, tool integration, RAG (Retrieval Augmented Generation), structured output, and workflow orchestration. The codebase follows PSR-12 standards with strict typing and modern PHP 8.1+ features.

## Common Development Commands

### Testing and Quality Assurance
```bash
# Run tests
composer test
# or directly: vendor/bin/phpunit --colors=always

# Run static analysis (PHPStan level 5)
composer analyse
# or directly: vendor/bin/phpstan analyse --memory-limit=1G -v

# Fix code style (PHP CS Fixer with PSR-12)
composer format
# or directly: php-cs-fixer fix --allow-risky=yes

# Refactor code (Rector)
composer refactor
# or directly: vendor/bin/rector

# Install dependencies
composer install
```

### Individual Test Execution
```bash
# Run specific test class
vendor/bin/phpunit tests/AgentTest.php

# Run specific test method
vendor/bin/phpunit --filter testMethodName

# Run tests with coverage
vendor/bin/phpunit --coverage-html coverage/
```

## Code Architecture

Each module is placed in its own namespace under `src/`. Sub-modules are grouped into sub-namespaces.

### Core Components

**Agent System**: The framework revolves around three main entity types:
- `Agent` (src/Agent.php) - Base agent class with chat, streaming, and structured output capabilities
- `RAG` (src/RAG/RAG.php) - Extends Agent with vector search and document retrieval
- `Workflow` (src/Workflow/Workflow.php) - Event-driven node execution system for complex agentic processes with persistence and interruption support

**Provider Architecture**: Abstracted AI provider system supporting multiple LLM services:
- All providers implement `AIProviderInterface` (src/Providers/AIProviderInterface.php)
- Supported: Anthropic, OpenAI, Gemini, Ollama, HuggingFace, Mistral, Grok
- Each provider has its own MessageMapper for API-specific formatting

**Tool System**: Extensible tool framework for agent capabilities:
- Individual tools implement `ToolInterface` (src/Tools/ToolInterface.php)
- Toolkits group related tools (src/Tools/Toolkits/)
- Built-in toolkits: Calculator, MySQL, PostgreSQL, Tavily, Zep, AWS SES, Jina, Riza, Supadata

**RAG Components**:
- Vector stores: Support for Pinecone, Chroma, Elasticsearch, Qdrant, Typesense, and more
- Embeddings providers: OpenAI, Gemini, Ollama, Voyage
- Document loaders: PDF, HTML, text files with chunking strategies
- Pre/post processors for query transformation and document reranking

**Chat History**: Pluggable memory systems (InMemory, File, SQL-based)

**Structured Output**: JSON schema-based extraction with PHP class mapping using attributes

**MCP Integration**: Model Context Protocol server connector for external tool integration

### Workflow System Details

**Core Components**:
- `Workflow` - Main orchestrator that manages event-to-node mappings and execution flow
- `Node` - Abstract base class for workflow nodes that process events and return new events
- `Event` - Marker interface for workflow events that trigger node execution
- `WorkflowState` - Shared state container that persists data across node executions
- `WorkflowInterrupt` - Exception-based mechanism for human-in-the-loop interactions

**Key Features**:
- **Event-Driven Architecture**: Nodes are triggered by events, promoting loose coupling
- **Persistence Support**: Multiple persistence backends (InMemory, File-based) for workflow state
- **Human-in-the-Loop**: Built-in interruption mechanism for human feedback integration
- **Workflow Export**: Pluggable export system with MermaidExporter for diagram generation
- **State Management**: Centralized state that flows through all workflow nodes
- **Validation**: Automatic validation ensures workflows have required StartEvent handlers
- **Observability**: Full integration with observable pattern for monitoring and debugging

**File Structure**:
- `Workflow.php` - Main workflow orchestrator class
- `Node.php` - Abstract base class for workflow nodes
- `NodeInterface.php` - Interface defining node contract
- `Event.php` - Marker interface for workflow events
- `StartEvent.php/StopEvent.php` - Built-in workflow lifecycle events
- `WorkflowState.php` - State container for cross-node data sharing
- `WorkflowInterrupt.php` - Exception for workflow interruption handling
- `Exporter/` - Export system with MermaidExporter implementation
- `Persistence/` - Persistence layer with InMemory and File implementations

**Usage Pattern**:
```php
// Normal execution
$handler = Workflow::make()
    ->addNodes([
        new ValidationNode(),
        new ProcessingNode(),
        new CompletionNode(),
    ])
    ->start($initialState);

$finalState = $handler->getResult();

// Streaming events
$handler = Workflow::make()
    ->addNodes([
        new ValidationNode(),
        new ProcessingNode(),
        new CompletionNode(),
    ])
    ->start($initialState);

foreach ($handler->streamEvents() as $event) {
    // flush the event stream
    echo match (get_class($event)) {
        ValidationEvent::class => $event->validationResult,
        ProgressEvent::class => $event->message,,
        // ...
    }
}

$finalState = $handler->getResult();
```

### Key Traits and Patterns

The codebase uses PHP traits extensively for modular functionality:
- `StaticConstructor` - Provides `make()` static factory method
- `Observable` - Observer pattern implementation for monitoring
- `ResolveProvider`, `ResolveTools`, `ResolveChatHistory` - Dependency resolution

All major components support the Observer pattern for monitoring and debugging, integrating with Inspector APM.

### Directory Structure

- `src/` - Main source code with PSR-4 autoloading under `NeuronAI\` namespace
- `src/Providers/` - AI provider implementations
- `src/Tools/` - Tool system and built-in toolkits
- `src/RAG/` - RAG system components
- `src/Workflow/` - Workflow orchestration system
- `src/Chat/` - Chat messaging and history management
- `src/Observability/` - Monitoring and event system
- `tests/` - PHPUnit tests mirroring src/ structure

## Code Standards

- Strict typing enforced (`declare(strict_types=1)`)
- PSR-12 coding standard
- PHPStan level 5 static analysis
- 100% type coverage requirements (return, param, property)
- All classes use constructor property promotion where applicable
- Extensive use of PHP 8.1+ features (enums, readonly properties, etc.)

## Environment Variables

Key environment variables for development:
- `INSPECTOR_INGESTION_KEY` - For monitoring/observability
- Various provider API keys (ANTHROPIC_API_KEY, OPENAI_API_KEY, etc.)
- Database connection strings for vector store testing
