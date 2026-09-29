# Upgrade: Tool names must be unique

## Summary

In 3.x an agent built with two tools of the same name sent both definitions to the provider. Most providers reject such
a request with an HTTP 400, and one that accepted it always ran the first tool, whichever one the model chose. The
built-in toolkits collide this way: the Tavily and Jina toolkits both provide tools named `web_search` and `url_reader`.

- **Building an agent's tools with a repeated name throws a `ToolException`** (`Tool names must be unique: "web_search"
  is registered twice.`) when the run starts, before the first request to the provider.
- **`ToolRegistry::add()` still ignores a tool already registered**, so a middleware may register its tool on every
  segment.
- **Unnamed provider tools never clash.** In 3.x `add()` dropped a second unnamed provider tool, such as `code_execution`
  added next to `web_search`.

| Before (3.x) | After |
|---|---|
| `TavilyToolkit` and `JinaToolkit` on one agent send `web_search` and `url_reader` twice | The run fails with a `ToolException` naming the first repeated tool |
| `new ToolRegistry([$a, $b])` with the same name keeps both | Throws `ToolException` |
| `$registry->add()` of a second unnamed provider tool drops it | Registers it |

## How to Refactor

Rename the colliding tools of one toolkit through its `with()` hook:

```php
use NeuronAI\Tools\Toolkits\Jina\JinaToolkit;
use NeuronAI\Tools\Toolkits\Jina\JinaUrlReader;
use NeuronAI\Tools\Toolkits\Jina\JinaWebSearch;
use NeuronAI\Tools\ToolInterface;

protected function tools(): array
{
    return [
        TavilyToolkit::make($tavilyKey),
        JinaToolkit::make($jinaKey)
            ->with(JinaWebSearch::class, fn (ToolInterface $tool): ToolInterface => $tool->setName('jina_web_search'))
            ->with(JinaUrlReader::class, fn (ToolInterface $tool): ToolInterface => $tool->setName('jina_url_reader')),
    ];
}
```

Or leave them out of one toolkit with `exclude()`:

```php
JinaToolkit::make($jinaKey)->exclude([JinaWebSearch::class, JinaUrlReader::class]),
```
