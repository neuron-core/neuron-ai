---
description: >-
  NeuronAI provides you with several ready to use interfaces against several
  vector databases.
---

# Vector Store

We currently offer first-party support for the following vector store:

### Memory Vector Store

This is an implementation of a volatile vector store that keeps your embeddings into the machine memory for the current session. It's useful when you don't need to store the generated embeddings for long term use, but just during current interaction sessions (or for local use).

```php
namespace App\Neuron;

use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class MyChatBot extends RAG
{
    ...
    
    protected function vectorStore(): VectorStoreInterface
    {
        return new MemoryVectorStore();
    }
}
```

### File Vector Store

File storage could be useful for low volume use case or local and staging environments. Embedded documents will be stored in the file system and processed during similarity search.

`FileVectorStore` uses PHP generators to read the embedded documents from the file systems. It will never keep more than `topK` items in memory while iterating very fast. You can store thousands of documents in your local filesystem only taking care on the maximum time you can accept to perform the similarity search.

You can also use this component to release agents with some knowledge already incorporated in a file.

```php
namespace App\Neuron;

use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class MyChatBot extends RAG
{
    ...
    
    protected function vectorStore(): VectorStoreInterface
    {
        return new FileVectorStore(
            directory: storage_path(),
            topK: 4
        );
    }
}
```

### Pinecone

Pinecone makes it easy to provide long-term memory for high-performance AI applications. It’s a managed, cloud-native vector database with a simple API and no infrastructure hassles. Pinecone serves fresh, filtered query results with low latency at the scale of billions of vectors.

Here is how to use Pinecone in your agent:

```php
namespace App\Neuron;

use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\PineconeVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class MyChatBot extends RAG
{
    ...
    
    protected function vectorStore(): VectorStoreInterface
    {
        return new PineconeVectorStore(
            key: 'PINECONE_API_KEY',
            indexUrl: 'PINECONE_INDEX_URL'
        );
    }
}
```

Pinecone also supports hybrid search that allows you to filter documents not only by similarity with the input prompt, but also by metadata stored along with your documents. You can pass additional filters to your agent instance so Pinecone will take them in consideration while filtering documents.

You can add the `addVectorStoreFilters()` method to your agent class to pass down filters at runtime:

```php
namespace App\Neuron;

use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\PineconeVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class MyChatBot extends RAG
{
    protected array $vectorStoreFilters = [];

    ...
    
    protected function vectorStore(): VectorStoreInterface
    {
        $store = new PineconeVectorStore(
            key: 'PINECONE_API_KEY',
            indexUrl: 'PINECONE_INDEX_URL'
        );
        
        return $store->withFilters($this->vectorStoreFilters);
    }
    
    public function addVectorStoreFilters(array $filters): self
    {
        $this->vectorStoreFilters = $filters;
        return $this;
    }
}
```

When you run your agent you can pass filters on the fly:

```php
$response = MyRAG::make()
    ->addVectorStoreFilters([
        // Add filters
    ])
    ->answer(new UserMessage(...));
```

