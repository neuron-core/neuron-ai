---
name: neuron-rag
description: Implement RAG (Retrieval-Augmented Generation) with Neuron AI including vector stores, embeddings providers, document loaders, and retrieval strategies. Use this skill whenever the user mentions RAG, retrieval, vector search, document retrieval, semantic search, knowledge bases, chat with documents, or wants to build AI systems that can query and understand external documents. Also trigger for tasks involving vector databases, embeddings, document chunking, or retrieval strategies.
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

`FileDataLoader` handles a single file or a whole directory; readers are selected by file extension (`PdfReader`, `HtmlReader`, `TextFileReader` built in). `StringDataLoader` wraps raw text. Loaders split while loading — configure the splitter with `withSplitter()`:

```php
use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;

// A directory (recursive, readers matched by extension) or a single file:
$documents = FileDataLoader::for('/path/to/documents')
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

## Ingesting Documents

`RAG::addDocuments()` embeds and stores in one call (batched — `chunkSize` controls the embedding batch):

```php
$rag = MyChatBot::make();

$rag->addDocuments(
    FileDataLoader::for('/path/to/docs')->getDocuments()
);
```

## Retrieval Strategies

The built-in strategy is `SimilarityRetrieval` — embed the query, ask the vector store:

```php
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;

protected function retrieval(): RetrievalInterface
{
    return new SimilarityRetrieval(
        $this->resolveVectorStore(),
        $this->resolveEmbeddingsProvider(),
    );
}
```

Custom strategies implement `RetrievalInterface` — the query arrives as a `Message`, the return is `Document[]`:

```php
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Retrieval\RetrievalInterface;

class CustomRetrieval implements RetrievalInterface
{
    public function retrieve(Message $query): array
    {
        // e.g. combine vector search with a keyword index, filter by metadata, ...
        return $documents;
    }
}

$rag->setRetrieval(new CustomRetrieval());
```

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
