# Upgrade: `AIProviderInterface` requires `getModel()` and drops the mapper accessors

## Summary

- `AIProviderInterface` now declares `public function getModel(): string`. Every built-in provider implements it.
- `messageMapper()` and `toolPayloadMapper()` are no longer part of `AIProviderInterface`. On every built-in provider they are now `protected`. `MessageMapperInterface`, `ToolMapperInterface` and the vendor mapper classes (`NeuronAI\Providers\<Vendor>\MessageMapper`, `...\ToolMapper`) are still public.
- Apps that only construct built-in providers and use them through an Agent are not affected.
- No stored data is involved: nothing to migrate in databases or files.

| 3.x | 4.x |
|---|---|
| A class implementing `AIProviderInterface` declares `messageMapper()` and `toolPayloadMapper()` | It must declare `public function getModel(): string`. Its own `messageMapper()`/`toolPayloadMapper()` are ordinary methods now: leave them as they are |
| A subclass of a built-in provider may declare `getModel()` with any signature | The parent declares `public function getModel(): string`, and any other signature is a fatal error at load |
| `$provider->messageMapper()->map($messages)` on a built-in provider or an `AIProviderInterface` value | `(new <message mapper class from the Case 4 table>())->map($messages)` for the exact vendor payload (for example `new \NeuronAI\Providers\OpenAI\MessageMapper()` for OpenAI, Grok or OpenAILike), or `$message->jsonSerialize()` on each message for a neutral format |
| `$provider->toolPayloadMapper()->map($tools)` | `(new <tool mapper class from the Case 4 table>())->map($tools)` (Deepseek, Cohere and Mistral use `\NeuronAI\Providers\OpenAI\ToolMapper`), or `$tool->jsonSerialize()` on each tool |
| Test doubles configure `messageMapper`/`toolPayloadMapper` | Delete those expectations. Stub `getModel` where the code under test reads it |

Not in this step:

- The `systemPrompt()` parameter (guide 24) and the `ProviderResponse` return types (guide 41) were migrated earlier. Classes you touch here already have them.
- `FakeMessageMapper`, `FakeToolMapper`, and `messageMapper()`/`toolPayloadMapper()` calls on a `FakeAIProvider`: guide 56 migrates them. Leave those hits alone.
- Other protected members of provider subclasses: guide 43.

## What to Search For

Run from the application root:

```bash
grep -rnE 'implements[^{]*AIProviderInterface' --include='*.php' --exclude-dir=vendor .
grep -rnE 'messageMapper|toolPayloadMapper' --include='*.php' --exclude-dir=vendor .
grep -rnE 'function[[:space:]]+getModel[[:space:]]*\(' --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:

- **Search 1** finds classes that implement the interface on one line: plain, fully qualified (`\NeuronAI\Providers\AIProviderInterface`), next to other interfaces, and anonymous classes (`new class (...) implements AIProviderInterface`). These are Case 1, or Case 3 when the class wraps another provider.
- **Search 2** also finds implementers whose `implements` clause spans several lines, because every 3.x implementer had to declare `messageMapper()` and `toolPayloadMapper()`. If a declaration sits in an app abstract base class, also check its subclasses (`grep -rnE 'extends[[:space:]]+<BaseName>\b'`). Search 2 also finds outside calls (Case 4) and test doubles that name the methods as strings, such as `->method('messageMapper')` or `shouldReceive('toolPayloadMapper')` (Case 5). These hits need no change: a `messageMapper()`/`toolPayloadMapper()` declaration in a subclass of a built-in provider, a `$this->messageMapper()` call inside a provider, and reads or writes of the `$this->messageMapper`/`$this->toolPayloadMapper` properties.
- **Search 3** finds existing `getModel()` methods. Act only on classes that implement `AIProviderInterface` or extend a Neuron provider, directly or through an app class (Case 1 or Case 2). A `getModel()` on an unrelated class, such as a repository or an Eloquent builder, is not part of this guide.

If none of the searches finds anything in a provider class, a caller of a provider or a test double of one, this guide does not apply.

## How to Refactor

### Case 1: A class that implements `AIProviderInterface`

Add `getModel()` and return whatever identifies the model the provider calls. Use a constant string if the provider has only one model. Leave the existing `messageMapper()` and `toolPayloadMapper()` exactly as they are, with the same visibility, because app code may still call them on this class.

Before:

```php
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;

class MyProvider implements AIProviderInterface
{
    public function __construct(protected string $model)
    {
    }

    public function messageMapper(): MessageMapperInterface
    {
        return new MyMessageMapper();
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return new MyToolMapper();
    }

