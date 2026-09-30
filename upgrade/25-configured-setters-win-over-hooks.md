# Upgrade: Processor and tool error handler setters win over their hooks

## Summary

| 3.x | 4.x |
|---|---|
| `RAG::setPreProcessors()` / `setPostProcessors()` append to the configured list | They replace it: only the last call on an instance counts |
| The `preProcessors()` / `postProcessors()` hooks' default bodies return the configured list, so an override silently replaced the setter | A configured list wins over the hook, and an empty list `[]` wins too. The hook runs only when no setter was called, and its default returns `[]` |
| `protected array $preProcessors = []` / `protected array $postProcessors = []` | `protected ?array $preProcessors = null` / `protected ?array $postProcessors = null`: `null` until a setter runs |
| The `resolveToolErrorHandler()` default returns the `toolErrorHandler()` value, so an override silently replaced the setter | A handler set with `toolErrorHandler()` wins. The hook runs only when no handler is set (including after `toolErrorHandler(null)`), and its default returns `null` |
| `Agent::toolMaxTries(int)` (deprecated) | Removed. Use `toolMaxRuns(int)` |

This is runtime configuration only. Nothing here is persisted, so data stored with 3.x is unaffected.

Other guides own these related changes:

- Guide 4 (already applied) migrated the `toolErrorHandler` callback signature (`ToolCall`, returning `null` rethrows).
- Guide 26 moves `ragNodes()` / `compose()` overrides to `entryNodes()` / `nodes()`. This guide changes only the
  constructor arguments inside them (Case 5).
- Guide 57 fixes the return type of an overridden `setPreProcessors()`, `setPostProcessors()`, `toolErrorHandler()` or
  `toolMaxRuns()`.

## What to Search For

```bash
# Case 1 and 4: setter calls and setter overrides
grep -rnE '(set(Pre|Post)Processors|toolErrorHandler)\(' --include='*.php' --exclude-dir=vendor .
# Case 2 and 4: hook overrides
grep -rnE 'function (preProcessors|postProcessors|resolveToolErrorHandler)\(' --include='*.php' --exclude-dir=vendor .
# Case 2 and 5: code that calls a hook (parent:: merges, nodes built from a hook)
grep -rnE '(->|::)(preProcessors|postProcessors|resolveToolErrorHandler)\(' --include='*.php' --exclude-dir=vendor .
# Case 2 and 3: reads, writes and redeclarations of the configured properties
grep -rnE '\$this->((pre|post)Processors|toolErrorHandler)([^(A-Za-z0-9_]|$)|(public|protected|private) [^=;(]*\$(pre|post)Processors\b' --include='*.php' --exclude-dir=vendor .
# Case 6
grep -rnE 'toolMaxTries\(' --include='*.php' --exclude-dir=vendor .
```

Keep the hits that belong to classes extending `NeuronAI\RAG\RAG` (processors) or `NeuronAI\Agent\Agent` (error
handler), directly or through the application's own base classes, and to the code that configures their instances:
constructors, `make()` factories, container bindings, controllers, commands and tests. For each such class, list its
hook overrides next to every setter call made on its instances, because the right case depends on the combination.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: Several setter calls on one instance

Each 3.x call appended to the list. In 4.x each call replaces the previous list. Merge the calls made on the same
instance into one call that holds the whole list, in the original order. This includes a call in the class's
constructor or factory plus a call at the call site, and a loop that calls the setter once per processor. The same
applies to `setPreProcessors()`.

Before (3.x):

```php
use NeuronAI\RAG\PostProcessor\CohereRerankerPostProcessor;
use NeuronAI\RAG\PostProcessor\FixedThresholdPostProcessor;

$rag->setPostProcessors([new CohereRerankerPostProcessor(key: $key, model: 'rerank-v3.5')]);
$rag->setPostProcessors([new FixedThresholdPostProcessor(threshold: 0.5)]);
```

After (4.x):

```php
use NeuronAI\RAG\PostProcessor\CohereRerankerPostProcessor;
use NeuronAI\RAG\PostProcessor\FixedThresholdPostProcessor;

$rag->setPostProcessors([
    new CohereRerankerPostProcessor(key: $key, model: 'rerank-v3.5'),
    new FixedThresholdPostProcessor(threshold: 0.5),
]);
```

### Case 2: A hook override that reads the configured value

Processor hooks come in these forms, and the same applies to `preProcessors()`:

- `return $this->postProcessors;`
- `[...$this->postProcessors, new X]`
- `array_merge($this->postProcessors, [...])`
- `[...parent::postProcessors(), new X]`

