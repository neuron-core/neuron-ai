# Upgrade: Tools are classes: Tool is abstract and ToolInterface changed

## Summary

`NeuronAI\Tools\Tool` is now abstract and has no constructor. A tool is either a class that extends `Tool` or a class that implements `ToolInterface` directly. A `Tool` subclass declares its identity as properties, its inputs in `properties()`, and its logic in `public function __invoke(...)`.

| 3.x | 4.x |
|---|---|
| `Tool::make($name, $description, $properties, $parameters, $annotations)` / `new Tool(...)` | a class extending `Tool` with `protected string $name`, `protected ?string $description`, `protected function properties(): array`, `protected array $parameters`, `protected array $annotations` |
| `->setCallable($callable)` | `public function __invoke(...)` on the tool class |
| `parent::__construct($name, $description, ...)` in a `Tool` subclass | removed: property defaults, or `$this->name = ...` in the subclass constructor |
| `->setMaxTries($n)` | `->setMaxRuns($n)` |
| `implements HasRunKey` | interface removed: `getRunKey()` is part of `ToolInterface` |
| `ToolInterface` | adds `getInputSchema()`, `hasResult()`, `setResult()`, `getRunKey()` and `requiresApproval()`; drops `setCallable()` |
| — | `Tool` declares new members whose names app subclasses can no longer use freely (Case 10) |

This guide changes no stored data. Keep every tool name and description exactly as it was in 3.x. The model sees them, chat histories record tool names, and names must stay unique (guide 11).

These belong to other guides. Leave them untouched here and list them in your report:
- A `Tool::make(...)` or `(clone $tool)` that only builds an entry for a hand-built `ToolCallMessage`/`ToolResultMessage` is migrated by guide 4. Such an entry is stamped with `setCallId()`/`setInputs()`/`setResult()` and is never registered on an agent. Until guide 4 runs, tests that use these entries fail. A `Tool::make(...)` entry fails with `Error: Cannot instantiate abstract class NeuronAI\Tools\Tool`. A `(clone $tool)` entry fails with `TypeError: ... must be of type NeuronAI\Tools\ToolCall, <YourTool> given`. Those failures are expected.
- Every `->setCallable(new CallableMcpTool(...))`, whether in a `McpConnector::createTool()` override or on a hand-built MCP tool, together with the `Tool::make(...)` it is chained to: guide 50. `CallableMcpTool` does not exist in 4.x, so do not wrap it in a tool class.
- `setCallable(new ToolRejectionHandler(...))`: guide 28.
- Subclasses of Calculator tools: guide 8. Subclasses of `NeuronAI\Agent\Middleware\WriteTodosTool`: guide 10. `getClient()` overrides in Tavily/Jina/Zep/Supadata tool subclasses: guide 6. Case 5 of this guide still applies to the constructors of those web tools.
- Deciding which tools require approval: guide 28. Property validation, casting and `ToolPropertyInterface`: guide 5. `Agent::toolMaxTries()`: guide 25.

## What to Search For

Run these from the application root.

1. Base `Tool` instances (Cases 1 and 2):

   ```bash
   grep -rnE '(^|[^A-Za-z0-9_])Tool::make[(]|new +(.?NeuronAI.Tools.)?Tool *[(]' --include='*.php' --exclude-dir=vendor .
   grep -rnE 'NeuronAI.Tools.Tool +as +' --include='*.php' --exclude-dir=vendor .
   ```

   The second command lists aliased imports. For each alias it prints, run the first command again with the alias in place of `Tool`. The pattern does not match `SumTool::make()`, `MySQLSelectTool::make($pdo)` or other concrete tool classes, because those are not base instances.

2. Callables (Cases 1, 2 and 6):

   ```bash
   grep -rnE -e '->setCallable[(]' -e '[$]this->callback([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor .
   ```

