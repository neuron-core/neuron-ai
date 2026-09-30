# Upgrade: MCP tools are McpTool instances built from strict schemas

## Summary

- `McpConnector::tools()` converts each server tool's `inputSchema` with `NeuronAI\Tools\ToolPropertyFactory::fromSchema()`. It throws `NeuronAI\Exceptions\ToolException` when a schema uses something the tool property types cannot represent. 3.x degraded such a property silently: without a `type` it became a string, and a type list kept its first type. This can break apps that extend nothing: Python (FastMCP/Pydantic) servers, for example, describe every optional parameter as `"anyOf": [{"type": "integer"}, {"type": "null"}]`.
- The tools are `NeuronAI\MCP\McpTool` instances, and `McpTool` extends `NeuronAI\Tools\Tool`. `NeuronAI\MCP\CallableMcpTool` is removed.
- The protected hooks `createToolProperty()`, `createArrayProperty()` and `createObjectProperty()` are removed. 4.x converts nested object properties and array items itself.
- The constructor is `__construct(array $config, ?HttpClientInterface $httpClient = null)`. A subclass constructor that sets `$this->config` without calling the parent constructor now breaks the connector.

| 3.x | 4.x |
|---|---|
| A property with `anyOf`, `oneOf`, `$ref` or a type list was silently simplified | `tools()` throws `ToolException` (Case 5) |
| `Tool::make(...)->setCallable(new CallableMcpTool(connector: ..., item: ...))` | `new McpTool(name: ..., description: ..., annotations: ..., connector: ..., item: ...)`, or `parent::createTool($item)` (Cases 2, 3) |
| `class X extends CallableMcpTool` wrapping `__invoke()` | an `invokeTool()` override on the connector subclass (Case 3) |
| `createToolProperty()` / `createArrayProperty()` / `createObjectProperty()` overrides | a `createTool()` override that rewrites `$item['inputSchema']` (Case 4) |
| subclass constructor: `$this->config = [...]` | `parent::__construct([...])` (Case 1) |

Stored data: `McpConnector` serializes the same `config`, `only` and `exclude` keys as in 3.x, so this guide changes no stored data. Runs paused under 3.x that hold MCP tools cannot be resumed; guide 14 covers them.

Owned by other guides. Leave these alone here:
- Two tools with the same name, and renaming an MCP tool with `with()`: guide 11.
- The `ToolOutput` that `invokeTool()` returns, and code that reads MCP tool results: guide 49.
- stdio `command` and `env`: guides 47 and 48. HTTP clients for MCP transports: guide 51.

## What to Search For

Run these from the application root.

1. Every MCP connector and every `CallableMcpTool` reference (all cases):

   ```bash
   grep -rnE 'McpConnector|CallableMcpTool' --include='*.php' --exclude-dir=vendor .
   ```

   If nothing is found, this guide does not apply.

2. The methods this guide migrates in `McpConnector` subclasses (Cases 1 to 4):

   ```bash
   grep -rlE 'extends +[A-Za-z0-9_\\]*McpConnector([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor . | xargs -r grep -nHE 'function +(__construct|__unserialize|createTool|createToolProperty|createArrayProperty|createObjectProperty) *[(]'
   ```

   Search 1 also lists the subclasses. For each subclass, grep for `extends <SubclassName>` to find app classes that extend it, and check their methods too. `createArrayProperty()` and `createObjectProperty()` in `ObjectProperty` subclasses belong to guide 5. Search 2 only reads `McpConnector` subclass files, so it does not list them.

3. Case 5 applies to every connector the app builds. Search 1 lists `McpConnector::make(` and `new McpConnector(`. For each subclass, also find where it is built, with its name in place of `GithubConnector`:

   ```bash
   grep -rnE 'GithubConnector::make *[(]|new +GithubConnector *[(]' --include='*.php' --exclude-dir=vendor .
   ```

## How to Refactor

Apply Cases 1 to 4 to the code the searches found. Then apply Case 5 to every connector.

### Case 1: A subclass constructor that does not call the parent constructor

Before (3.x):

```php
use NeuronAI\MCP\McpConnector;

class GithubConnector extends McpConnector
{
    public function __construct(string $token)
    {
        $this->config = [
            'url' => 'https://api.githubcopilot.com/mcp/',
            'token' => $token,
        ];
    }
}
```

After (4.x):

