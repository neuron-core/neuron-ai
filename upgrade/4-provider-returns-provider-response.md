# Upgrade: AI Providers Return ProviderResponse Instead of Message

## Summary

AI provider methods `chat()` and `stream()` now return `ProviderResponse` instead of `Message`. The `ProviderResponse` wraps the assistant message and provides access to the raw HTTP response body and headers.

**This only affects standalone provider usage.** When providers are used inside an Agent (via `chat()`, `stream()`, or `structured()` on the Agent itself), no changes are needed — the Agent handles the `ProviderResponse` internally.

You only need to refactor code that calls provider methods directly, such as in scripts, controllers, commands, or workflows.

## What to Search For

Find files that use providers directly — look for provider class names and the `AIProviderInterface`:

```
grep -rn "use NeuronAI\\Providers\\" --include="*.php" .
grep -rn "AIProviderInterface" --include="*.php" .
```

Identify standalone usage by searching for direct calls to `->chat(`, `->stream(`, or `->structured(` on provider instances (not on Agent instances):

```
grep -rn "->chat(" --include="*.php" .
grep -rn "->stream(" --include="*.php" .
```

Within the results, exclude calls inside Agent classes or Agent subclasses — those are handled internally and do not need changes.

Look for type hints or variable assignments that expect a `Message` return from provider calls:

```
grep -rn "Provider.*chat\|Provider.*stream" --include="*.php" .
grep -rn ": Message\b" --include="*.php" .
```

## Refactoring Instructions

### Case 1: `chat()` — accessing the assistant message

**Before:**

```php
$response = $provider->chat($message);

// $response was a Message
$content = $response->getContent();
```

**After — call `message()` on the ProviderResponse:**

```php
$response = $provider->chat($message);

// $response is now a ProviderResponse
$content = $response->message()->getContent();
```

### Case 2: `chat()` — assigning to a Message-typed variable

**Before:**

```php
/** @var Message $response */
$response = $provider->chat($message);
$text = $response->getContent();
```

**After:**

```php
$response = $provider->chat($message);
$text = $response->message()->getContent();
```

### Case 3: `stream()` — getting the result after iteration

**Before:**

```php
$generator = $provider->stream($message);

foreach ($generator as $chunk) {
    echo $chunk;
}

// $generator->getReturn() was a Message
$message = $generator->getReturn();
$content = $message->getContent();
```

**After — the generator return value is a ProviderResponse:**

```php
$generator = $provider->stream($message);

foreach ($generator as $chunk) {
    echo $chunk;
}

// $generator->getReturn() is now a ProviderResponse
$message = $generator->getReturn()->message();
$content = $message->getContent();
```

### Case 4: Accessing raw response body or headers

This is new functionality that was not available before. If the code needs raw HTTP data:

```php
$response = $provider->chat($message);

$response->message();   // Message — the deserialized assistant message
$response->body();      // ?string — raw HTTP response body
$response->headers();   // array — response headers
$response->metadata('usage'); // mixed — per-key metadata access
```

### Case 5: `structured()` — no change needed

The `structured()` method returns the DTO class instance directly, same as before:

```php
// No change required
$dto = $provider->structured($messages, MyDto::class, $schema);
```

### Case 6: Provider used inside an Agent — no change needed

When providers are configured on an Agent and used via Agent methods, no refactoring is needed:

```php
// No change required — Agent handles ProviderResponse internally
$agent = MyAgent::make()->chat($message);
$agent = MyAgent::make()->stream($message);
$agent = MyAgent::make()->structured($message, MyDto::class);
```

## Checklist

For each file you modify:

- [ ] The file uses a provider directly (not through an Agent)
- [ ] `$provider->chat(...)` return value: chained calls to `->getContent()`, `->getRole()`, etc. are now accessed via `->message()->getContent()`, `->message()->getRole()`
- [ ] `$provider->stream(...)` generator return value: `$generator->getReturn()` is a `ProviderResponse`, call `->message()` on it
- [ ] No changes to `structured()` calls
- [ ] No changes to code where providers are used inside Agents
- [ ] `ProviderResponse` import added where needed: `use NeuronAI\Providers\ProviderResponse;`
