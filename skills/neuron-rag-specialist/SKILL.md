---
name: neuron-rag-specialist
description: Implement RAG (Retrieval-Augmented Generation) with Neuron AI including vector stores, embeddings providers, document loaders, and retrieval strategies. Use this skill whenever the user mentions RAG, retrieval, vector search, document retrieval, semantic search, knowledge bases, chat with documents, or wants to build AI systems that can query and understand external documents. Also trigger for tasks involving vector databases, embeddings, document chunking, or retrieval strategies.
---

# Neuron AI RAG Specialist

This skill helps you implement Retrieval-Augmented Generation (RAG) in Neuron AI. RAG extends the Agent class with document retrieval capabilities.

## Core RAG Architecture

RAG systems in Neuron AI consist of three main components:

1. **Vector Store** - Stores document embeddings for semantic search
2. **Embeddings Provider** - Converts text to vector embeddings
3. **Retrieval Strategy** - Determines how to search and rank documents

```php
use NeuronAI\RAG\RAG;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingProvider;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\RAG\VectorStore\PineconeVectorStore;

class MyChatBot extends RAG
{
    protected function provider(): AIProviderInterface
    {
        return new Anthropic(
            key: $_ENV['ANTHROPIC_API_KEY'],
            model: 'claude-3-5-sonnet-20241022',
        );
    }

    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new OpenAIEmbeddingProvider(
            key: $_ENV['OPENAI_API_KEY'],
            model: 'text-embedding-3-small',
        );
    }

    protected function vectorStore(): VectorStoreInterface
    {
        return new PineconeVectorStore(
            key: $_ENV['PINECONE_API_KEY'],
            indexUrl: $_ENV['PINECONE_INDEX_URL']
        );
    }
}
```

## Vector Stores

### Pinecone

```php
use NeuronAI\RAG\VectorStore\PineconeVectorStore;

new PineconeVectorStore(
    key: $_ENV['PINECONE_API_KEY'],
    indexUrl: $_ENV['PINECONE_INDEX_URL'],
    environment: 'us-east-1-aws'
);
```

### Chroma

```php
use NeuronAI\RAG\VectorStore\ChromaVectorStore;

new ChromaVectorStore(
    host: 'localhost',
    port: 8000,
    collection: 'my_collection'
);
```

### Qdrant

```php
use NeuronAI\RAG\VectorStore\QdrantVectorStore;

new QdrantVectorStore(
    apiKey: $_ENV['QDRANT_API_KEY'],
    url: $_ENV['QDRANT_URL'],
    collection: 'my_collection'
);
```

### Elasticsearch

```php
use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;

new ElasticsearchVectorStore(
    hosts: ['http://localhost:9200'],
    index: 'documents'
);
```

### Typesense

```php
use NeuronAI\RAG\VectorStore\TypesenseVectorStore;

new TypesenseVectorStore(
    apiKey: $_ENV['TYPESENSE_API_KEY'],
    nodes: [['host' => 'localhost', 'port' => 8108]],
    collection: 'documents'
);
```

### Other Vector Stores
- `MemoryVectorStore` - In-memory for testing
- `MilvusVectorStore` - Milvus database
- `RedisVectorStore` - Redis with RediSearch
- `WeaviateVectorStore` - Weaviate database
- `PgVectorStore` - PostgreSQL with pgvector extension

## Embeddings Providers

### OpenAI

```php
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingProvider;

new OpenAIEmbeddingProvider(
    key: $_ENV['OPENAI_API_KEY'],
    model: 'text-embedding-3-small'  // or 'text-embedding-3-large'
);
```

### Ollama

```php
use NeuronAI\RAG\Embeddings\OllamaEmbeddingProvider;

new OllamaEmbeddingProvider(
    baseUrl: 'http://localhost:11434',
    model: 'nomic-embed-text'
);
```

### Gemini

```php
use NeuronAI\RAG\Embeddings\GeminiEmbeddingProvider;

new GeminiEmbeddingProvider(
    key: $_ENV['GEMINI_API_KEY'],
    model: 'text-embedding-004'
);
```

### Voyage

```php
use NeuronAI\RAG\Embeddings\VoyageEmbeddingProvider;

new VoyageEmbeddingProvider(
    key: $_ENV['VOYAGE_API_KEY'],
    model: 'voyage-3-lite'
);
```