In 4.x the hook runs only when no setter was called, and the property is then `null`. The first three forms throw a
`TypeError` or `Error: Only arrays and Traversables can be unpacked`. The `parent::` form gets `[]` when the call
reaches `NeuronAI\RAG\RAG`'s own hook, so it no longer includes the configured processors. When it reaches an
application base class's override, it still returns that class's declared processors: keep that spread and migrate the
base class's override with this case.

1. Make the hook return only its own processors. Delete an override that only returned the property. Keep a `parent::`
   spread that reaches an application class's override.
2. If instances of the class also receive a setter call, both lists ran in 3.x, configured first. The setter's list now
   replaces the hook's, so keep the 3.x pipeline by adding the hook's processors to that setter call.

Before (3.x):

```php
use NeuronAI\RAG\PostProcessor\CohereRerankerPostProcessor;
use NeuronAI\RAG\PostProcessor\FixedThresholdPostProcessor;
use NeuronAI\RAG\RAG;

class SupportRag extends RAG
{
    protected function postProcessors(): array
    {
        return [...$this->postProcessors, new FixedThresholdPostProcessor(threshold: 0.5)];
    }
}

$rag = SupportRag::make()->setPostProcessors([
    new CohereRerankerPostProcessor(key: $key, model: 'rerank-v3.5'),
]);
```

After (4.x):

```php
use NeuronAI\RAG\PostProcessor\CohereRerankerPostProcessor;
use NeuronAI\RAG\PostProcessor\FixedThresholdPostProcessor;
use NeuronAI\RAG\RAG;

class SupportRag extends RAG
{
    protected function postProcessors(): array
    {
        return [new FixedThresholdPostProcessor(threshold: 0.5)];
    }
}

$rag = SupportRag::make()->setPostProcessors([
    new CohereRerankerPostProcessor(key: $key, model: 'rerank-v3.5'),
    new FixedThresholdPostProcessor(threshold: 0.5),
]);
```

A `resolveToolErrorHandler()` override that reads `$this->toolErrorHandler` or `parent::resolveToolErrorHandler()`:

- As a fallback (`return $this->toolErrorHandler ?? $defaultHandler;`), it keeps working. Leave it.
- If it wraps the configured handler (for example, it logs and then delegates to it), the wrapper no longer runs once
  `toolErrorHandler()` is called. Ask the developer whether configured handlers must still be wrapped. If they must,
  apply the wrapper to the handler passed to `toolErrorHandler()`.

### Case 3: A subclass that redeclares or writes the processor properties

In 4.x, redeclaring `protected array $postProcessors = [];` (or `$preProcessors`) in a RAG subclass is a fatal error:
`Type of App\...\SupportRag::$postProcessors must be ?array (as in class NeuronAI\RAG\RAG)`.

1. Delete the redeclaration.
2. Move processors that the subclass assigns to the property into the matching hook. This covers
   `$this->postProcessors[] = new X` and `$this->postProcessors = [...]`, usually in a constructor.
3. If instances also receive a setter call, both lists ran in 3.x. Put both in that setter call, as in Case 2 step 2.

Before (3.x):

```php
use NeuronAI\RAG\PostProcessor\CohereRerankerPostProcessor;
use NeuronAI\RAG\RAG;

class SupportRag extends RAG
{
    protected array $postProcessors = [];

    public function __construct(protected string $cohereKey)
    {
        parent::__construct();

        $this->postProcessors[] = new CohereRerankerPostProcessor(key: $cohereKey);
    }
}
```

After (4.x):

```php
use NeuronAI\RAG\PostProcessor\CohereRerankerPostProcessor;
use NeuronAI\RAG\RAG;

class SupportRag extends RAG
{
    public function __construct(protected string $cohereKey)
    {
        parent::__construct();
    }

    protected function postProcessors(): array
    {
        return [new CohereRerankerPostProcessor(key: $this->cohereKey)];
    }
}
```

### Case 4: A declared hook and a setter call on the same instances

This case covers an override of `preProcessors()`, `postProcessors()` or `resolveToolErrorHandler()` that never read
the configured value in 3.x. There, a setter call on that class had no effect. In 4.x any setter call wins over the
hook. That includes an empty list, for example `setPostProcessors($config['post_processors'] ?? [])` when the config
value is empty. `toolErrorHandler(null)` does not win: the hook applies.

For each such class, ask the developer which one should run. Name the setter call (`file:line`) and the hook
override. For example: "In 3.x, this `setPostProcessors()` call had no effect because `SupportRag::postProcessors()`
overrides it. Keep the declared processors, or let the configured list replace them?"