```php
use NeuronAI\MCP\McpConnector;

class GithubConnector extends McpConnector
{
    public function __construct(string $token)
    {
        parent::__construct([
            'url' => 'https://api.githubcopilot.com/mcp/',
            'token' => $token,
        ]);
    }
}
```

Without the parent call, the first `tools()` call throws `Error: Typed property NeuronAI\MCP\McpConnector::$httpClient must not be accessed before initialization`. A `__unserialize()` override causes the same error after a paused run resumes. Make it call `parent::__unserialize($data)`.

### Case 2: A `createTool()` override that builds the tool with `CallableMcpTool`

Guide 3 left these overrides for this guide.

Before (3.x):

```php
use NeuronAI\MCP\CallableMcpTool;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;

class GithubConnector extends McpConnector
{
    protected function createTool(array $item): ToolInterface
    {
        $tool = Tool::make(
            name: $item['name'],
            description: '[GitHub] ' . ($item['description'] ?? ''),
            annotations: $item['annotations'] ?? [],
        )->setCallable(new CallableMcpTool(connector: $this, item: $item));

        foreach ($item['inputSchema']['properties'] ?? [] as $name => $prop) {
            $required = in_array($name, $item['inputSchema']['required'] ?? []);
            $type = PropertyType::fromSchema($prop['type'] ?? PropertyType::STRING->value);

            $tool->addProperty(match ($type) {
                PropertyType::ARRAY => $this->createArrayProperty($name, $required, $prop),
                PropertyType::OBJECT => $this->createObjectProperty($name, $required, $prop),
                default => $this->createToolProperty($name, $type, $required, $prop),
            });
        }

        return $tool;
    }
}
```

After (4.x):

```php
use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\ToolInterface;

class GithubConnector extends McpConnector
{
    protected function createTool(array $item): ToolInterface
    {
        $tool = parent::createTool($item);

        return $tool->setDescription('[GitHub] ' . $tool->getDescription());
    }
}
```

1. The 3.x `McpConnector::createTool()` body is the Before without the `'[GitHub] ' .` prefix. Compare the override with it. Replace it with `parent::createTool($item)`. Keep only what the override changed, as calls on the returned tool (`setName()`, `setDescription()`, `setMaxRuns()`, ...). If it changed nothing, delete the override.
2. If the override changed the schema before converting it (dropped a property, added a description, changed a type), rewrite `$item['inputSchema']` before `parent::createTool($item)`, as in the Case 4 After.
3. Do not rebuild the tool by hand with `new McpTool(...)`. `parent::createTool()` also applies the `with()` callbacks, and guide 11 may have added some.
4. If the override passed a `CallableMcpTool` subclass, apply Case 3a as well.
5. Remove the imports the override no longer uses (`CallableMcpTool`, `Tool`, `PropertyType`).

### Case 3: Other references to `CallableMcpTool`

**3a. A `CallableMcpTool` subclass.** Move its `__invoke()` logic into an `invokeTool()` override on the connector subclass whose `createTool()` built it. Then delete the `CallableMcpTool` subclass, and reduce or delete that `createTool()` override (Case 2).

Before (3.x):

```php
use NeuronAI\MCP\CallableMcpTool;

class LoggedMcpCall extends CallableMcpTool
{
    public function __invoke(...$arguments): mixed
    {
        error_log("MCP call {$this->item['name']}: " . json_encode($arguments));

        return parent::__invoke(...$arguments);
    }
}
```

After (4.x):

```php
use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\ToolOutput;

class DocsConnector extends McpConnector
{
    public function invokeTool(array $item, array $arguments): ToolOutput
    {
        error_log("MCP call {$item['name']}: " . json_encode($arguments));

        return parent::invokeTool($item, $arguments);
    }
}
```

`$this->item` becomes `$item`, and `$this->connector` becomes `$this`. Every tool of the connector calls `invokeTool()`. If the wrapper was used for some tools only, check `$item['name']`. If the connector already overrides `invokeTool()` (guide 49), merge the logic into that override. The result is a `ToolOutput` (guide 49).

**3b. An MCP tool built by hand.** Build a `McpTool` and keep the name, description and properties the app gave it. `item` must hold the server's tool name under `'name'`.

Before (3.x):

```php
use NeuronAI\MCP\CallableMcpTool;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

$connector = McpConnector::make($config);

$searchTool = Tool::make(
    name: 'search_docs',
    description: 'Search the product documentation.',
)->addProperty(
    new ToolProperty(name: 'query', type: PropertyType::STRING, description: 'Search terms', required: true)
)->setCallable(new CallableMcpTool(connector: $connector, item: ['name' => 'search']));
```

