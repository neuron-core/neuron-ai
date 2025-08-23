---
description: Integrate services to transform text into vectors for semantic search.
---

# Embeddings Provider

Transform your text into vector representations! Embeddings let you add Retrieval-Augmented Generation ([RAG](../advanced/rag.md)) into your AI applications.

## Available Embeddings Providers

The framework already includes the following embeddings provider.

### Ollama

With Ollama you can run embedding models locally. Documentation - [https://ollama.com/blog/embedding-models](https://ollama.com/blog/embedding-models)

```php
namespace App\Neuron;

use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\RAG;

class MyRAG extends RAG
{
    ...
    
    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new OllamaEmbeddingsProvider(
            model: 'OLLAMA_EMBEDDINGS_MODEL'
        );
    }
}
```

### Voyage AI

Documentation - [https://www.voyageai.com/](https://www.voyageai.com/)

```php
namespace App\Neuron;

use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider;
use NeuronAI\RAG\RAG;

class MyRAG extends RAG
{
    ...
    
    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new VoyageEmbeddingsProvider(
            key: 'VOYAGE_API_KEY',
            model: 'VOYAGE_EMBEDDINGS_MODEL' // voyage-3-large
        );
    }
}
```

### OpenAI

Documentation - [https://platform.openai.com/docs/guides/embeddings](https://platform.openai.com/docs/guides/embeddings)

```php
namespace App\Neuron;

use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\RAG;

class MyRAG extends RAG
{
    ...
    
    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new OpenAIEmbeddingsProvider(
            key: 'OPENAI_API_KEY',
            model: 'OPENAI_EMBEDDINGS_MODEL' // text-embedding-3-small
        );
    }
}
```

### Gemini

```php
namespace App\Neuron;

use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\GeminiEmbeddingsProvider;
use NeuronAI\RAG\RAG;

class MyRAG extends RAG
{
    ...
    
    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new GeminiEmbeddingsProvider(
            key: 'GEMINI_API_KEY',
            model: 'GEMINI_EMBEDDINGS_MODEL' // gemini-embedding-001
        );
    }
}
```

## Implement a new Provider

To create a custom provider you just have to extend the `AbstractEmbeddingsProvider` class. This class already implement the framework specific methods and let's you free to implement the only provider specific HTTP call into the `embedText()` method:

```php
namespace App\Neuron\Embeddings;

use GuzzleHttp\Client;

class CustomEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    protected Client $client;

    protected string $baseUri = 'HTTP-ENDPOINT';

    public function __construct(
        protected string $key,
        protected string $model
    ) {
        $this->client = new Client([
            'base_uri' => trim($this->baseUri, '/').'/',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->key,
            ]
        ]);
    }

    public function embedText(string $text): array
    {
        $response = $this->client->post('', [
            'json' => [
                'model' => $this->model,
                'input' => $text,
            ]
        ]);

        $response = \json_decode($response->getBody()->getContents(), true);

        return $response['data'][0]['embedding'];
    }
}
```

You should adjust the HTTP request based on the APIs of the custom provider.