    // systemPrompt(), setTools(), chat(), stream(), structured(), setHttpClient()
}
```

After:

```php
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;

class MyProvider implements AIProviderInterface
{
    public function __construct(protected string $model)
    {
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return new MyMessageMapper();
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return new MyToolMapper();
    }

    // systemPrompt(), setTools(), chat(), stream(), structured(), setHttpClient()
}
```

Do the same in anonymous classes. For an abstract base class, add `getModel()` to the base when it holds the model, otherwise to each concrete subclass.

If the class already declares `getModel()`, change its signature to exactly `public function getModel(): string`. A getModel() that returns something other than a string, such as an object or an enum, is a case for the developer. Ask whether to rename the app's method (for example to `getModelConfig()`) and update its callers, then add the string `getModel()`.

### Case 2: A subclass of a built-in provider that declares `getModel()`

`getModel()` is inherited from the built-in provider, so a subclass adds nothing. A subclass that already declared its own `getModel()` in 3.x fails to load unless the declaration is exactly `public function getModel(): string`. Each of these is a fatal error at load: no return type, a return type other than `string` (for example `?string`), a required parameter, or `protected` visibility.

Before:

```php
use NeuronAI\Providers\OpenAI\OpenAI;

class TenantOpenAI extends OpenAI
{
    public function getModel()
    {
        return $this->model;
    }
}
```

After:

```php
use NeuronAI\Providers\OpenAI\OpenAI;

class TenantOpenAI extends OpenAI
{
    public function getModel(): string
    {
        return $this->model;
    }
}
```

If the body is only `return $this->model;`, you can delete the method instead. The inherited one returns the same value. If it returns something other than a string, ask the developer as in Case 1.

A subclass that overrides `messageMapper()` or `toolPayloadMapper()` needs no change. The provider still calls `$this->messageMapper()` internally, and the public override stays callable from outside.

### Case 3: A wrapper provider that delegated the mappers

This is a class implementing `AIProviderInterface` around an inner provider, for logging, caching, fallback or routing. Delete the methods that returned `$this->inner->messageMapper()` or `$this->inner->toolPayloadMapper()`: the interface no longer declares them, and on a built-in inner provider they throw `Error: Call to protected method`. Then delegate `getModel()`.

Before:

```php
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;

class LoggingProvider implements AIProviderInterface
{
    public function __construct(protected AIProviderInterface $inner)
    {
    }

    public function messageMapper(): MessageMapperInterface
    {
        return $this->inner->messageMapper();
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return $this->inner->toolPayloadMapper();
    }

    // systemPrompt(), setTools(), chat(), stream(), structured(), setHttpClient() delegate to $this->inner
}
```

After:

```php
use NeuronAI\Providers\AIProviderInterface;

class LoggingProvider implements AIProviderInterface
{
    public function __construct(protected AIProviderInterface $inner)
    {
    }

    public function getModel(): string
    {
        return $this->inner->getModel();
    }

    // systemPrompt(), setTools(), chat(), stream(), structured(), setHttpClient() delegate to $this->inner
}
```

1. Delete the delegating mapper methods and the `MessageMapperInterface`/`ToolMapperInterface` imports they leave unused.
2. Search the app for calls to those methods on the wrapper (`$wrapper->messageMapper()`) and migrate them as in Case 4.
3. A wrapper over several providers, such as a router or a fallback chain, has no single model. Ask the developer which identifier `getModel()` should return, for example the primary provider's `getModel()`.

### Case 4: Code that asks a provider for its mappers

Calling `messageMapper()` or `toolPayloadMapper()` from outside a built-in provider now fails with `Call to protected method`. The interface no longer declares them either, so on a value typed `AIProviderInterface` the call is an undefined method. First decide which of these applies:

- **The value is typed as the app's own provider class (Case 1), or as a subclass that overrides the accessor as `public` (Case 2).** It keeps working. Leave it.
- **The code needs the exact payload the provider sends,** for example for token estimation, request replay or snapshot tests. Instantiate the mapper class the provider itself uses. Every vendor mapper is a public class built with no arguments, exactly as the provider builds it.
- **Any readable serialization is enough,** for example for logging or debugging. Call `jsonSerialize()` on each message or tool.
- **The code needs the whole outgoing HTTP request,** including URL, headers and body. Wrap the provider's HTTP client instead and pass it with `setHttpClient()`. Guide 2 covers custom `HttpClientInterface` implementations.

If the variable is typed `AIProviderInterface`, the concrete provider is unknown at the call site, and the code needs the exact vendor payload, ask the developer which provider classes this code serves. Then pick the mappers from the table below, or fall back to `jsonSerialize()`.

Before:

```php
// $provider is an Anthropic instance
$payload = [
    'messages' => $provider->messageMapper()->map($messages),
    'tools' => $provider->toolPayloadMapper()->map($tools),
];
```

After (exact vendor payload):

```php
$payload = [
    'messages' => (new \NeuronAI\Providers\Anthropic\MessageMapper())->map($messages),
    'tools' => (new \NeuronAI\Providers\Anthropic\ToolMapper())->map($tools),
];
```

After (neutral format):

```php
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;

