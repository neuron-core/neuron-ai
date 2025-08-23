---
description: Managing errors fired by your agent.
---

# Error Handling

All exceptions fired from Neuron AI  are an extension of `NeuronException` . There several type of exception that can help you understand unexpected errors, but since they inherit from the same root exception gives you the ability to precisely catch agent errors in the context of your code:

```php
try {

    // Your code here...

} catch (NeuronAI\Exceptions\NeuronException $e) {
    // catch all the exception generated just from the agent
} catch (NeuronAI\Exceptions\ProviderException $e) {
    // Fired from AI providers and embedding providers
}
```

If you want to be alerted on any error, consider to connect Inspector to your Agent instance. Learn more at the [**Observability**](observability.md) section.