## Document Loading and Chunking

### Text Documents

```php
use NeuronAI\RAG\DocumentLoader\TextLoader;
use NeuronAI\RAG\Chunker\RecursiveCharacterTextSplitter;

$loader = new TextLoader('/path/to/document.txt');
$documents = $loader->load();

// Chunk documents
$chunker = new RecursiveCharacterTextSplitter(
    chunkSize: 1000,
    chunkOverlap: 200
);
$chunks = $chunker->chunk($documents);
```

### PDF Documents

```php
use NeuronAI\RAG\DocumentLoader\PDFLoader;

$loader = new PDFLoader('/path/to/document.pdf');
$documents = $loader->load();
```

### HTML Documents

```php
use NeuronAI\RAG\DocumentLoader\HtmlLoader;

$loader = new HtmlLoader('https://example.com/page');
$documents = $loader->load();
```

### Loading Multiple Files

```php
use NeuronAI\RAG\DocumentLoader\DirectoryLoader;

$loader = new DirectoryLoader('/path/to/documents');
$documents = $loader->load();
```

## Ingesting Documents

```php
$rag = MyChatBot::make();

// Load and chunk documents
$loader = new DirectoryLoader('./docs');
$documents = $loader->load();

$chunker = new RecursiveCharacterTextSplitter(
    chunkSize: 1000,
    chunkOverlap: 200
);
$chunks = $chunker->chunk($documents);

// Add to vector store
$rag->vectorStore()->addDocuments($chunks);
```

## Retrieval Strategies

### Basic Similarity Search

```php
use NeuronAI\RAG\Retrieval\SimilaritySearch;

$rag->setRetrieval(new SimilaritySearch(
    k: 5  // Return top 5 documents
));
```

### Hybrid Search (Keyword + Semantic)

```php
use NeuronAI\RAG\Retrieval\HybridSearch;

$rag->setRetrieval(new HybridSearch(
    k: 5,
    alpha: 0.5  // Balance between semantic (1.0) and keyword (0.0)
));
```

### Max Marginal Relevance (MMR)

```php
use NeuronAI\RAG\Retrieval\MMRSearch;

$rag->setRetrieval(new MMRSearch(
    k: 5,
    fetchK: 20,  // Fetch 20, return diverse 5
    lambdaMult: 0.5  // Diversity parameter
));
```

## Pre and Post Processors

### Pre-Processors (Query Transformation)

```php
use NeuronAI\RAG\Processor\QueryExpansionProcessor;

$rag->addPreProcessor(new QueryExpansionProcessor(
    numQueries: 3  // Generate 3 query variations
));

use NeuronAI\RAG\Processor\HydeProcessor;

$rag->addPreProcessor(new HydeProcessor(
    model: $rag->provider()  // Generate hypothetical document
));
```

### Post-Processors (Result Enhancement)

```php
use NeuronAI\RAG\Processor\RerankProcessor;
use NeuronAI\RAG\Processor\JinaReranker;

$rag->addPostProcessor(new RerankProcessor(
    reranker: new JinaReranker(
        apiKey: $_ENV['JINA_API_KEY']
    ),
    topK: 5
));

use NeuronAI\RAG\Processor\CompressorProcessor;

$rag->addPostProcessor(new CompressorProcessor(
    maxTokens: 2000
));
```

## Using the RAG

### Basic Query

```php
$rag = MyChatBot::make();

$response = $rag->chat(
    new UserMessage("What are the main features of our product?")
)->getMessage();

echo $response->getContent();
// Response includes retrieved documents as context
```

### Streaming

```php
foreach ($rag->stream(new UserMessage("Explain the architecture"))->events() as $event) {
    if ($event instanceof AIResponseChunkEvent) {
        echo $event->chunk;
    }
}
```

### Structured Output with RAG

```php
$summary = $rag->structured(
    new UserMessage("Summarize the pricing information"),
    PricingSummary::class
);
```

## Graph Stores (Knowledge Graphs)

For relationship-based retrieval:

```php
use NeuronAI\RAG\GraphStore\Neo4jGraphStore;

class KnowledgeRAG extends RAG
{
    protected function graphStore(): GraphStoreInterface
    {
        return new Neo4jGraphStore(
            uri: 'bolt://localhost:7687',
            username: 'neo4j',
            password: $_ENV['NEO4J_PASSWORD']
        );
    }
}
```