$payload = [
    'messages' => array_map(fn (Message $message): array => $message->jsonSerialize(), $messages),
    'tools' => array_map(fn (ToolInterface|ProviderToolInterface $tool): array => $tool->jsonSerialize(), $tools),
];
```

These are the mapper classes each built-in provider uses (all under `NeuronAI\Providers\`). A subclass uses its parent's mappers unless it overrides the accessors:

| Provider | Message mapper | Tool mapper |
|---|---|---|
| `OpenAI\OpenAI`, `OpenAI\AzureOpenAI`, `OpenAILike`, `XAI\Grok`, `HuggingFace\HuggingFace`, `Alibaba\DashScopeOpenAI` | `OpenAI\MessageMapper` | `OpenAI\ToolMapper` |
| `Deepseek\Deepseek` | `Deepseek\MessageMapper` | `OpenAI\ToolMapper` |
| `Cohere\Cohere` | `Cohere\MessageMapper` | `OpenAI\ToolMapper` |
| `Mistral\Mistral` | `Mistral\MessageMapper` | `OpenAI\ToolMapper` |
| `ZAI\ZAI` | `ZAI\MessageMapper` | `ZAI\ToolMapper` |
| `Anthropic\Anthropic`, `Anthropic\AnthropicVertex` | `Anthropic\MessageMapper` | `Anthropic\ToolMapper` |
| `Gemini\Gemini`, `Gemini\GeminiVertex` | `Gemini\MessageMapper` | `Gemini\ToolMapper` |
| `Ollama\Ollama` | `Ollama\MessageMapper` | `Ollama\ToolMapper` |
| `AWS\BedrockRuntime` | `AWS\MessageMapper` | `AWS\ToolMapper` |
| `OpenAI\Responses\OpenAIResponses`, `OpenAILikeResponses` | `OpenAI\Responses\MessageMapper` | `OpenAI\Responses\ToolMapper` |

Tests that compare `jsonSerialize()` output with literal arrays get the 4.x message shape from guide 34.

### Case 5: Test doubles that configure the mapper accessors

In a double of `AIProviderInterface`, or a `createMock()` of a built-in provider class, configuring `messageMapper` or `toolPayloadMapper` now throws `MethodCannotBeConfiguredException` in PHPUnit. Delete those expectations, and the Mockery `shouldReceive('messageMapper')`/`shouldReceive('toolPayloadMapper')` ones too. The code under test no longer calls these methods once Case 4 is applied. If the code under test reads `getModel()`, stub it.

Before:

```php
use NeuronAI\Providers\AIProviderInterface;

$provider = $this->createMock(AIProviderInterface::class);
$provider->method('messageMapper')->willReturn($mapper);
```

After:

```php
use NeuronAI\Providers\AIProviderInterface;

$provider = $this->createMock(AIProviderInterface::class);
$provider->method('getModel')->willReturn('gpt-4o');
```

A partial mock that replaces the mapper a built-in provider uses internally, such as `getMockBuilder(OpenAI::class)->onlyMethods(['messageMapper'])`, can stay. Run the test to confirm it still passes.

## Checklist

- Every class implementing `AIProviderInterface` (named, anonymous, or through an app base class) has `public function getModel(): string`.
- Every `getModel()` in a provider class, whether the app's own or a subclass of a built-in provider, is declared exactly `public function getModel(): string`.
- The app's own `messageMapper()`/`toolPayloadMapper()` declarations kept their visibility. The wrapper providers no longer delegate them.
- Search 2 has no call on a built-in provider or an `AIProviderInterface` value from outside the provider. The remaining hits are declarations, `$this->` calls or property accesses inside a provider, calls on the app's own provider class, or `FakeAIProvider` usages left for guide 56.
- No test double configures `messageMapper` or `toolPayloadMapper` on `AIProviderInterface` or with `createMock()` of a built-in provider.
- PHPStan or the test suite loads every provider class without a `getModel()` declaration error.