After (4.x):

```php
use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\McpTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

$connector = McpConnector::make($config);

$searchTool = (new McpTool(
    name: 'search_docs',
    description: 'Search the product documentation.',
    annotations: [],
    connector: $connector,
    item: ['name' => 'search'],
))->addProperty(
    new ToolProperty(name: 'query', type: PropertyType::STRING, description: 'Search terms', required: true)
);
```

Guide 3 may already have turned such a tool into a `Tool` subclass that still uses `CallableMcpTool`. If so, replace that class and every place that builds it with the `new McpTool(...)` above.

**3c. `instanceof CallableMcpTool`.** Replace it with `instanceof \NeuronAI\MCP\McpTool`, checked on the tool itself.

### Case 4: Overrides of the removed property hooks

4.x never calls `createToolProperty()`, `createArrayProperty()` or `createObjectProperty()`, and `parent::create*Property()` is a fatal error.

Before (3.x):

```php
use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

class DocsConnector extends McpConnector
{
    protected function createToolProperty(string $name, PropertyType $type, bool $required, array $prop): ToolProperty
    {
        return new ToolProperty(
            name: $name,
            type: $type,
            description: $prop['description'] ?? $prop['title'] ?? null,
            required: $required,
            enum: $prop['items']['enum'] ?? $prop['enum'] ?? [],
        );
    }
}
```

After (4.x):

```php
use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\ToolInterface;

class DocsConnector extends McpConnector
{
    protected function createTool(array $item): ToolInterface
    {
        foreach ($item['inputSchema']['properties'] ?? [] as $name => $schema) {
            if (!isset($schema['description']) && isset($schema['title'])) {
                $item['inputSchema']['properties'][$name]['description'] = $schema['title'];
            }
        }

        return parent::createTool($item);
    }
}
```

1. Delete each `create*Property()` override.
2. If an override only filled in nested object properties or array item types, nothing replaces it. 4.x converts them.
3. Move any other adjustment into a `createTool()` override. It rewrites `$item['inputSchema']` in JSON Schema terms, then calls `parent::createTool($item)`. If the class already has one, merge the rewrite into it. The 3.x hook received one property at a time. The rewrite loops over `$item['inputSchema']['properties']`.
4. For a change to one tool after conversion (name, description, approval), use `->with('server_tool_name', fn (ToolInterface $tool): ToolInterface => ...)` where the connector is built. The key is the name the server lists.

### Case 5: A server tool whose schema 4.x refuses (every app that uses MCP)

1. List the tools each connector cannot convert. For each connector found in search 3, run this with the exact config array the app passes to the connector (and the same HTTP client as a second `McpClient` argument, if the connector receives one). Run it in the app's context (tinker, a console command or a test) so config and env values resolve. Delete it afterwards.

   ```php
   use NeuronAI\Exceptions\ArrayPropertyException;
   use NeuronAI\Exceptions\ToolException;
   use NeuronAI\MCP\McpClient;
   use NeuronAI\Tools\ToolPropertyFactory;

   $config = [
       'command' => 'uvx',
       'args' => ['reports-mcp-server'],
   ];

   foreach ((new McpClient($config))->listTools() as $definition) {
       try {
           ToolPropertyFactory::fromSchema($definition['inputSchema'] ?? []);
       } catch (ToolException|ArrayPropertyException $e) {
           echo $definition['name'] . ': ' . $e->getMessage() . PHP_EOL;
       }
   }
   ```

   Ignore tools the connector already filters out with `only()` or `exclude()`. If you cannot reach the server (credentials, network, a binary missing locally), ask the developer to run the snippet and share the output. Do not guess. The messages are:
   - `JSON Schema keyword '<anyOf|oneOf|allOf|$ref|prefixItems>' cannot be represented by the tool property types.`
   - `Property '<name>' must declare one non-null type.` (for example `"type": ["string", "number"]`)
   - `Unsupported type '<type>' for property '<name>'.`
   - `Property '<name>' must declare its type as a string, <type> given.`
   - `Property '<name>' must be defined by a schema object, <type> given.`
   - `Keyword '<keyword>' of <owner> must be of type <type>, <type> given.`
   - `Tuple schemas cannot be represented by a single array item property.`

   Array items are reported as the property `<name>_item`.

