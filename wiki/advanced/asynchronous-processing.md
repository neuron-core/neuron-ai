---
description: Execute multiple parallel processes using NeuronAI async interface.
---

# Asynchronous Processing

NeuronAI supports asynchronous execution and parallel processing of agent requests, enabling you to efficiently handle multiple operations simultaneously. This is particularly valuable for batch processing, data classification pipelines, and high-throughput applications.

### Why Use Async Processing?

Asynchronous processing addresses several common challenges in AI-powered applications:

**Performance Optimization**: Instead of waiting for each request to complete sequentially, you can process multiple inputs simultaneously, dramatically reducing total execution time.

**Cost Efficiency**: When working with token-based pricing models, parallel processing allows you to maximize throughput within rate limits and optimize your API usage costs.

**Scalability**: Applications handling large volumes of data (product classification, content moderation, data labeling) benefit significantly from concurrent processing capabilities.

**User Experience**: In web applications, async processing prevents blocking operations that could impact response times and user experience.

**Provider Independence**: Unlike batch processing features that are provider-specific (such as OpenAI's Batch API), async processing is implemented at the framework level, making it available for all providers out of the box without relying on individual provider capabilities or implementations.

### Framework-Level vs Provider-Level Solutions

NeuronAI's async processing approach offers several advantages over provider-specific batch APIs:

**Universal Compatibility**: Async processing works with any provider supported by NeuronAI, regardless of whether they offer native batch processing capabilities.

**Consistent Interface**: You use the same async methods and patterns across all providers, eliminating the need to learn different batch implementations for each service.

**Future-Proof**: As new providers are added to NeuronAI, they automatically inherit async processing capabilities without requiring additional implementation work.

**Fallback Support**: Even if a provider discontinues or changes their batch API, your async implementation continues to work unchanged.

### Basic Async Implementation

To execute multiple agent requests in parallel, create separate agent instances for each operation and schedule the async execution using `chatAsync` method instead of the normal `chat` method. This prevents state conflicts and ensures clean execution:

```php
use GuzzleHttp\Promise\Utils;
use NeuronAI\Chat\Messages\UserMessage;

// Create separate agent instances
$agent1 = ClassificationAgent::make();
$agent2 = ClassificationAgent::make();
$agent3 = ClassificationAgent::make();

// Execute multiple parallel requests
$results = Utils::unwrap([
    'product_a' => $agent1->chatAsync(new UserMessage("Classify: Red cotton shirt, size M")),
    'product_b' => $agent2->chatAsync(new UserMessage("Classify: wireless headphones, Bluetooth 5.3")),
    'product_c' => $agent3->chatAsync(new UserMessage("Classify: laptop, Intel i7, 16GB RAM"))
]);

// Access results
echo $results['product_a']->getContent();
echo $results['product_b']->getContent();
echo $results['product_c']->getContent();
```

{% hint style="warning" %}
**Instance Isolation**: Always use separate agent instances for parallel requests. Reusing the same instance can cause state conflicts and unpredictable behavior.
{% endhint %}

### Queue-Worker Processing

For applications using message queues (RabbitMQ, Redis, SQS, etc.), async processing integrates seamlessly with worker patterns. The example below is like a pseudo-code representing a background Job to process the classification of multiple products in parallel.&#x20;

You will implement your queue-worker pattern using the services provided by your framework. This is just a guideline on you can encapsulate this process:

```php
class ProductClassificationWorker
{
    public function handle(ClassificationJob $job, Inspector $inspector): void
    {
        $agents = [];
        $promises = [];
        
        // Prepare async requests
        foreach ($job->products as $id => $product) {
            $agents[$id] = ClassificationAgent::make();
            $promises[$id] = $agents[$id]->chatAsync(
                new UserMessage($product->getDescription())
            );
        }
        
        // Wait for all responses
        $results = Utils::unwrap($promises);
        
        // Process results
        foreach ($results as $id => $response) {
            // Save $response->getContent() for product ID $id
        }
    }
}
```

### Error Handling in Async Operations

When working with multiple concurrent requests, implement robust error handling to manage partial failures:

```php
use GuzzleHttp\Promise\Utils;
use NeuronAI\Exceptions\AgentException;

try {
    
    $responses = Utils::unwrap($promises);
    
} catch (AgentException $exception) {
    // Handle specific agent errors
}
```

### Performance Considerations

Asynchronous processing in NeuronAI enables you to build scalable, efficient AI-powered applications that can handle high-volume workloads while maintaining optimal performance and resource utilization.

**Memory Usage**: Each agent instance consumes memory. For very large batches, consider processing in smaller chunks to manage memory consumption.

**Rate Limits**: Be mindful of API rate limits when processing large volumes. Implement appropriate delays or throttling if needed.
