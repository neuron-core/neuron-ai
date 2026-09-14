# Upgrade: `AIProviderInterface` requires `getModel()` and hides the mappers

## Summary

Two things changed on the provider contract for code that implements a provider or introspects
one.

- `getModel(): string` is now part of `AIProviderInterface`. A custom provider without it fails
  at load time.
- `messageMapper()` and `toolPayloadMapper()` are no longer part of the contract. The built-in
  providers keep them as protected internals, and `MessageMapperInterface` and
  `ToolMapperInterface` still exist for them, but nothing outside a provider can ask for its
  mappers any more. `FakeMessageMapper` and `FakeToolMapper` were deleted together with the
  accessors that returned them.

The other contract changes have their own guides: the return types in guide 4, the
`systemPrompt()` parameter in guide 25.

| 3.x | 4.x |
|---|---|
| `AIProviderInterface`: `systemPrompt()`, `setTools()`, `messageMapper()`, `toolPayloadMapper()`, `chat()`, `stream()`, `structured()`, `setHttpClient()` | `getModel()`, `systemPrompt()`, `setTools()`, `chat()`, `stream()`, `structured()`, `setHttpClient()` |
| `$provider->messageMapper()->map($messages)` from outside the provider | Not available. A provider's wire format is its own business; a neutral serialization is `jsonSerialize()` on each message |
| `FakeMessageMapper`, `FakeToolMapper`, `FakeAIProvider::messageMapper()`, `FakeAIProvider::toolPayloadMapper()` | Removed |

## What to Search For

Search the whole application, including tests and config, excluding `vendor/`:

```
grep -rn "implements AIProviderInterface" --include="*.php" .
grep -rn "messageMapper()\|toolPayloadMapper()" --include="*.php" .
grep -rn "FakeMessageMapper\|FakeToolMapper" --include="*.php" .
```

The first finds custom providers that must declare `getModel()`. The second finds mapper
accessors: inside a provider they may stay, outside one they have no replacement. The third
finds tests built on the deleted fakes.

## How to Refactor

### Case 1: A custom provider

Before:

```php
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

    // chat(), stream(), structured(), ...
}
```

After:

```php
class MyProvider implements AIProviderInterface
{
    public function __construct(protected string $model)
    {
    }

    public function getModel(): string
    {
        return $this->model;
    }

    protected function messageMapper(): MessageMapperInterface
    {
        return new MyMessageMapper();
    }

    protected function toolPayloadMapper(): ToolMapperInterface
    {
        return new MyToolMapper();
    }

    // chat(), stream(), structured(), ...
}
```

Keeping the mapper accessors public is harmless; making them protected matches the built-in
providers.

### Case 2: Code that asked a provider for its mappers

Before:

```php
$payload = $provider->messageMapper()->map($messages);
```

After, when a neutral serialization is enough:

```php
$payload = array_map(fn (Message $message): array => $message->jsonSerialize(), $messages);
```

Code that needs the exact request a provider sends has no replacement: the wire format lives
inside the provider. Log it there, in the provider's own `chat()` or `stream()`.

### Case 3: Tests built on the deleted fakes

The fakes were one-line wrappers around `jsonSerialize()`.

Before:

```php
$mapped = (new FakeMessageMapper())->map($messages);
```

After:

```php
$mapped = array_map(fn (Message $message): array => $message->jsonSerialize(), $messages);
```

## Checklist

- Every class implementing `AIProviderInterface` declares `getModel(): string`.
- No call to `messageMapper()` or `toolPayloadMapper()` on a provider from outside its class.
- No reference to `FakeMessageMapper` or `FakeToolMapper`.