Take a look at the Pinecone official documentation to better understand the metadata filters: [https://docs.pinecone.io/reference/api/2025-04/data-plane/query#body-filter](https://docs.pinecone.io/reference/api/2025-04/data-plane/query#body-filter)

### Elasticsearch

Elasticsearch's open source vector database offers an efficient way to create, store, and search vector embeddings. To use Elasticseach as a vector store in your agents implementation you have to import the official client:

```bash
composer require elasticsearch/elasticsearch
```

Here is how to create a RAG that uses Elasticsearch:

```php
namespace App\Neuron;

use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class MyChatBot extends RAG
{
    public function __construct(protected Client $elasticClient) {}

    ...
    
    protected function vectorStore(): VectorStoreInterface
    {
        return new ElasticsearchVectorStore(
            client: $this->elasticClient,
            index: 'neuron-ai'
        );
    }
}
```

Passing the elasticsearch client instance to the agent:

```php
// The Inspector instance in your application - https://inspector.dev/
$inspector = new \Inspector\Inspector(
    new \Inspector\Configuration('INSPECTOR_INGESTION_KEY')
);

$elasticClient = ClientBuilder::create()
   ->setHosts(['<elasticsearch-endpoint>'])
   ->setApiKey('<api-key>')
   ->build();
   
$response = MyChatBot::make($elasticClient)
    ->observe(new AgentMonitoring($inspector))
    ->chat(new UserMessage('Hello!'));

echo $response->getContent();
```

Elasticsearch also support hybrid search. You can pass additional filters to your agent instance so Elasticsearch will take them in consideration while filtering documents.

You can add the `addVectorStoreFilters()` method to your agent class to pass down filters at runtime:

```php
namespace App\Neuron;

use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class MyChatBot extends RAG
{
    protected array $vectorStoreFilters = [];
    
    public function __construct(protected Client $elasticClient) {}

    ...
    
    protected function vectorStore(): VectorStoreInterface
    {
        $store = new ElasticsearchVectorStore(
            client: $this->elasticClient,
            index: 'neuron-ai'
        );
    
        return $store->withFilter($this->vectorStoreFilters);
    }
    
    public function addVectorStoreFilters(array $filters): self
    {
        $this->vectorStoreFilters = $filters;
        return $this;
    }
}
```

When you run your agent you can pass filters on the fly:

```php
$response = MyRAG::make()
    ->addVectorStoreFilters([
        // Add filters
    ])
    ->answer(new UserMessage(...));
```

### Typesense

[Typesense](https://typesense.org/) is an open source alternative to the options above. To use Typesense in your agents you need to install its official client:

```bash
composer require typesense/typesense-php
```

Once you have the official client installed in your app you can return an instance of the TypesenseVectorStore in your RAG agent:

```php
namespace App\Neuron;

use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\TypesenseVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class MyChatBot extends RAG
{
    public function __construct(protected Client $typesenseClient) {}

    ...
    
    protected function vectorStore(): VectorStoreInterface
    {
        return new TypesenseVectorStore(
            client: $this->typesenseClient,
            collection: 'neuron-ai',
            vectorDimension: 1024
        );
    }
}
```

Passing the instance of the typesense client to the Agent:

```php
// The Inspector instance in your application - https://inspector.dev/
$inspector = new \Inspector\Inspector(
    new \Inspector\Configuration('INSPECTOR_INGESTION_KEY')
);

$typesenseClient = new Client([
    'api_key' => 'TYPESENSE_API_KEY',
    'nodes' => [
        [
            'host' => 'TYPESENSE_NODE_HOST',
            'port' => 'TYPESENSE_NODE_PORT',
            'protocol' => 'TYPESENSE_NODE_PROTOCOL'
        ],
    ]
]);

$response = MyChatBot::make($typesenseClient)
    ->observe(new AgentMonitoring($inspector))
    ->chat(new UserMessage('Hello!'));

echo $response->getContent();
```

### Qdrant

[Qdrant](https://qdrant.tech/) is an open source vector database with strong similarity search capabilities. To use Qdrant in your agents you have to provide a `collectionUrl`.  This means you will first need to create a collection on Qdrant with its attributes like: name, similarity search algorithm, vector dimension, etc.

Once you have the collection URL you can attach the `QdrantVectorStore` instance to your agent.

```php
namespace App\Neuron;

use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class MyChatBot extends RAG
{
    ...
    
    protected function vectorStore(): VectorStoreInterface
    {
        return new QdrantVectorStore(
            collectionUrl: 'http://localhost:6333/collections/neuron-ai/',
            key: 'QDRANT_API_KEY'
        );
    }
}
```

### ChromaDB

[Chroma](https://trychroma.com/) is an open source database designed to be an AI application data source. To use ChromaDB in your agents you have to provide the name of an internal collection where you want to store the embeddings.

Once you have the collection created on your Chroma instance you can attach the `ChromaVectorStore` instance to the agent:

```php
namespace App\Neuron;

use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\ChromaVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class MyChatBot extends RAG
{
    ...
    
    protected function vectorStore(): VectorStoreInterface
    {
        return new ChromaVectorStore(
            collection: 'neuron-ai',
            //host: 'http://localhost:8000', <-- This is by default
            topK: 5
        );
    }
}
```

### Meilisearch

[Meilisearch](https://www.meilisearch.com/) is a hybrid search engine, but the Neuron implementation uses it exclusively as a vector store for embeddings and similarity search.

Before attaching the `MeilisearchVectorStore` to your RAG agent you must create an index using the Meilisearch Admin Console and associate a custom embedder to the index configuring as Dimension the same value of the vector dimension generated by your [Embeddings Provider](embeddings-provider.md).

Once you have configured your index you can add the component to your RAG:

```php
namespace App\Neuron;

use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class MyChatBot extends RAG
{
    ...
    
    protected function vectorStore(): VectorStoreInterface
    {
        return new MeilisearchVectorStore(
            indexUid: 'MEILISEARCH_INDEXUID',
            host: 'http://localhost:8000', // Or use the cloud URL
            key: 'MEILISEARCH_API_KEY',
            embedder: 'default',
            topK: 5
        );
    }
}
```

### Implement custom Vector Stores

If you want to create a new provider you have to implement the `VectorStoreInterface` interface:

```php
namespace NeuronAI\RAG\VectorStore;

use NeuronAI\RAG\Document;

interface VectorStoreInterface
{
    public function addDocument(Document $document): void;

    /**
     * @param  Document[]  $documents
     */
    public function addDocuments(array $documents): void;
    
    public function deleteBySource(string $sourceName, string $sourceType): void;

    /**
     * Return docs most similar to the embedding.
     *
     * @param  float[]  $embedding
     * @return Document[]
     */
    public function similaritySearch(array $embedding, int $k = 4): iterable;
}
```

There are two different methods for adding a single document or a collection of documents because many databases provide different APIs for these use cases. If the database you want to interact to doesn't handle these requests differently you can implement `addDocument()` as a placeholder.

The similaritySearch should return documents with a similarity score not a similarity distance. If the underlying database returns a distance you can convert it to a score using the utility class `VectorSimilarity`:

```php
namespace App\Neuron\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\VectorSimilarity;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class MyVectorStore implements VectorStoreInterface
{
    ...
    
    
    /**
     * @param float[] $embeddings
     */
    public function similaritySearch(array $embedding): iterable
    {
        $documents = // get documents from the vector store
        
        return \array_map(function (Document $document) {
            return $document->setScore(
                VectorSimilarity::similarityFromDistance($similarity)
            );
        }, $documents);
    }
}
```

This is the basic template for a new AI provider implementation.

```php
namespace App\Neuron\VectorStore;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class MyVectorStore implements VectorStoreInterface
{
    protected Client $client;

    public function __construct(
        string $key,
        protected string $index,
        protected int $topK = 5
    ) {
        $this->client = new Client([
            'base_uri' => 'https://api.vector-store.com',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => "Bearer {$key}",
            ]
        ]);
    }

    public function addDocument(Document $document): void
    {
        $this->addDocuments([$document]);
    }

    /**
     * @param Document[] $documents
     */
    public function addDocuments(array $documents): void
    {
        $this->client->post("indexes/{$this->index}", [
            RequestOptions::JSON => \array_map(function (Document $document) {
                return [
                    'vector' => $document->embedding,
                ];
            }, $documents)
        ]);
    }

    /**
     * @return Document[]
     */
    public function similaritySearch(array $embedding): iterable
    {
        // perform similarity search and return an array of Document objects
    }
}
```

After creating your own implementation you can use it in the agent:

```php
namespace App\Neuron;

use App\Neuron\VectorStore\MyVectorStore;
use NeuronAI\Agent;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class MyAgent extends Agent
{
    protected function vectorStore(): VectorStoreInterface
    {
        return new MyVectorStore(
            key: 'VECTORSTORE_API_KEY',
            index: 'neuron-ai',
        );
    }
}
```

{% hint style="warning" %}
We strongly recommend you to submit new vector store implementations via PR on the official repository or using other [Inspector.dev](https://inspector.dev/developer-support/) support channels. The new implementation can receives an important boost in its advancement by the community.
{% endhint %}
