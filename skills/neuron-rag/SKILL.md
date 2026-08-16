---
name: neuron-rag
description: Implement RAG (Retrieval-Augmented Generation) with Neuron AI including vector stores, embeddings providers, document loaders, retrieval strategies, and metadata filtering. Use this skill whenever the user mentions RAG, retrieval, vector search, document retrieval, semantic search, knowledge bases, chat with documents, or wants to build AI systems that can query and understand external documents. Also trigger for tasks involving vector databases, embeddings, document chunking, document schemas and metadata validation (DocumentSchema), ingestion and reindexing, retrieval strategies, or filtering retrieval by metadata (tenant scoping, source scoping, date ranges).
---

# Neuron AI RAG

This skill helps you implement Retrieval-Augmented Generation (RAG) in Neuron AI. `RAG` extends `Agent` — it inherits everything an agent can do (chat, stream, structured output, tools, persistence, thread identity) and replaces the entry chain with retrieval:

```
AgentStartEvent → PreProcessNode → RetrievalNode → PostProcessNode → InstructionsNode → inference
```

1. Pre-process the user question (query rewriting/expansion)
2. Retrieve relevant documents from the vector store
3. Post-process (re-rank, filter by score)
4. Build document-enriched instructions and run the normal inference

## Core Components

1. **Embeddings provider** — converts text to vectors (`EmbeddingsProviderInterface`)
2. **Vector store** — stores and searches document embeddings (`VectorStoreInterface`)
3. **Retrieval strategy** — how a query becomes a document list (`RetrievalInterface`)
4. **Pre/post processors** — query transformation and result re-ranking/filtering

```php
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\PineconeVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class MyChatBot extends RAG
{
    protected function provider(): AIProviderInterface
    {
        return new Anthropic(
            key: $_ENV['ANTHROPIC_API_KEY'],
            model: 'claude-sonnet-4-6',
        );
    }

    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new OpenAIEmbeddingsProvider(
            key: $_ENV['OPENAI_API_KEY'],
            model: 'text-embedding-3-small',
        );
    }

    protected function vectorStore(): VectorStoreInterface
    {
        return new PineconeVectorStore(
            key: $_ENV['PINECONE_API_KEY'],
            indexUrl: $_ENV['PINECONE_INDEX_URL'],
        );
    }
}
```

Fluent alternatives exist for every hook: `setEmbeddingsProvider()`, `setVectorStore()`, `setRetrieval()`, `setPreProcessors()`, `setPostProcessors()`.

## Vector Stores

All implement `VectorStoreInterface`. The `topK` constructor parameter controls how many documents a search returns.

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
use NeuronAI\RAG\VectorStore\PineconeVectorStore;

new PineconeVectorStore(
    key: $_ENV['PINECONE_API_KEY'],
    indexUrl: $_ENV['PINECONE_INDEX_URL'],
    topK: 4,
    namespace: '__default__',
);

use NeuronAI\RAG\VectorStore\ChromaVectorStore;

new ChromaVectorStore(
    collection: 'my_collection',
    host: 'http://localhost:8000',
    topK: 5,
);

use NeuronAI\RAG\VectorStore\QdrantVectorStore;

new QdrantVectorStore(
    collectionUrl: 'http://localhost:6333/collections/neuron-ai/',
    key: $_ENV['QDRANT_API_KEY'],
    topK: 5,
);

use NeuronAI\RAG\VectorStore\FileVectorStore;

new FileVectorStore(
    directory: storage_path('embeddings'),
    topK: 4,
);
```

## Embeddings Providers

All implement `EmbeddingsProviderInterface`: `OpenAIEmbeddingsProvider`, `GeminiEmbeddingsProvider`, `OllamaEmbeddingsProvider`, `VoyageEmbeddingsProvider`, `CohereEmbeddingsProvider`, `MistralEmbeddingsProvider`, `AwsBedrockEmbeddingsProvider`, `OpenAILikeEmbeddings`.

```php
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;

new OpenAIEmbeddingsProvider(
    key: $_ENV['OPENAI_API_KEY'],
    model: 'text-embedding-3-small',   // or 'text-embedding-3-large'
);

use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;

new OllamaEmbeddingsProvider(
    model: 'nomic-embed-text',
    url: 'http://localhost:11434/api',
);

use NeuronAI\RAG\Embeddings\GeminiEmbeddingsProvider;