3. Every tool class (Cases 3, 4 and 5):

   ```bash
   grep -rnE 'extends +[A-Za-z0-9_\\]*(Tool|JinaWebSearch|JinaUrlReader)([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor .
   ```

   Ignore `extends ProviderTool` hits. Also ignore `extends CallableMcpTool` hits, which belong to guide 50. For each remaining hit, use the class's `use` imports to find where its parent comes from. The parent is one of three things:
   - `NeuronAI\Tools\Tool`;
   - an app class: follow it up to `Tool`, and grep for that class's own subclasses the same way;
   - a built-in Neuron tool.

   In each class, look for `parent::__construct(`, for a missing `__construct`, and for a missing `__invoke`.

4. Constructor-less tool classes built with arguments (Case 4). Do this after Case 3. For each class from step 3 where no class between it and `Tool` declares `__construct`, run this command with the class name in place of `SearchTool`:

   ```bash
   grep -rnE '(new +([A-Za-z0-9_\\]*\\)?SearchTool|(^|[^A-Za-z0-9_])SearchTool::make) *[(] *([^ )]|$)' --include='*.php' --exclude-dir=vendor .
   ```

   A hit whose line ends with `(` has its arguments on the following lines: open it to check.

   Anonymous tool classes built with arguments:

   ```bash
   grep -rnE 'new +class *[(] *[^ )].*extends +[A-Za-z0-9_\\]*Tool([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor .
   ```

5. Removed alias (Case 7):

   ```bash
   grep -rnE 'setMaxTries[(]' --include='*.php' --exclude-dir=vendor .
   ```

6. Direct `ToolInterface` implementers (Case 9):

   ```bash
   grep -rnE '(implements|extends)[^{]*[^A-Za-z0-9_]ToolInterface([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor .
   ```

   A class that also extends `Tool` needs no change. If an app interface extends `ToolInterface`, grep for its implementers the same way.

7. Run keys (Case 8):

   ```bash
   grep -rnE 'HasRunKey|TrackByInputs|function +getRunKey *[(]' --include='*.php' --exclude-dir=vendor .
   ```

8. Reserved member names (Case 10):

   ```bash
   grep -rnE 'function +(getInputSchema|hasResult|requiresApproval|approvalPolicy|requireApproval|suppressApproval|withApprovalPolicy|invokeParameters) *[(]|(public|protected|private|var)[^;=(]*[$](invalidInput|approvalRequired|approvalPolicyOverride|result) *[;=,)]' --include='*.php' --exclude-dir=vendor .
   ```

   Only hits inside classes that extend `Tool` (step 3) matter.

If none of these searches finds anything, this guide does not apply.

## How to Refactor

### Case 1: A tool built inline with `Tool::make(...)` or `new Tool(...)`

