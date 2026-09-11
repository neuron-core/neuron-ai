# Upgrade: Stream adapters moved out of `Chat\Messages\Stream\Adapters`

## Summary

In 3.x every stream adapter class lived under `NeuronAI\Chat\Messages\Stream\Adapters`.
The namespace is gone. The classes are split by ownership:

- **Protocol-neutral contracts** now belong to the Workflow, because it is the Workflow
  that converts streamed output into protocol lines (see `setStreamAdapter()` in guide 13).
- **Agent-facing adapters** (AG-UI, Vercel AI SDK) now belong to the Agent, because they
  encode Agent concepts such as tool calls and approvals.

| 3.x class | 4.x class |
|---|---|
| `NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface` | `NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface` |
| `NeuronAI\Chat\Messages\Stream\Adapters\SSEAdapter` | `NeuronAI\Workflow\Streaming\Adapter\SSEAdapter` |
| `NeuronAI\Chat\Messages\Stream\Adapters\AGUIAdapter` | `NeuronAI\Agent\Adapters\AGUIAdapter` |
| `NeuronAI\Chat\Messages\Stream\Adapters\VercelAIAdapter` | `NeuronAI\Agent\Adapters\VercelAIAdapter` |

Class names are unchanged; only the namespace differs. No 3.x class remains at the old
location and there is no alias, so any reference to the old namespace is a fatal
"class not found" error at runtime.

New in 4.x, and therefore never present in a 3.x codebase, are the sibling types
`NeuronAI\Workflow\Streaming\Adapter\CustomizableStreamAdapterInterface`,
`NeuronAI\Workflow\Streaming\Adapter\MapsStreamEvents` and the portable events under
`NeuronAI\Agent\Adapters\Events`. They need no migration.

## What to Search For

Search the whole application, including tests and config, excluding `vendor/`:

```
grep -rn "Chat\\\\Messages\\\\Stream\\\\Adapters" --include="*.php" .
grep -rn "Chat\\\\Messages\\\\Stream\\\\Adapters" --include="*.yaml" --include="*.yml" --include="*.neon" --include="*.xml" --include="*.json" .
```

The first command finds `use` imports, fully-qualified references and `::class` constants.
The second finds class names written as strings in container definitions (Symfony
services, Laravel config), PHPStan configuration and similar files.

Follow the matches: a custom adapter that `extends SSEAdapter` or
`implements StreamAdapterInterface` may sit in a file that imports the old namespace only
once, at the top, while the class body references the short names.

## How to Refactor

Rewrite every reference according to the table above. Nothing else in the file changes.

### Case 1: Imports of a built-in adapter

Before:

```php
use NeuronAI\Chat\Messages\Stream\Adapters\VercelAIAdapter;
use NeuronAI\Chat\Messages\Stream\Adapters\AGUIAdapter;
```

After:

```php
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Adapters\AGUIAdapter;
```

### Case 2: Custom adapters extending the base class or implementing the interface

Before:

```php
use NeuronAI\Chat\Messages\Stream\Adapters\SSEAdapter;
use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;

final class MyProtocolAdapter extends SSEAdapter { /* ... */ }
final class MyRawAdapter implements StreamAdapterInterface { /* ... */ }
```

After:

```php
use NeuronAI\Workflow\Streaming\Adapter\SSEAdapter;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;

final class MyProtocolAdapter extends SSEAdapter { /* ... */ }
final class MyRawAdapter implements StreamAdapterInterface { /* ... */ }
```

Custom adapters must also satisfy the enlarged 4.x contract (`suspended()` and `error()`),
covered by guide 21. Fix the namespace first so the class loads, then apply guide 21 if it
has not been applied yet.

### Case 3: Type hints and `::class` references

Before:

```php
public function stream(\NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface $adapter): void
```

After:

```php
public function stream(\NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface $adapter): void
```

### Case 4: Class names in configuration strings

Before:

```php
'adapter' => 'NeuronAI\Chat\Messages\Stream\Adapters\VercelAIAdapter',
```

After:

```php
'adapter' => \NeuronAI\Agent\Adapters\VercelAIAdapter::class,
```

Prefer the `::class` constant when the file is PHP so a future move fails static analysis
instead of production.

## Verification Checklist

- [ ] The search patterns above return no matches outside `vendor/`
- [ ] Every custom adapter still resolves its parent class or interface (run
      `composer dump-autoload` and the test suite, or `vendor/bin/phpstan`)
- [ ] Container and configuration files reference the new fully-qualified names
