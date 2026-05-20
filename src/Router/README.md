# RouterProvider

The RouterProvider is a proxy that implements `AIProviderInterface` and routes inference calls (`chat`, `stream`, `structured`) to different underlying providers based on a routing strategy you define. The agent doesn't know it's talking to a router — it's a drop-in replacement for any provider.

## When to Use It

- Route `structured()` calls to a provider with better structured output support (e.g., OpenAI) while using another provider for `chat()`
- Use different providers depending on the content of the messages (e.g., route image-heavy requests to Gemini)
- Switch providers based on whether tools are present in the request
- Implement cost-based or latency-based routing logic

## Installation

No additional dependencies required. The RouterProvider is part of the `neuron-core/neuron-ai` package.

```php
use NeuronAI\Router\RouterProvider;
```

## Quick Start

```php
use NeuronAI\Router\RouterProvider;
use NeuronAI\Router\Rules\MethodRule;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;

$router = RouterProvider::make()
    ->addProvider('anthropic', new Anthropic(
        key: 'ANTHROPIC_API_KEY',
        model: 'claude-sonnet-4-20250514',
    ))
    ->addProvider('openai', new OpenAI(
        key: 'OPENAI_API_KEY',
        model: 'gpt-4o',
    ))
    ->setRule(
        new MethodRule('anthropic')->structured('openai')
    );

$agent->setAiProvider($router);
```

## How It Works

The router intercepts the fluent chain that agents use to call providers:

```php
$provider->systemPrompt($instructions)->setTools($tools)->chat(...$messages);
```

It buffers `systemPrompt()` and `setTools()`, then when the terminal method (`chat`, `stream`, or `structured`) is called, it:

1. Invokes the routing rule to pick a provider by name
2. Configures the chosen provider with the buffered system prompt and tools
3. Delegates the call and returns the result unchanged

## Routing Rules

Routing logic is defined via the `RoutingRuleInterface`. The router calls `resolveProvider()` on the rule, passing context about the current request:

```php
interface RoutingRuleInterface
{
    public function resolveProvider(string $method, array $messages, array $tools): string;
}
```

| Parameter  | Type     | Description                                            |
|------------|----------|--------------------------------------------------------|
| `$method`  | `string` | The inference method: `'chat'`, `'stream'`, or `'structured'` |
| `$messages`| `array`  | The messages being sent to the provider                 |
| `$tools`   | `array`  | The tools configured for this request                   |

The method must return the name of a registered provider (as a string).

### Built-in Rules

#### MethodRule

Routes based on the inference method. Set a default provider and optionally override specific methods:

```php
use NeuronAI\Router\Rules\MethodRule;

// Use Anthropic for everything, except structured output which goes to OpenAI
->setRule(
    new MethodRule('anthropic')->structured('openai')
)

// Override each method individually
->setRule(
    new MethodRule('openai')
        ->chat('anthropic')
        ->stream('anthropic')
        ->structured('openai')
)
```

#### CallbackRule

Wraps a callable for maximum flexibility. Use this when you need to inspect messages or tools:

```php
use NeuronAI\Router\Rules\CallbackRule;

// Route based on tools presence
->setRule(new CallbackRule(function (string $method, array $messages, array $tools): string {
    if (count($tools) > 0) {
        return 'anthropic';
    }
    return 'openai';
}))
```

#### RoundRobinRule

Distributes requests evenly across providers in sequence. Each call cycles to the next provider:

```php
use NeuronAI\Router\Rules\RoundRobinRule;

// Alternate between Anthropic and OpenAI for each request
->setRule(
    new RoundRobinRule(['anthropic', 'openai'])
)
```

#### ContentRule

Routes based on the content blocks inside messages (images, files, audio, video). When a message contains a content type that not all providers support, you can route it to one that does:

```php
use NeuronAI\Router\Rules\ContentRule;

// Use Anthropic by default, route images and video to Gemini, files to OpenAI
->setRule(
    new ContentRule('anthropic')
        ->image('gemini')
        ->video('gemini')
        ->file('openai')
)
```

When multiple content types are present in the same request, precedence is: **video → audio → image → file → default**. Content types without a configured provider are ignored and fall through to the next type in the precedence order.

### Custom Rules

Implement `RoutingRuleInterface` to create your own routing logic:

```php
use NeuronAI\Router\Rules\RoutingRuleInterface;

class ImageAwareRule implements RoutingRuleInterface
{
    public function __construct(
        private string $defaultProvider,
        private string $imageProvider,
    ) {}

    public function resolveProvider(string $method, array $messages, array $tools): string
    {
        foreach ($messages as $message) {
            foreach ($message->getContents() as $content) {
                if ($content instanceof ImageContent) {
                    return $this->imageProvider;
                }
            }
        }
        return $this->defaultProvider;
    }
}
```

Then use it:

```php
->setRule(
    new ImageAwareRule(
        defaultProvider: 'anthropic',
        imageProvider: 'gemini',
    )
)
```

## Using with an Agent

Inject the router just like any other provider — either via `setAiProvider()` or by overriding the `provider()` method:

```php
class MyAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return RouterProvider::make()
            ->addProvider('anthropic', new Anthropic(
                key: 'ANTHROPIC_API_KEY',
                model: 'claude-sonnet-4-20250514',
            ))
            ->addProvider('openai', new OpenAI(
                key: 'OPENAI_API_KEY',
                model: 'gpt-4o',
            ))
            ->setRule(
                new RoundRobinRule(['anthropic', 'openai'])
            );
    }
}
```

## Error Handling

The router throws `ProviderException` with clear messages for misconfiguration:

| Scenario | Error Message |
|----------|--------------|
| No routing rule set | `no routing strategy configured. Call setRule() to set one.` |
| No providers registered | `no providers registered. Call addProvider() to add one.` |
| Rule returns unknown name | `unknown provider 'name'. Available: ...` |

## Limitations

- `messageMapper()` and `toolPayloadMapper()` throw — these are internal to each concrete provider and are never called by the agent directly.
- `setHttpClient()` is forwarded to **all** registered providers.
- The routing rule is called on every inference request, so keep it fast.