Before (3.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class YouTubeAgent extends Agent
{
    // ...

    protected function tools(): array
    {
        return [
            Tool::make(
                'get_transcription',
                'Retrieve the transcription of a YouTube video.',
            )->addProperty(
                new ToolProperty(
                    name: 'video_url',
                    type: PropertyType::STRING,
                    description: 'The URL of the YouTube video.',
                    required: true,
                )
            )->setCallable(function (string $video_url): string {
                return $this->transcriber->transcribe($video_url);
            })->setMaxRuns(3),
        ];
    }
}
```

After (4.x): a new class, for example `app/Neuron/Tools/GetTranscriptionTool.php`:

```php
namespace App\Neuron\Tools;

use App\Services\TranscriptionService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetTranscriptionTool extends Tool
{
    protected string $name = 'get_transcription';

    protected ?string $description = 'Retrieve the transcription of a YouTube video.';

    public function __construct(protected TranscriptionService $transcriber)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'video_url',
                type: PropertyType::STRING,
                description: 'The URL of the YouTube video.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $video_url): string
    {
        return $this->transcriber->transcribe($video_url);
    }
}
```

The agent then registers the new class:

```php
use App\Neuron\Tools\GetTranscriptionTool;

protected function tools(): array
{
    return [
        GetTranscriptionTool::make($this->transcriber)->setMaxRuns(3),
    ];
}
```

Steps:
1. Create one class per tool. Put it in the app's existing tools namespace and directory, follow that code's style, and name it after the tool (`get_transcription` becomes `GetTranscriptionTool`). In a test, an anonymous class `new class extends Tool { ... }` is fine.
2. Copy the name (1st argument) into `protected string $name` and the description (2nd argument) into `protected ?string $description`, character for character.
3. Move every `addProperty(...)` argument, and the 3rd argument (`properties:`), into `protected function properties(): array`.
4. Move the 4th argument (`parameters:`) to `protected array $parameters = [...];`, or keep a chained `->setParameters([...])`. Move the 5th argument (`annotations:`) to `protected array $annotations = [...];`. Property defaults cannot contain `new`, so assign such values in a constructor.
5. Turn the callable into `public function __invoke(...)`. Keep the same parameter names, types, defaults, return type and body. The parameter names must equal the property names, as they already had to in 3.x. If the callable is not a closure (`[$service, 'method']`, an invokable object or a function name), have `__invoke()` call it with the same arguments.
6. Turn the values the closure captured (`$this->...`, `use (...)`) into constructor parameters of the tool, and pass them at registration. The constructor must not call `parent::__construct()`. If the body calls private or protected methods of the class that built the tool, move that logic into the tool, or inject the collaborator the logic uses.
7. Replace the inline expression with the new class. Keep every other chained call (`->setMaxRuns(3)`, `->visible(false)`, `->setParameters([...])`). A chained `->setMaxTries(` becomes `->setMaxRuns(` (Case 7).
8. The same tool may also be cloned into a hand-built `ToolCallMessage` (`(clone $tool)->setCallId(...)`). Convert the tool, and leave the clone line for guide 4.

### Case 2: Tools generated at runtime

Use this case when names or descriptions come from configuration, a loop or an API spec. Write one generic class and build every tool from it. Tools with fixed names get their own class (Case 1).

Before (3.x):

```php
foreach ($this->endpoints as $endpoint) {
    $tools[] = Tool::make($endpoint['name'], $endpoint['description'])
        ->addProperty(new ToolProperty('query', PropertyType::STRING, 'The search query.', true))
        ->setCallable(fn (string $query): string => $this->gateway->call($endpoint['name'], $query));
}
```

After (4.x): a generic class, written once:

```php
namespace App\Neuron\Tools;

use Closure;
use NeuronAI\Tools\Tool;

class CallbackTool extends Tool
{
    protected Closure $handler;

    public function __construct(string $name, ?string $description, callable $handler)
    {
        $this->name = $name;
        $this->description = $description;
        $this->handler = $handler(...);
    }

    public function __invoke(mixed ...$arguments): mixed
    {
        return ($this->handler)(...$arguments);
    }
}
```

The loop then builds each tool from it:

```php
use App\Neuron\Tools\CallbackTool;

foreach ($this->endpoints as $endpoint) {
    $tools[] = CallbackTool::make(
        $endpoint['name'],
        $endpoint['description'],
        fn (string $query): string => $this->gateway->call($endpoint['name'], $query),
    )->addProperty(new ToolProperty('query', PropertyType::STRING, 'The search query.', true));
}
```

The callable receives the declared properties as named arguments, as it did in 3.x. Keep the existing `addProperty()` calls. If properties were passed as the 3rd `make()` argument, turn them into `addProperty()` calls.

### Case 3: A `Tool` subclass calling `parent::__construct(...)`

Only a call that reaches `NeuronAI\Tools\Tool` breaks, with `Error: Cannot call constructor`. A call into an app base class that still declares a constructor stays as it is.

Before (3.x):

```php
use NeuronAI\Tools\Tool;

class GetTranscriptionTool extends Tool
{
    public function __construct(protected string $key)
    {
        parent::__construct(
            'get_transcription',
            'Retrieve the transcription of a YouTube video.',
        );
    }

    // properties() and __invoke() unchanged
}

class WeatherTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'get_weather',
            description: 'Get the current weather for a location.',
            parameters: ['strict' => true],
        );
    }

    // ...
}

abstract class ApiTool extends Tool
{
    public function __construct(string $name, string $description, protected ApiClient $client)
    {
        parent::__construct($name, $description);
    }
}
```

After (4.x):

```php
use NeuronAI\Tools\Tool;

