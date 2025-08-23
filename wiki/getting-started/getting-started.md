---
description: >-
  Step by step instructions on how to install NeuronAI in your application and
  create an Agent.
---

# Getting Started

### Requirements

* PHP: ^8.1

### Install

Run the composer command below to install the latest version:

```bash
composer require inspector-apm/neuron-ai
```

### Inspector

Many of the applications you build with Neuron will contain multiple steps with multiple invocations of LLM calls, tools, external memory system, etc. As these applications get more and more complex, it becomes crucial to be able to inspect what exactly is going on inside your agentic system. The best way to do this is with [Inspector](https://inspector.dev/).

After you sign up at the link above, make sure to set the `INSPECTOR_INGESTION_KEY` variable in the application environment file to start monitoring:

{% code title=".env" %}
```
INSPECTOR_INGESTION_KEY=nwse877auxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```
{% endcode %}

### Create an Agent

You can easily create your first agent extending the `NeuronAI\Agent` class:&#x20;

```php
namespace App\Neuron;

use NeuronAI\Agent;
use NeuronAI\Providers\Anthropic\Anthropic;

class MyAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        // return an AI provider (Anthropic, OpenAI, Ollama, Gemini, etc.)
        return new Anthropic(
            key: 'ANTHROPIC_API_KEY',
            model: 'ANTHROPIC_MODEL',
        );
    }
}
```

### Talk to the Agent

Send a prompt to the agent to get a response from the underlying LLM:

```php
use NeuronAI\Chat\Messages\UserMessage;

$response = MyAgent::make()->chat(
    new UserMessage("Hi, Who are you?")
);
    
echo $response->getContent();

// I'm a friendly AI Agent built with NeuronAI, how can I help you today?
```