new GeminiEmbeddingsProvider(
    key: $_ENV['GEMINI_API_KEY'],
    model: 'text-embedding-004',
);

use NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider;

new VoyageEmbeddingsProvider(
    key: $_ENV['VOYAGE_API_KEY'],
    model: 'voyage-3-lite',
);
```

**The embedding model is part of your index**: documents and queries must be embedded with the same model, so changing providers means re-ingesting.

## Document Loading and Splitting

`FileDataLoader` handles a single file or a whole directory (recursive) and fills each document's built-in `sourceType`/`sourceName` fields. Plain text is the default; register readers for other formats with `addReader()` (one extension or an array). `StringDataLoader` wraps raw text. Loaders split while loading — configure the splitter with `withSplitter()`:

```php
use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\DataLoader\HtmlReader;
use NeuronAI\RAG\DataLoader\PdfReader;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;

// A directory (recursive) or a single file:
$documents = FileDataLoader::for('/path/to/documents')
    ->addReader('pdf', new PdfReader())
    ->addReader(['html', 'htm'], new HtmlReader())
    ->withSplitter(new DelimiterTextSplitter(maxLength: 1000, separator: ' ', wordOverlap: 50))
    ->getDocuments();

// Raw text:
use NeuronAI\RAG\DataLoader\StringDataLoader;

$documents = StringDataLoader::for($text)->getDocuments();

// Register a custom reader for an extension:
$loader = FileDataLoader::for('/docs')->addReader('md', new MyMarkdownReader());
```

### Splitters (`Splitter/`)

- `DelimiterTextSplitter(maxLength: 1000, separator: ' ', wordOverlap: 0, minLength: 0)` — character-budget chunks with word overlap
- `SentenceTextSplitter(maxWords: 200, overlapWords: 0, minWords: 0)` — sentence-aware chunks
- Custom: implement `SplitterInterface` (`splitDocument(Document): array`, `splitDocuments(array): array`)

**Chunking guidance**: smaller chunks retrieve more precisely but carry less context; 10–20% overlap preserves continuity across boundaries.

### Document metadata

Add schema-required and filterable metadata after loading, before ingestion. Values must match the declared type (an integer field requires `2026`, not `'2026'`). Undeclared metadata is also allowed — any JSON-safe value (strings, numbers, booleans, null, arrays of those) is stored and returned, but cannot be used in a portable filter:

```php
foreach ($documents as $document) {
    $document
        ->addMetadata('tenant', 'acme')
        ->addMetadata('status', 'published')
        ->addMetadata('published_at', 1767225600)
        ->addMetadata('ui_hint', ['color' => 'blue']);   // undeclared, stored as-is
}
```

Create a `Document` directly when a loader is unnecessary — setting the source makes later replacement/deletion predictable. Built-in splitters copy source and metadata to every chunk, so metadata can be set before splitting:

```php
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\SentenceTextSplitter;

$document = (new Document($content))
    ->setSourceType('cms')
    ->setSourceName('article-42')
    ->setMetadata(['tenant' => 'acme', 'status' => 'published']);

$documents = (new SentenceTextSplitter(maxWords: 200, overlapWords: 20))->splitDocument($document);
```

The same `Document` type flows through loading and retrieval: `getContent()`, `getSourceType()`, `getSourceName()`, `getMetadata()`, plus nullable `getEmbedding()` (null before embedding) and `getScore()` (null before retrieval; `0.0` is a valid score — use strict null checks).

## Ingesting Documents

`RAG::addDocuments()` validates each document against the store schema, embeds, and stores in one call (batched, default 50 — `chunkSize` controls the embedding batch). Validation runs before the embedding request, so missing required metadata or a wrong type fails before consuming embedding tokens or writing partial data:

```php
$rag = MyChatBot::make();

$rag->addDocuments(
    FileDataLoader::for('/path/to/docs')->getDocuments()
);

$rag->addDocuments($documents, chunkSize: 100);   // when the embeddings provider needs a different batch
```

Repeated ingestion creates duplicate chunks. `reindexBySource()` groups the new documents by `sourceType`/`sourceName`, deletes the old documents for each source, then adds the new chunks (destructive for those sources):

```php
$rag->reindexBySource($documents);
```

## Retrieval Strategies

The built-in strategy is `SimilarityRetrieval` — embed the query, ask the vector store. It optionally takes a static filter scope applied to every search it performs:

```php
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;