class GetTranscriptionTool extends Tool
{
    protected string $name = 'get_transcription';

    protected ?string $description = 'Retrieve the transcription of a YouTube video.';

    public function __construct(protected string $key)
    {
    }

    // properties() and __invoke() unchanged
}

class WeatherTool extends Tool
{
    protected string $name = 'get_weather';

    protected ?string $description = 'Get the current weather for a location.';

    protected array $parameters = ['strict' => true];

    // ...
}

abstract class ApiTool extends Tool
{
    public function __construct(string $name, string $description, protected ApiClient $client)
    {
        $this->name = $name;
        $this->description = $description;
    }
}
```

The children of `ApiTool` keep their `parent::__construct('get_forecast', '...', $client)` calls, because `ApiTool` still has a constructor.

Steps:
1. Delete the call.
2. Literal name and description values, whether positional or passed as `name:`/`description:`, become property defaults.
3. Values that arrive as constructor arguments become `$this->name = $name;` and `$this->description = $description;`.
4. `properties:` moves to `properties()`. `parameters:` and `annotations:` become property defaults, or assignments to `$this->parameters` / `$this->annotations`.
5. If the constructor is now empty and has no parameters, delete it.

### Case 4: A tool class without a constructor, instantiated with arguments

In 4.x, PHP silently ignores the arguments. The first `getName()` then throws `Typed property NeuronAI\Tools\Tool::$name must not be accessed before initialization`, or the class's declared default wins over the value passed.

- Every call site passes the same identity: move it into property defaults, as in Case 3, and remove the arguments from the call sites.
- Call sites pass different identities: add a constructor that assigns them. The call sites stay unchanged:

Before (3.x):

```php
class SearchTool extends Tool
{
    protected function properties(): array
    {
        return [new ToolProperty('query', PropertyType::STRING, 'The search query.', true)];
    }

    public function __invoke(string $query): string
    {
        // ...
    }
}

$docs = new SearchTool('search_docs', 'Search the product documentation.');
$blog = SearchTool::make('search_blog', 'Search the blog.');
```

After (4.x):

```php
class SearchTool extends Tool
{
    public function __construct(string $name, ?string $description = null)
    {
        $this->name = $name;
        $this->description = $description;
    }

    // properties() and __invoke() unchanged
}
```

Anonymous classes built with arguments (`new class('search', '...') extends Tool { ... }`) follow the same rules.

### Case 5: A subclass of a built-in Neuron tool

Keep `parent::__construct(...)` with the same arguments when the 4.x parent declares a constructor. Delete the call when the parent has none.

| 3.x parent | 4.x |
|---|---|
| Calendar tools (`NeuronAI\Tools\Toolkits\Calendar\*Tool`) | no constructor: delete the call, and delete the constructor if it becomes empty |
| FileSystem tools (`NeuronAI\Tools\Toolkits\FileSystem\*Tool`) | `FileSystemTool::__construct(?string $scope = null)`: `parent::__construct()` stays |
| `SESTool`, `RetrievalTool`, MySQL and PGSQL tools, Tavily, Jina, Zep and Supadata tools | the same dependency arguments are accepted: keep the call |
| Calculator tools | guide 8 |
| `NeuronAI\Agent\Middleware\WriteTodosTool` | guide 10 |

For any other parent, open it under `vendor/neuron-core/neuron-ai/src` and check whether any class between it and `Tool` declares a constructor.

Before (3.x):

```php
use NeuronAI\Tools\Toolkits\Calendar\CurrentDateTimeTool;
use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;
use PDO;

class OfficeClockTool extends CurrentDateTimeTool
{
    public function __construct()
    {
        parent::__construct();
        $this->setName('office_datetime');
    }
}

class ReportingSelectTool extends MySQLSelectTool
{
    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
        $this->setName('reporting_select_query');
    }
}
```

After (4.x):

```php
use NeuronAI\Tools\Toolkits\Calendar\CurrentDateTimeTool;
use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;
use PDO;

