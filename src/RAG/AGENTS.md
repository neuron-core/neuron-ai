# RAG Module

Retrieval Augmented Generation. Extends Agent with document search.

**Dependencies**: `src/Agent/AGENTS.md`, `src/Chat/AGENTS.md`, `src/Providers/AGENTS.md`

## Architecture

RAG extends Agent → inherits all Agent + Workflow capabilities (including
`resume()`, thread identity, and the ignition record — see `src/Agent/AGENTS.md`).

RAG overrides `entryNodes()`: the retrieval chain replaces the Agent's
`StartNode` as the entry chain, and RAG's inference event is born at its end,
in `InstructionsNode`:

```
AgentStartEvent → PreProcessNode → RetrievalNode → PostProcessNode → InstructionsNode → [RecallMemoryNode] → inference
```

1. Extract and pre-process the user question (query expansion, rewriting)
2. Retrieve relevant documents from the VectorStore. `QueryPreProcessedEvent`
   is the injection channel for retrieval filters: middleware (in `before()`
   on `RetrievalNode`) and preceding nodes call `addFilters(FilterExpression)`;
   the node combines each mandatory scope at the root with AND and forwards the
   resulting expression to the retrieval strategy. A scope may contain nested
   AND/OR logic, but it can never relax another scope.
   The event is born fresh every run, so a filter never leaks into the next run.
3. Post-process (re-rank, filter)
4. `InstructionsNode` births the inference event: document-enriched
   instructions + the run's intent, carried through the chain on each event's
   `$startEvent` (so a streamed or structured RAG run keeps its mode across
   the retrieval boundary — a custom node inserted into the chain must thread
   `$startEvent` through its own event the same way). The event then passes
   through the Agent's recall phase before its first inference.

## Core Files

| File | Purpose |
|------|---------|
| `RAG.php` | Main class, extends Agent |
| `Document.php` | Document container with content, metadata, embedding |
| `ResolveVectorStore.php` | Trait for vector store injection |
| `ResolveEmbeddingProvider.php` | Trait for embeddings provider |
| `ResolveRetrieval.php` | Trait for retrieval strategy |

## Usage with RAG Extension Pattern

Create a custom RAG class extending `RAG`:

```php
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class WorkoutTipsAgent extends RAG
{
    protected function provider(): AIProviderInterface
    {
        return new Anthropic(
            key: env('ANTHROPIC_API_KEY'),
            model: 'claude-sonnet-4-6',
        );
    }

    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new OpenAIEmbeddingsProvider(
            key: env('OPENAI_API_KEY'),
            model: 'text-embedding-3-small',
        );
    }

    protected function vectorStore(): VectorStoreInterface
    {
        return new FileVectorStore(
            storage: storage_path('app/embeddings'),
        );
    }
}

// Usage
$response = WorkoutTipsAgent::make()->chat(
    new UserMessage('What are the best exercises for back pain?')
);
```

## Vector Stores (`VectorStore/`)

`VectorStoreInterface` is filter-aware and stateless per call:

```php
$store->search(new SearchRequest(embedding: $vector, filters: $group, topK: 8));
$store->delete(Filter::eq('sourceType', 'file'));
```

`SearchRequest` is an immutable per-call value (embedding, `?FilterExpression`,
`?int topK` — null falls back to the store's constructor default). There is
no mutable search state on a store: a filter set for one call cannot leak
into the next.

### The filter model (`VectorStore/Filter/`)

A portable, backend-neutral expression tree compiled to each store's native
syntax. This is **filtered similarity search**: metadata filters constrain
vector similarity results. Reserve **hybrid search** for strategies that
combine vector and lexical ranking.

- `Filter::eq/neq/in/gt/gte/lt/lte(field, value)` — low-level comparison
  factories. `Filter::where(field, value)` starts an immutable fluent
  `Criteria` with `where*` methods for the common path.
  Values are scalars only (`null` throws: no portable missing-vs-null
  semantics); range values normalize to `int|float` (string ranges are not
  portable). Backed enums normalize to their value and `DateTimeInterface`
  values normalize to epoch timestamps.
- `FilterGroup::allOf(...)` / `anyOf(...)` — nested boolean expressions;
  `and(...)` / `or(...)` remain short aliases. Same-operator groups flatten,
  while mixed operators preserve their boundaries.
- `FilterScope::merge(...)` — combines independently supplied mandatory
  scopes with a root AND. Query expressiveness and scope safety are separate.
- `Filter::containsAny/containsAll(field, values)` — portable filtering for
  filterable `string[]` fields. Other array types remain backend-native.
- `Filter::raw(StoreClass::class, $fragment)` — backend-native escape hatch,
  tagged with its target store. The tagged store passes the fragment through
  verbatim; every other store's compiler throws (fail-loud on store swap,
  never silent misfiltering). Raw fragments must be trusted, developer-authored
  syntax; never interpolate request values into them.

Each backend has a compiler class in `VectorStore/Filter/Compilers/`
(`QdrantFilterCompiler`, `MeilisearchFilterCompiler`, ...;
`OpenSearchFilterCompiler` extends the Elasticsearch one). Compilers are
internal wiring — no shared interface, not injectable. `FileVectorStore` and
`MemoryVectorStore` share the PHP-side `FilterEvaluator` instead (raw filters
throw there — nothing can execute them).

Retrieval logs include the filter's fields, operators, and boolean structure,
but omit comparison values and raw fragments so authorization data does not
leak into the default log context.

