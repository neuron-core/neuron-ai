# Upgrade: Processor and tool error handler setters win over their hooks

## Summary

- **`RAG::setPreProcessors()` and `RAG::setPostProcessors()` replace the list.** They used to append, so a second call
  added to the processors of the first.
- **They win over the `preProcessors()` and `postProcessors()` hooks.** The setters used to fill a property that only
  the hooks' default bodies returned: a subclass declaring its own processors silently ignored the configured ones,
  so a container binding configuring a reranker had no effect. The hooks' defaults now return an empty list, and the
  properties stay null until a setter runs.
- **`Agent::toolErrorHandler()` wins over the `resolveToolErrorHandler()` hook**, for the same reason. The hook's
  default returns null.
- **A value that is not a processor raises `AgentException`** naming its type. A class name used to raise a raw
  `TypeError`.

## How to Refactor

### Case 1: Processors added with several setter calls

Pass the complete list in one call.

Before:

```php
$rag->setPostProcessors([new CohereRerankerPostProcessor(key: $key, model: 'rerank-v3.5')]);
$rag->setPostProcessors([new FixedThresholdPostProcessor(threshold: 0.5)]);
```

After:

```php
$rag->setPostProcessors([
    new CohereRerankerPostProcessor(key: $key, model: 'rerank-v3.5'),
    new FixedThresholdPostProcessor(threshold: 0.5),
]);
```

### Case 2: A hook that reads the setter's list

An override that returned `$this->preProcessors` or `$this->postProcessors`, or merged it with its own processors,
now reads null: unpacking it throws. Return only the declared processors; a configured list replaces them.

Before:

```php
protected function postProcessors(): array
{
    return [...$this->postProcessors, new FixedThresholdPostProcessor(threshold: 0.5)];
}
```

After:

```php
protected function postProcessors(): array
{
    return [new FixedThresholdPostProcessor(threshold: 0.5)];
}
```

### Case 3: A declared hook that must beat the setter

A subclass whose `preProcessors()`, `postProcessors()` or `resolveToolErrorHandler()` override was meant to apply even
when the setter ran now loses to the setter. Remove the setter call where the declared value must apply.

### Case 4: Class names passed as processors

Build the processor, for example through the application's container, and pass the instance.

Before:

```php
$rag->setPostProcessors([ReportReranker::class]);
```

After:

```php
$rag->setPostProcessors([$container->get(ReportReranker::class)]);
```

## What to Search For

```
grep -rn "setPreProcessors(\|setPostProcessors(" --include="*.php" .
grep -rn "function preProcessors\|function postProcessors" --include="*.php" .
grep -rn "this->preProcessors\|this->postProcessors" --include="*.php" .
grep -rn "toolErrorHandler(\|function resolveToolErrorHandler" --include="*.php" .
```

## Checklist

- Each RAG instance sets its processors with one `setPreProcessors()` / `setPostProcessors()` call holding the whole list.
- No `preProcessors()` or `postProcessors()` override reads `$this->preProcessors` or `$this->postProcessors`.
- Where a subclass declares processors or a tool error handler, a configured setter is meant to replace them.
- Processors are passed as instances.