class OfficeClockTool extends CurrentDateTimeTool
{
    public function __construct()
    {
        $this->setName('office_datetime');
    }
}

class ReportingSelectTool extends MySQLSelectTool
{
    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
        $this->setName('reporting_select_query');
    }
}
```

### Case 6: `setCallable()` on a custom subclass, or `$this->callback`

Before (3.x):

```php
class LookupOrderTool extends Tool
{
    public function __construct(protected OrderRepository $orders)
    {
        parent::__construct('lookup_order', 'Find an order by its number.');

        $this->setCallable([$this, 'lookup']);
    }

    protected function properties(): array
    {
        return [new ToolProperty('number', PropertyType::STRING, 'The order number.', true)];
    }

    public function lookup(string $number): array
    {
        return $this->orders->find($number);
    }
}
```

After (4.x):

```php
class LookupOrderTool extends Tool
{
    protected string $name = 'lookup_order';

    protected ?string $description = 'Find an order by its number.';

    public function __construct(protected OrderRepository $orders)
    {
    }

    protected function properties(): array
    {
        return [new ToolProperty('number', PropertyType::STRING, 'The order number.', true)];
    }

    public function __invoke(string $number): array
    {
        return $this->orders->find($number);
    }
}
```

1. Rename the target method to `__invoke`. If other code calls it by its old name, keep that method and have `__invoke()` call it.
2. Delete the `setCallable(...)` or `$this->callback = ...` line, then apply Case 3 to the constructor.
3. If code outside the class calls `->setCallable(...)` on an instance of the subclass:
   - The class has no `__invoke()`: move that callable into the class's `__invoke()` using the Case 1 rules. If different call sites pass different callables, give the class a callable constructor parameter, as in Case 2.
   - The class already defines `__invoke()`: in 3.x the callable replaced it for that instance only, usually as a test stub. Leave the class unchanged and, at that call site, build an anonymous subclass that overrides `__invoke()` with the callable's body, for example `new class($apiKey) extends WeatherTool { public function __invoke(string $location): string { return 'sunny'; } }`. If the call site is not a test, ask the developer whether the override is still wanted.

If a tool has no `__invoke()`, 4.x throws `ToolCallableNotSet: Tool "lookup_order" must implement __invoke() to define its execution logic.` when the tool runs.

### Case 7: `setMaxTries()` is removed

```php
// Before (3.x)
PingTool::make()->setMaxTries(5);

// After (4.x)
PingTool::make()->setMaxRuns(5);
```

The argument is unchanged.

### Case 8: `HasRunKey` is removed

Before (3.x):

```php
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\TrackByInputs;

class ReadPageTool extends Tool implements HasRunKey
{
    use TrackByInputs;

    // ...
}
```

After (4.x):

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\TrackByInputs;

class ReadPageTool extends Tool
{
    use TrackByInputs;

    // ...
}
```

1. Remove `HasRunKey` from every `implements` list, and delete the `use NeuronAI\Tools\HasRunKey;` import. Keep the class's `getRunKey()` method or its `use TrackByInputs;`.
2. List the tools that declare `getRunKey()` or use `TrackByInputs` without implementing `HasRunKey`. 3.x ignored their key, but 4.x counts run limits by it. Ask the developer whether the key should now apply (keep it) or per-name counting was intended (delete the method or trait).

### Case 9: A class that implements `ToolInterface` directly

Add the five methods below. Adapt `hasResult()` and `setResult()` to the property that the class's `getResult()` already reads. An existing `getResult(): string` stays valid. `setCallable()` is no longer part of the interface: delete it, and its `$callback` property, unless app code calls it.