Backend caveats: Meilisearch filterable attributes and new MongoDB Atlas
indexes are derived from `DocumentSchema`; existing indexes may require
recreation. Weaviate keeps an opaque metadata copy and projects declared
filter fields to native properties. Pinecone filter-based deletion works on
pod-based indexes only.

`VectorStoreInterface` implementations:

| Class | Backend |
|-------|---------|
| `PineconeVectorStore` | Pinecone |
| `ChromaVectorStore` | ChromaDB |
| `QdrantVectorStore` | Qdrant |
| `ElasticsearchVectorStore` | Elasticsearch |
| `OpenSearchVectorStore` | OpenSearch |
| `TypesenseVectorStore` | Typesense |
| `MeilisearchVectorStore` | Meilisearch |
| `MongoDBVectorStore` | MongoDB Atlas Vector Search |
| `MariaDBVectorStore` | MariaDB vectors |
| `WeaviateVectorStore` | Weaviate |
| `FileVectorStore` | Local file storage |
| `MemoryVectorStore` | In-memory (testing) |

```php
// Pinecone example
use NeuronAI\RAG\VectorStore\PineconeVectorStore;

protected function vectorStore(): VectorStoreInterface
{
    return new PineconeVectorStore(
        apiKey: env('PINECONE_API_KEY'),
        indexName: 'my-index',
        namespace: 'documents',
    );
}
```

### Document schemas

`Document` is the unified processing object across loading, splitting,
embedding, storage, retrieval, middleware, and reranking. Its fields are
accessed through methods. Embedding and score are nullable runtime values;
strict `null` checks express whether a stage produced them (`0.0` remains a
valid score).

Custom metadata stays schema-less for storage and round-tripping. Portable
filtering requires a collection-level schema passed to the vector store:

```php
$schema = DocumentSchema::of(
    DocumentField::string('tenant')->required()->filterable(),
    DocumentField::integer('year')->filterable(),
    DocumentField::strings('tags')->filterable(),
);

$store = new MemoryVectorStore(schema: $schema);
```

Stores validate declared values and filters locally. Only `sourceType`,
`sourceName`, and declared filterable metadata fields are portable filter
targets. Array fields are supported for validation/storage but need raw
backend filters except for portable filterable string arrays, which support
`containsAny` and `containsAll`. Declared arrays must be non-empty homogeneous
lists. A `DocumentField` can be passed directly to
filter factories for schema-aware construction. `neq` requires a required
field so missing-field behavior cannot diverge between databases. RAG
validates documents before embedding.

## Embeddings (`Embeddings/`)

`EmbeddingsProviderInterface`:

| Provider | Service |
|----------|---------|
| `OpenAIEmbeddingsProvider` | OpenAI text-embedding |
| `GeminiEmbeddingsProvider` | Google Gemini |
| `OllamaEmbeddingsProvider` | Local Ollama |
| `VoyageEmbeddingsProvider` | Voyage AI |
| `CohereEmbeddingsProvider` | Cohere |
| `MistralEmbeddingsProvider` | Mistral |
| `AwsBedrockEmbeddingsProvider` | AWS Bedrock |
| `OpenAILikeEmbeddings` | Any OpenAI-compatible endpoint |

```php
use NeuronAI\RAG\Embeddings\GeminiEmbeddingsProvider;

protected function embeddings(): EmbeddingsProviderInterface
{
    return new GeminiEmbeddingsProvider(
        key: env('GEMINI_API_KEY'),
        model: 'text-embedding-004',
    );
}
```

## Document Loading (`DataLoader/`)

Load and chunk documents:

```php
use NeuronAI\RAG\DataLoader\FileDataLoader;

$documents = FileDataLoader::for('/path/to/documents')
    ->withSplitter(new CustomSplitter())
    ->getDocuments();
```

### Readers

| Reader | Format |
|--------|--------|
| `PdfReader` | PDF files |
| `HtmlReader` | HTML documents |
| `TextFileReader` | Plain text |

## Retrieval Strategies (`Retrieval/`)

`RetrievalInterface::retrieve(Message $query, ?FilterExpression $filters = null)`.
The second parameter carries per-run filters injected via
`QueryPreProcessedEvent::addFilters()`; a strategy must AND them with its
own — never drop them. The common RAG path declares its mandatory scope with
the protected `retrievalScope()` hook (or `setRetrievalScope()` before
execution):

```php
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;

protected function retrievalScope(): ?FilterExpression
{
    return Filter::where('tenant', $this->tenantId)
        ->whereIn('status', ['published', 'reviewed']);
}
```

`SimilarityRetrieval` still accepts a `filters:` expression when used
directly or inside a custom retrieval composition.

## Pre/Post Processors

- `PreProcessor/` - Transform query before retrieval (query expansion, etc.)
- `PostProcessor/` - Re-rank or filter retrieved documents

## Graph Store (`GraphStore/`)

Knowledge graph integration (Neo4j) using triplet model (subject-relation-object).

## Splitter (`Splitter/`)

Document chunking strategies. Implement `SplitterInterface`:

```php
use NeuronAI\RAG\Splitter\SplitterInterface;
use NeuronAI\RAG\Document;

class CustomSplitter implements SplitterInterface
{
    public function splitDocument(Document $document): array
    {
        // Custom chunking logic
        return $chunks;
    }

    public function splitDocuments(array $documents): array
    {
        return array_map([$this, 'splitDocument'], $documents);
    }
}
```
