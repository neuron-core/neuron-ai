# RAG Module

Retrieval Augmented Generation. Extends Agent with document search.

**Dependencies**: `src/Agent/AGENTS.md`, `src/Chat/AGENTS.md`, `src/Providers/AGENTS.md`

## Architecture

RAG extends Agent → inherits all Agent + Workflow capabilities.

Before inference:
1. Extract user question
2. Retrieve relevant documents from VectorStore
3. Inject documents into instructions
4. Call parent Agent with enriched context

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
use NeuronAI\RAG\Embeddings\OpenAIEmbeddings;
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
        return new OpenAIEmbeddings(
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

## Embeddings (`Embeddings/`)

`EmbeddingsProviderInterface`:

| Provider | Service |
|----------|---------|
| `OpenAIEmbeddings` | OpenAI text-embedding |
| `GeminiEmbeddings` | Google Gemini |
| `OllamaEmbeddings` | Local Ollama |
| `VoyageEmbeddings` | Voyage AI |

```php
use NeuronAI\RAG\Embeddings\GeminiEmbeddings;

protected function embeddings(): EmbeddingsProviderInterface
{
    return new GeminiEmbeddings(
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

```php
use NeuronAI\RAG\RAG\Retrieval\SimilarityRetrieval;

protected function retrieval(): RetrievalInterface
{
    return new SimilarityRetrieval(
        vectorStore: $this->resolveVectorStore(),
        embeddingsProvider: $this->resolveEmbeddingsProvider()
    );
}
```

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