```php
use NeuronAI\Tools\ToolInterface;
use stdClass;

class StockPriceTool implements ToolInterface
{
    // ... the existing 3.x methods, without setCallable() ...

    public function getInputSchema(): array
    {
        $properties = [];
        foreach ($this->getProperties() as $property) {
            $properties[$property->getName()] = $property->getJsonSchema();
        }

        return [
            'type' => 'object',
            'properties' => $properties === [] ? new stdClass() : $properties,
            'required' => $this->getRequiredProperties(),
        ];
    }

    public function hasResult(): bool
    {
        return $this->result !== null;
    }

    public function setResult(mixed $result): ToolInterface
    {
        $this->result = is_array($result) ? json_encode($result, JSON_THROW_ON_ERROR) : (string) $result;
        return $this;
    }

    public function getRunKey(): string
    {
        return $this->getName();
    }

    public function requiresApproval(): bool|string
    {
        return false;
    }
}
```

Every provider now sends `getInputSchema()` as the tool's JSON schema. The body above builds the same schema that 3.x built from `getProperties()`. Returning `false` from `requiresApproval()` keeps the 3.x behaviour; guide 28 decides whether the tool must be gated. If the class returned a custom key through `HasRunKey`, keep that `getRunKey()` instead (Case 8).

### Case 10: Members that now collide with `Tool`

`Tool` now declares these members:

| Visibility | Members |
|---|---|
| public | `getInputSchema(): array`, `hasResult(): bool`, `getRunKey(): string`, `requiresApproval(): bool\|string`, `requireApproval(bool $require = true): ToolInterface`, `suppressApproval(): ToolInterface`, `withApprovalPolicy(callable $policy): ToolInterface` |
| protected | `approvalPolicy(): bool\|string`, `invokeParameters(): array`, `?string $invalidInput`, `?bool $approvalRequired`, `?Closure $approvalPolicyOverride` |
| protected, type changed | `string\|ToolOutput\|null $result` (3.x: `?string`) |

If a `Tool` subclass declares a member with one of these names, it either fails at class load (incompatible signature or property type) or silently overrides framework behaviour. Rename the app's member and update its call sites. There are two exceptions:
- a `getRunKey()` kept by Case 8;
- a redeclared `$result` that only repeated `Tool`'s declaration: delete it.

Sometimes the app's member already means the same thing as the 4.x member, for example a `requiresApproval()` method that the app's own `ToolApproval` subclass consulted. Ask the developer before renaming it. Guide 28 migrates approval configuration.

Before (3.x):

```php
class TransferFundsTool extends Tool
{
    // ...

    protected function approvalPolicy(): array
    {
        return ['max_amount' => 1000.0];
    }

    public function __invoke(float $amount): string
    {
        return $amount > $this->approvalPolicy()['max_amount'] ? 'refused' : 'done';
    }
}
```

After (4.x):

```php
class TransferFundsTool extends Tool
{
    // ...

    protected function transferLimits(): array
    {
        return ['max_amount' => 1000.0];
    }

    public function __invoke(float $amount): string
    {
        return $amount > $this->transferLimits()['max_amount'] ? 'refused' : 'done';
    }
}
```

## Checklist

- Searches 1, 2 and 5 find only two kinds of hits: the items this guide leaves for guides 4, 28 and 50 (listed in your report), and `setCallable()` methods kept on direct `ToolInterface` implementers because app code calls them (Case 9).
- No `parent::__construct(` reaches `NeuronAI\Tools\Tool` or a Calendar tool.
- Every tool class sets `$name`, either as a property default or in its constructor, and defines `__invoke()`.
- No tool class without a constructor is instantiated with arguments.
- Tool names and descriptions are identical to 3.x.
- No `HasRunKey` remains. Tools with `getRunKey()` or `TrackByInputs` that never implemented `HasRunKey` are reported to the developer.
- Every direct `ToolInterface` implementer has `getInputSchema()`, `hasResult()`, `setResult()`, `getRunKey()` and `requiresApproval()`.
- No `Tool` subclass declares a Case 10 member, except a kept `getRunKey()`.
- Static analysis and tests report none of the following, apart from failures caused by the items left for guides 4, 28 and 50: `Cannot instantiate abstract class NeuronAI\Tools\Tool`, `Cannot call constructor`, `Call to undefined method ...::setCallable()`, `Call to undefined method ...::setMaxTries()`, `must not be accessed before initialization`, `Interface "NeuronAI\Tools\HasRunKey" not found`, `contains ... abstract method`.
