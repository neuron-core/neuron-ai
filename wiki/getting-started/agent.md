---
description: Easily implement LLM interactions extending the basic Agent class.
---

# Agent

You can create your agent by extending the `NeuronAI\Agent` class to inherit the main features of the framework and create fully functional agents. This class automatically manages some advanced mechanisms for you such as memory, tools and function calls, up to the RAG systems. We will go into more detail about these aspects in the following sections.

This implementation strategy ensures the portability of your agent because all the moving parts are encapsulated into a single entity that you can run wherever you want in your application, or even release as stand alone composer packages.

Let's start creating an AI Agent summarizing YouTube videos. We start creating the `YouTubeAgent` class extending `NeuronAI\Agent`:

```php
<?php

namespace App\Neuron;

use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;

class YouTubeAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        ...
    }
}
```

### Inspector

Many of the applications you build with Neuron will contain multiple steps with multiple invocations of LLM calls. As these applications get more and more complex, it becomes crucial to be able to inspect what exactly is going on inside your agentic system. The best way to do this is with [Inspector](https://inspector.dev/).

After you sign up at the link above, make sure to set the `INSPECTOR_INGESTION_KEY` variable in the application environment file to start monitoring:

{% code title=".env" %}
```
INSPECTOR_INGESTION_KEY=nwse877auxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```
{% endcode %}

### AI Provider

The minimum implementation requires assigning an AI Provider that will be the language and reasoning engine of your agent.

The only required method to implement is `provider()`  returning the instance of the provider you want to use. Let's assume it's Anthropic.

```php
<?php

namespace App\Neuron;

use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;

class YouTubeAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        // return an AI provider (Anthropic, OpenAI, Gemini, Ollama, etc.)
        return new Anthropic(
            key: 'ANTHROPIC_API_KEY',
            model: 'ANTHROPIC_MODEL',
        );
    }
}
```

You can also use other providers like OpenAI, Gemini, or Ollama if you want to run the model locally. Check out the [supported providers](../components/ai-provider.md).

### System instructions

The second important building block is the system instructions. System instructions provide directions for making the AI ​​act according to the task we want to achieve. They are fixed instructions that will be sent to the LLM on every interaction.

That’s why they are defined by an internal method, and stay encapsulated into the agent entity. Let's implement the `instructions()` method:

```php
<?php

namespace App\Neuron;

use NeuronAI\Agent;
use NeuronAI\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;

class YouTubeAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        // return an AI provider instance (Anthropic, OpenAI, Ollama, Gemini, etc.)
        return new Anthropic(
            key: 'ANTHROPIC_API_KEY',
            model: 'ANTHROPIC_MODEL',
        );
    }
    
    public function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ["You are an AI Agent specialized in writing YouTube video summaries."],
            steps: [
                "Get the url of a YouTube video, or ask the user to provide one.",
                "Use the tools you have available to retrieve the transcription of the video.",
                "Write the summary.",
            ],
            output: [
                "Write a summary in a paragraph without using lists. Use just fluent text.",
                "After the summary add a list of three sentences as the three most important take away from the video.",
            ]
        );
    }
}
```

The `SystemPrompt` class is designed to take your base instructions and build a consistent prompt for the underlying model reducing the effort for prompt engineering. The properties has the following meaning:

* **background**: Write about the role of the Agent. Think about the macro tasks it's intended to accomplish.
* **steps**: Define the way you expect the Agent to behave. Multiple steps help the Agent to act consistently.
* **output**: Define how you want the agent to respond. Be explicit on the format you expect.

We highly recommend to use the `SystemPrompt` class to increase the quality of the results, in alternative you can just return a simple string:

```php
<?php

namespace App\Neuron;

use NeuronAI\Agent;

class YouTubeAgent extend Agent
{
    ...
    
    public function instructions(): string
    {
        return "You are an AI Agent specialized in writing YouTube video summaries.";
    }
}
```

### Talk to the YouTubeAgent

We are ready to test how the agent responds to our message based on the new instructions.

```php
use NeuronAI\Chat\Messages\UserMessage;

$response = YouTubeAgent::make()->chat(
    new UserMessage("Who are you?")
);
    
echo $response->getContent();
// Hi, I'm a frindly AI agent specialized in summarizing YouTube videos!
// Can you give me the URL of a YouTube video you want a quick summary of?
```

### Message

The agent always accepts input as a `Message` class, and returns Message instances.

As you saw in the example above we sent a `UserMessage` instance to the agent and it responded with an `AssistantMessage` instance. A list of assistant messages and user messages creates a chat.

We will learn more about [ChatHistory](../components/chat-history-and-memory.md) later, but it's important to know that the unified interface for the agent input and response is the Message object.

## Fluent Agent Definition

In alternative to the single class encapsulation you can also instruct the agent inline using the fluent chain of methods:

```php
$agent = Agent::make()
    ->withAiProvider(
        new Anthropic(
            key: 'ANTHROPIC_API_KEY',
            model: 'ANTHROPIC_MODEL',
        )
    )
    ->withInstructions(
        (string) new SystemPrompt(...)
    )
    ->addTool([...]);
    
$response = $agent->chat(new UserMessage(...));
```