## CLI Generation

```bash
vendor/bin/neuron make:rag MyKnowledgeBot
```

## Advanced Configuration

### Custom Retrieval

```php
use NeuronAI\RAG\Retrieval\RetrievalInterface;

class CustomRetrieval implements RetrievalInterface
{
    public function retrieve(string $query, VectorStoreInterface $vectorStore): array
    {
        // Custom retrieval logic
        return $vectorStore->similaritySearch($query, k: 3);
    }
}

$rag->setRetrieval(new CustomRetrieval());
```

### Filtering Results

```php
$rag->chat(
    new UserMessage("Find documents about pricing")
)->withMetadataFilter([
    'category' => 'pricing',
    'year' => 2024
]);
```

### Configuring Top-K per Query

```php
$rag->chat(
    new UserMessage("Search query")
)->withRetrievalConfig([
    'k' => 10,  // Retrieve 10 documents
    'scoreThreshold' => 0.7  // Minimum similarity score
]);
```

## Common Patterns

### Company Knowledge Base

```php
class CompanyKnowledgeBot extends RAG
{
    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new OpenAIEmbeddingProvider(
            key: $_ENV['OPENAI_API_KEY'],
            model: 'text-embedding-3-small'
        );
    }

    protected function vectorStore(): VectorStoreInterface
    {
        return new PineconeVectorStore(
            key: $_ENV['PINECONE_API_KEY'],
            indexUrl: $_ENV['PINECONE_INDEX_URL']
        );
    }

    protected function retrieval(): RetrievalInterface
    {
        return new HybridSearch(k: 5, alpha: 0.7);
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                "You are a helpful assistant that answers questions",
                "about our company using the provided context.",
            ],
            constraints: [
                "Only use the provided context to answer.",
                "If the answer is not in the context, say you don't know.",
            ]
        );
    }
}
```

### Document Q&A with Reranking

```php
$rag = MyChatBot::make();

$rag->addPostProcessor(new RerankProcessor(
    reranker: new CohereReranker($_ENV['COHERE_API_KEY']),
    topK: 5
));

$rag->chat(new UserMessage("Your question here"));
```

### Multi-Source RAG

```php
class MultiSourceRAG extends RAG
{
    protected function vectorStore(): VectorStoreInterface
    {
        // Configure multiple sources with metadata
        return new PineconeVectorStore(...);
    }

    public function queryWithSources(string $query, array $sources): string
    {
        $response = $this->chat(
            new UserMessage($query)
        )->withMetadataFilter([
            'source' => ['$in' => $sources]
        ]);

        return $response->getMessage()->getContent();
    }
}
```

## Performance Considerations

### Chunk Size Selection
- **Smaller chunks** (500-1000 tokens): More precise retrieval, more documents to process
- **Larger chunks** (1500-2000 tokens): More context per document, less precise
- **Chunk overlap**: 10-20% helps maintain context across chunk boundaries

### Top-K Selection
- **3-5 documents**: Good for focused queries, faster responses
- **10+ documents**: Better for comprehensive answers, but slower and noisier

### Embedding Model Choice
- **Fast/Light**: `text-embedding-3-small`, `nomic-embed-text` - Good for most use cases
- **High Quality**: `text-embedding-3-large`, `voyage-3` - Better for complex queries

## Testing RAG

```php
use PHPUnit\Framework\TestCase;

class MyChatBotTest extends TestCase
{
    public function testRAGRetrieval(): void
    {
        $rag = MyChatBot::make();

        // Add test document
        $rag->vectorStore()->addDocument(
            new Document('test', 'The product costs $99.')
        );

        $response = $rag->chat(
            new UserMessage("How much does it cost?")
        )->getMessage();

        $this->assertStringContainsString('99', $response->getContent());
    }
}
```

## Debugging RAG

To see retrieved documents:

```php
$response = $rag->chat(new UserMessage("Your question"));
$retrievedDocs = $response->getRetrievedDocuments();

foreach ($retrievedDocs as $doc) {
    echo "Score: {$doc->score}\n";
    echo "Content: {$doc->content}\n\n";
}
```