- Keep the declared value (the 3.x behaviour): remove the setter call.
- The configured value replaces the declared one: no change.
- The configured list applies only when it is not empty: skip the call when it is empty.

Before (3.x):

```php
use NeuronAI\RAG\PostProcessor\FixedThresholdPostProcessor;
use NeuronAI\RAG\RAG;

class SupportRag extends RAG
{
    protected function postProcessors(): array
    {
        return [new FixedThresholdPostProcessor(threshold: 0.5)];
    }
}

$rag = SupportRag::make()->setPostProcessors($configuredPostProcessors);
```

After (4.x), keeping the declared processors:

```php
$rag = SupportRag::make();
```

After (4.x), applying the configured list only when it is not empty:

```php
$rag = SupportRag::make();

if ($configuredPostProcessors !== []) {
    $rag->setPostProcessors($configuredPostProcessors);
}
```

Treat a `resolveToolErrorHandler()` override and a `toolErrorHandler($handler)` call on the same agent the same way.

### Case 5: Code that calls a hook to get the configured value

In 3.x, `$this->preProcessors()`, `$this->postProcessors()` and `$this->resolveToolErrorHandler()` returned the
configured value. In 4.x they return only the declared value, which is `[]` or `null` by default. So code that builds
`PreProcessNode`, `PostProcessNode`, `ToolNode` or `ParallelToolNode` from them silently ignores the setters. This
code is typically a 3.x `ragNodes()` or `compose()` override. Resolve the value the way 4.x does: the property first,
then the hook.

Before (3.x):

```php
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\RAG\Nodes\PostProcessNode;
use NeuronAI\RAG\Nodes\PreProcessNode;

new PreProcessNode($this->preProcessors()),
new PostProcessNode($this->postProcessors()),
new ToolNode($this->toolMaxRuns, $this->resolveToolErrorHandler()),
```

After (4.x):

```php
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\RAG\Nodes\PostProcessNode;
use NeuronAI\RAG\Nodes\PreProcessNode;

new PreProcessNode($this->preProcessors ?? $this->preProcessors()),
new PostProcessNode($this->postProcessors ?? $this->postProcessors()),
new ToolNode($this->toolMaxRuns, $this->toolErrorHandler ?? $this->resolveToolErrorHandler()),
```

Change the second argument of `new ParallelToolNode(...)` the same way. Change only these arguments here: guide 26
then moves the surrounding override. A `parent::` call inside a hook override is Case 2.

The same applies to code outside a hook override that reads the processor properties directly, for example a custom
method returning or counting `$this->preProcessors`. In 4.x the property is `null` until a setter runs, so these reads
throw a `TypeError`, and PHPStan level 5 does not report them. Replace each such read with
`$this->preProcessors ?? $this->preProcessors()` / `$this->postProcessors ?? $this->postProcessors()`. A read of
`$this->toolErrorHandler` outside `resolveToolErrorHandler()` means the same as in 3.x: leave it.

### Case 6: `toolMaxTries()`

`toolMaxTries()` is removed. `toolMaxRuns()` sets the same limit.

Before (3.x):

```php
$agent->toolMaxTries(5);
```

After (4.x):

```php
$agent->toolMaxRuns(5);
```

After the rename, nothing calls an application override of `toolMaxTries()`. Delete it, and move any extra logic it
had into a `toolMaxRuns()` override (guide 57 gives the return type).

## Checklist

- No `toolMaxTries(` remains.
- Each RAG instance receives at most one `setPreProcessors()` call and one `setPostProcessors()` call, each holding
  the whole list.
- No `preProcessors()` / `postProcessors()` override reads `$this->preProcessors` / `$this->postProcessors`, or spreads
  a `parent::preProcessors()` / `parent::postProcessors()` that resolves to `NeuronAI\RAG\RAG`'s default hook.
- Outside hook overrides, `$this->preProcessors` / `$this->postProcessors` are read only as the left side of
  `$this->preProcessors ?? $this->preProcessors()` / `$this->postProcessors ?? $this->postProcessors()`.
- No class redeclares `$preProcessors` / `$postProcessors`, and no subclass writes them. Only the setters do.
- Where a class overrides a hook and its instances also receive the matching setter call, the setter call either
  carries both lists (Cases 2 and 3) or was decided with the developer (Case 4).
- Outside hook overrides, every remaining `preProcessors()`, `postProcessors()` or `resolveToolErrorHandler()` call is
  in the form `$this->preProcessors ?? $this->preProcessors()`, `$this->postProcessors ?? $this->postProcessors()` or
  `$this->toolErrorHandler ?? $this->resolveToolErrorHandler()`.
- PHPStan reports no error about these properties, hooks or setters.