protected function retrieval(): RetrievalInterface
{
    return new SimilarityRetrieval(
        $this->resolveVectorStore(),
        $this->resolveEmbeddingsProvider(),
        filters: FilterGroup::and(Filter::eq('tenant', $this->tenantId)),
    );
}
```

Custom strategies implement `RetrievalInterface` — the query arrives as a `Message`, the return is `Document[]`. The second parameter carries filters injected for this run (by middleware or preceding nodes): a strategy must honor them by AND-ing them with its own — they can narrow the search, never be dropped:

```php
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;

class CustomRetrieval implements RetrievalInterface
{
    public function retrieve(Message $query, ?FilterGroup $filters = null): array
    {
        // e.g. combine vector search with a keyword index — but always
        // apply $filters when present. FilterGroup::merge($own, $filters)
        // is the null-tolerant AND for combining them with your own scope.
        return $documents;
    }
}

$rag->setRetrieval(new CustomRetrieval());
```

## Filtering

Retrieval can be constrained by document metadata with a portable filter model that compiles to each backend's native syntax — the same `FilterGroup` works on Pinecone, Qdrant, Meilisearch, and every other store.

### Document schema (`DocumentSchema`)

A vector database needs to know a metadata field's type before it can index and compare it. A `DocumentSchema` gives every backend the same understanding of your documents; it belongs to the vector store (every built-in store accepts the optional `schema:` constructor argument) because every document in one index must follow the same contract:

```php
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;

$schema = DocumentSchema::of(
    DocumentField::string('tenant')->required()->filterable(),
    DocumentField::string('status')->required()->filterable(),
    DocumentField::integer('published_at')->filterable(),
    DocumentField::float('price')->filterable(),
    DocumentField::boolean('published')->filterable(),
    DocumentField::strings('tags'),
);

$store = new MemoryVectorStore(schema: $schema);
```

Field constructors: `string`, `integer`, `float`, `boolean`, and homogeneous arrays `strings`, `integers`, `floats`, `booleans`. Integers are valid values for float fields. `required()` means every document must carry a non-null value; `filterable()` means the field can appear in a portable filter.

Declare only what the database needs — a field that must exist on every document, needs a known type, or appears in portable filters. Undeclared JSON-safe metadata still round-trips (stored and returned), but cannot be filtered portably because its database type is unknown. Arrays are validated and stored but require raw backend filters. `neq` is available only for required fields, preventing absent fields from widening a scope on databases with different missing-field behavior.

`sourceType` and `sourceName` are built-in string fields — always filterable, and must **not** be declared in the schema.

Create the schema before creating a new database index or collection; if an existing index has incompatible field mappings, recreate it and reindex.

### The filter vocabulary

```php
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;

FilterGroup::and(                                // conjunction: every condition must match
    Filter::eq('tenant', 'acme'),
    Filter::neq('status', 'draft'),
    Filter::in('sourceType', ['pdf', 'html']),
    Filter::gte('published_at', 1767225600),     // ranges are numeric-only
    Filter::lt('price', 100),
);
```

Rules the filter and store schema validate before database I/O:

- **Scalars only** — `null` has no portable missing-vs-null semantics across backends and throws.
- **Ranges are `int|float` only** — not every backend can range-compare strings; index dates as epoch timestamps.
- **Custom fields are declared and filterable** — values and operators must match the schema type.
- **`neq` fields are required** — missing records can never widen the result on a different database.
- **Groups are AND-only** — `in()` covers OR-over-one-field, which is most real OR usage.
- **Groups flatten** — `FilterGroup::and($scope, $userFilters)` merges by appending, so composed filters can only narrow, never widen.

### The raw escape hatch

Backend capabilities outside the vocabulary go through `Filter::raw()`, tagged with the store class the fragment is written for. The tagged store passes it through verbatim; every other store throws — a store swap fails loudly instead of silently matching wrong documents:

```php
FilterGroup::and(
    Filter::eq('tenant', 'acme'),
    Filter::raw(MeilisearchVectorStore::class, "_geoRadius(45.4, 9.1, 2000)"),
);
```

### Static filters vs per-run filters

Two delivery paths, always combined by AND (an injected filter can never relax a configured scope):

1. **Static scope** — the `filters:` constructor argument on `SimilarityRetrieval` (shown above). Since agents are typically built per request, capture per-request context (tenant, user) there.
2. **Per-run injection** — filters ride the `QueryPreProcessedEvent` on its way to `RetrievalNode`. Workflow middleware adds constraints in `before()`; the event is born fresh every run, so an injected filter can never leak into the next run:

```php
use NeuronAI\RAG\Events\QueryPreProcessedEvent;
use NeuronAI\RAG\Nodes\RetrievalNode;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;