2. Decide per reported tool. To see the offending schema, print `$definition['inputSchema']` for that tool.
   - If every failure is a top-level property with `anyOf` of exactly one type plus `{"type": "null"}`, use Option B. This is the shape Pydantic gives `Optional[...]` parameters. You do not need to ask.
   - For anything else (`$ref`, `oneOf`, `allOf`, a union of two real types, a nested nullable property), ask the developer whether the agent needs the tool. If it does not, use Option A. If it does, ask how the property should be described. Add that rewrite to the Option B `createTool()` override, and report it: it changes what the model is told.
3. Build each connector as the app does and call `->tools()`. It must return without an exception.

#### Option A: exclude the tool

The model loses the tool. Filtering runs before conversion, so an excluded tool no longer throws.

Before (3.x):

```php
use NeuronAI\MCP\McpConnector;

protected function tools(): array
{
    return [
        ...McpConnector::make([
            'command' => 'uvx',
            'args' => ['reports-mcp-server'],
        ])->tools(),
    ];
}
```

After (4.x):

```php
use NeuronAI\MCP\McpConnector;

protected function tools(): array
{
    return [
        ...McpConnector::make([
            'command' => 'uvx',
            'args' => ['reports-mcp-server'],
        ])->exclude(['create_report'])->tools(),
    ];
}
```

A second `exclude()` or `only()` call replaces the list set by the first. If the connector already has an `exclude([...])`, add the name to that list. If it has an `only([...])`, remove the name from it. If that name was the only entry, do not leave `only([])`: an empty list removes the filter and exposes every tool the server lists. Ask the developer whether to drop the connector from `tools()` instead.

#### Option B: collapse nullable `anyOf` in a connector subclass

Put this class in the app's namespace. If the app already has a `McpConnector` subclass for that server, merge the loop and the method into its `createTool()` instead.

```php
namespace App\Neuron;

use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\ToolInterface;

class AppMcpConnector extends McpConnector
{
    protected function createTool(array $item): ToolInterface
    {
        foreach ($item['inputSchema']['properties'] ?? [] as $name => $schema) {
            $item['inputSchema']['properties'][$name] = $this->collapseNullableAnyOf($schema);
        }

        return parent::createTool($item);
    }

    /**
     * {"anyOf": [{"type": "integer", ...}, {"type": "null"}]} becomes {"type": ["integer", "null"], ...}.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function collapseNullableAnyOf(array $schema): array
    {
        $branches = $schema['anyOf'] ?? [];
        $typed = array_values(array_filter($branches, fn (array $branch): bool => ($branch['type'] ?? null) !== 'null'));

        if (count($branches) !== 2 || count($typed) !== 1 || !is_string($typed[0]['type'] ?? null)) {
            return $schema;
        }

        unset($schema['anyOf']);

        return ['type' => [$typed[0]['type'], 'null']] + $schema + $typed[0];
    }
}
```

Then build the affected connectors with it:

```php
use App\Neuron\AppMcpConnector;

protected function tools(): array
{
    return [
        ...AppMcpConnector::make([
            'command' => 'uvx',
            'args' => ['reports-mcp-server'],
        ])->tools(),
    ];
}
```

The property keeps the keywords of its non-null branch (`items`, `enum`), and the outer ones such as `description` win. The model may now send `null`. `McpClient` does not send null arguments, so the server applies its default, as when the argument is omitted.

## Checklist

- `grep -rn 'CallableMcpTool' --include='*.php' --exclude-dir=vendor .` finds nothing.
- No `McpConnector` subclass declares `createToolProperty()`, `createArrayProperty()` or `createObjectProperty()`.
- Every `McpConnector` subclass constructor calls `parent::__construct(...)`, and every `__unserialize()` override calls `parent::__unserialize($data)`.
- No `createTool()` override uses `Tool::make()`. Each one returns `parent::createTool($item)`, possibly after rewriting `$item['inputSchema']` or adjusting the returned tool.
- Every tool the Case 5 snippet printed is filtered out by the connector or fixed by its `createTool()` rewrite. Calling `->tools()` on each connector, built exactly as the app builds it, returns without an exception.
- The step report lists every excluded tool and every schema rewrite. The developer agreed to each exclusion and to each rewrite other than the nullable `anyOf` collapse.
- Static analysis reports no error about `McpConnector`, `McpTool`, `CallableMcpTool` or the removed hooks.
