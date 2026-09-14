# Upgrade: Agent instructions are a `SystemMessage`

## Summary

In 3.x agent instructions were a plain string: `instructions()` returned one,
`setInstructions()` accepted one, and `resolveInstructions()` handed the effective string to
whoever asked, including the provider through `systemPrompt(?string)`. Provider features that
work on system blocks, such as prompt-caching breakpoints or several system parts, had no place
to live.

In 4.x the instructions are a `NeuronAI\Chat\Messages\SystemMessage`: a message carrying one or
more `SystemContent` blocks, each of which can be marked cached. Strings are still accepted at
every boundary and wrapped immediately, so an agent that returns or sets a string needs no change.
What breaks is code that read the instructions back as a string, and custom providers that kept
the 3.x parameter type.

| 3.x | 4.x |
|---|---|
| `protected function instructions(): string` | `protected function instructions(): SystemMessage\|string`. A string return still works, and so does the `SystemPrompt` builder, because it is `Stringable` |
| `setInstructions(string $instructions)` | `setInstructions(SystemMessage\|string $instructions)` |
| `resolveInstructions(): string` on `AgentInterface` | Removed. `getInstructions(): SystemMessage` returns the effective instructions; `->getContent()` renders them as text |
| `AIProviderInterface::systemPrompt(?string $prompt)` | `systemPrompt(SystemMessage\|string\|null $prompt)`. A custom provider keeping the 3.x signature fails at load time, because an implementation may not narrow a parameter |
| `RequestRecord::$systemPrompt` is `?string` (the `FakeAIProvider` recording) | It is `?SystemMessage` |

`SystemMessage::getContent()` renders the blocks as text separated by a blank line,
`contains($text)` tells whether a text appears in any block, and `cache()` marks every block
cached.

## What to Search For

Search the whole application, including tests and config, excluding `vendor/`:

```
grep -rn "resolveInstructions(" --include="*.php" .
grep -rn "function systemPrompt(" --include="*.php" .
grep -rn -- "->systemPrompt\b" --include="*.php" .
```

The first finds every read of the effective instructions. The second finds custom providers.
The third finds tests that read the provider fake's recording. Agent subclasses overriding
`instructions()` and callers of `setInstructions()` need no change.

## How to Refactor

### Case 1: Reading the effective instructions

Before:

```php
$text = $agent->resolveInstructions();

$this->assertStringContainsString('helpful', $agent->resolveInstructions());
```

After:

```php
$text = $agent->getInstructions()->getContent();

$this->assertTrue($agent->getInstructions()->contains('helpful'));
```

### Case 2: A custom provider

Before:

```php
public function systemPrompt(?string $prompt): AIProviderInterface
{
    $this->system = $prompt;
    return $this;
}
```

After:

```php
use NeuronAI\Chat\Messages\SystemMessage;

public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
{
    $this->system = $prompt instanceof SystemMessage ? $prompt->getContent() : $prompt;
    return $this;
}
```

A provider whose API has native system blocks maps `$prompt->getContentBlocks()` instead of
flattening them; each `SystemContent` reports `isCached()` so the provider can place a cache
breakpoint on it.

### Case 3: Reading the provider fake's recording

Before:

```php
$record = $provider->getRecorded()[0];

$this->assertSame('Be helpful', $record->systemPrompt);
```

After:

```php
$record = $provider->getRecorded()[0];

$this->assertSame('Be helpful', $record->systemPrompt?->getContent());
```

`$provider->assertSystemPrompt('Be helpful')` still takes a string and needs no change.

### Case 4: Adopting system blocks in an agent (optional)

A string return keeps working unchanged:

```php
protected function instructions(): string
{
    return (string) new SystemPrompt(background: ['You are a data analyst.']);
}
```

Switch to a `SystemMessage` only to split the instructions into blocks, for example to cache
the static part:

```php
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\SystemMessage;

protected function instructions(): SystemMessage|string
{
    return new SystemMessage([
        (new SystemContent((string) new SystemPrompt(background: ['You are a data analyst.'])))->cache(),
        new SystemContent('Today is ' . date('Y-m-d')),
    ]);
}
```

## Checklist

- No call to `resolveInstructions()` remains; reads go through `getInstructions()`.
- Every custom provider declares `systemPrompt(SystemMessage|string|null $prompt)`.
- Tests read `$record->systemPrompt` as a `SystemMessage` or use `assertSystemPrompt()`.