class TenantScope implements WorkflowMiddleware
{
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if ($event instanceof QueryPreProcessedEvent) {
            $event->addFilters(FilterGroup::and(
                Filter::eq('tenant', $state->get('tenant')),
            ));
        }
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
    }
}

$rag->addMiddleware(RetrievalNode::class, new TenantScope());
```

### Searching and deleting on the store directly

`VectorStoreInterface` itself is filter-aware — searches take an immutable per-call `SearchRequest` (nothing outlives the call), and deletion is filter-based:

```php
use NeuronAI\RAG\VectorStore\SearchRequest;

$documents = $store->search(new SearchRequest(
    embedding: $embedding,
    filters: FilterGroup::and(Filter::eq('sourceType', 'pdf')),
    topK: 8,                       // per-call override; null = store default
));

$store->delete(FilterGroup::and(
    Filter::eq('sourceType', 'pdf'),
    Filter::eq('sourceName', 'manual.pdf'),
));
```

### Backend caveats

- **Meilisearch**: schema filter fields are registered as filterable index attributes automatically.
- **MongoDB Atlas**: `setupVectorIndex()` includes schema filter fields; an existing Atlas index may need recreation.
- **Weaviate**: complete metadata is retained as JSON while declared filter fields are projected to native typed properties.
- **Pinecone**: filter-based deletion works on pod-based indexes only.

## Pre and Post Processors

### Pre-processors — transform the query before retrieval

`QueryTransformationPreProcessor` uses an AI provider to rewrite the query; the transformation type is an enum:

```php
use NeuronAI\RAG\PreProcessor\QueryTransformationPreProcessor;
use NeuronAI\RAG\PreProcessor\QueryTransformationType;

protected function preProcessors(): array
{
    return [
        new QueryTransformationPreProcessor(
            provider: $this->resolveProvider(),
            transformation: QueryTransformationType::REWRITING,  // or DECOMPOSITION, HYDE
        ),
    ];
}
```

### Post-processors — re-rank or filter the retrieved set

```php
use NeuronAI\RAG\PostProcessor\CohereRerankerPostProcessor;
use NeuronAI\RAG\PostProcessor\JinaRerankerPostProcessor;
use NeuronAI\RAG\PostProcessor\FixedThresholdPostProcessor;
use NeuronAI\RAG\PostProcessor\AdaptiveThresholdPostProcessor;

protected function postProcessors(): array
{
    return [
        new CohereRerankerPostProcessor(
            key: $_ENV['COHERE_API_KEY'],
            model: 'rerank-v3.5',
            topN: 3,
        ),
        // or: new JinaRerankerPostProcessor(key: ..., topN: 3)
        // or: new LocalAIRerankerPostProcessor(...)
        new FixedThresholdPostProcessor(threshold: 0.5),        // drop low-score documents
        // or: new AdaptiveThresholdPostProcessor(multiplier: 0.6)  // statistics-based cutoff
    ];
}
```

Fluent equivalents: `setPreProcessors([...])`, `setPostProcessors([...])`.

## Using the RAG

A `RAG` is an `Agent` — all verbs work, in every mode:

```php
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;

$rag = MyChatBot::make(threadId: $threadId);   // thread identity: same model as Agent

// Chat (eager → AgentState)
echo $rag->chat(new UserMessage('What are the main features?'))->getMessage()->getContent();

// Streaming (Generator; getReturn() is the final AgentState)
foreach ($rag->stream(new UserMessage('Explain the architecture')) as $chunk) {
    if ($chunk instanceof TextChunk) {
        echo $chunk->content;
    }
}

// Structured output
$summary = $rag->structured(new UserMessage('Summarize the pricing'), PricingSummary::class);
```

## CLI Generation

```bash
vendor/bin/neuron make:rag MyKnowledgeBot
```

## Testing RAG

Use the in-memory store and the testing fakes — no external services:

```php
use NeuronAI\RAG\Document;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;

$rag = MyChatBot::make()
    ->setEmbeddingsProvider(new FakeEmbeddingsProvider())
    ->setVectorStore(new FakeVectorStore([new Document('The product costs $99.')]));

$response = $rag->chat(new UserMessage('How much does it cost?'))->getMessage();
```
