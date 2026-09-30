# Upgrade: Agent instructions are a SystemMessage

## Summary

Agent instructions and the provider system prompt are now a `NeuronAI\Chat\Messages\SystemMessage` instead of a string.

| 3.x | 4.x |
|---|---|
| `public function resolveInstructions(): string` (also on `AgentInterface`; the Agent read the instructions through it) | Removed. Use `final public function getInstructions(): SystemMessage`, and `->getContent()` (`?string`) for the text |
| `protected function instructions(): string` | `protected function instructions(): SystemMessage\|string`. A string return still works, including `(string) new SystemPrompt(...)`. A bare `new SystemPrompt(...)` return behaves as in 3.x: it is a TypeError under `declare(strict_types=1)`. The framework's default implementation now returns a `SystemMessage` |
| `public function setInstructions(string $instructions): self` | `public function setInstructions(SystemMessage\|string $instructions): static` |
| `protected string $instructions` | `protected SystemMessage $instructions` |
| `AIProviderInterface::systemPrompt(?string $prompt)` | `systemPrompt(SystemMessage\|string\|null $prompt)`. The Agent passes a `SystemMessage` |
| `Anthropic::systemPromptBlocks(array $blocks)`, `Anthropic::withPromptCaching(bool $enabled = true)`, protected `$systemBlocks` and `$promptCachingEnabled` | Removed |
| `NeuronAI\Testing\RequestRecord::$systemPrompt` is `?string` | `?SystemMessage` |
| `ContentBlockType` cases `TEXT`, `REASONING`, `IMAGE`, `FILE`, `AUDIO`, `VIDEO` | Adds `SYSTEM`, the type of `NeuronAI\Chat\Messages\ContentBlocks\SystemContent` (which extends `TextContent`) |

The `SystemMessage` API:
- `new SystemMessage('text')` or `new SystemMessage([new SystemContent('a'), new SystemContent('b')])` creates one.
- `getContent()` joins the text blocks with a blank line, and returns `null` when there is no text.
- `contains(string $text)` checks whether any block contains the text.
- `addContent(new SystemContent('...'))` appends a block.
- `cache()` marks every block cached. `SystemContent::cache()` marks a single block.

These need no change:
- `instructions(): string` overrides that return their own text, `(string) new SystemPrompt(...)` included. The `SystemPrompt` class is unchanged.
- `setInstructions('...')` calls, `$provider->systemPrompt('...')` calls, and `$provider->assertSystemPrompt('...')`.

No stored data needs migrating for this guide. 3.x stored instructions only inside the interrupts of paused runs, which 4.x does not read (guide 14).

Other guides handle these, so do not migrate them here:
- `$this->resolveInstructions()` passed to a node constructor in an Agent or RAG subclass, such as `new InstructionsNode($this->resolveInstructions(), ...)` in a copied `ragNodes()`: guide 26.
- Middleware that edits `$event->instructions`: guide 27.
- Other reads and writes of `$this->system` in subclasses of `Anthropic`, `AnthropicVertex`, `OpenAIResponses` or `OpenAILikeResponses`: guide 43.
- Classes that implement `AgentInterface` without extending `Agent`: guide 57.
- Other `AIProviderInterface` changes: guides 41 to 43.

## What to Search For

Run from the application root:

```bash
# Case 1 and Case 5: methods named getInstructions() or setInstructions()
grep -rnE 'function (get|set)Instructions[[:space:]]*\(' --include='*.php' --exclude-dir=vendor .
# Case 2 (definitions) and Case 6 (calls)
grep -rnE 'resolveInstructions' --include='*.php' --exclude-dir=vendor .
# Case 3
grep -rnE 'parent::instructions[[:space:]]*\(' --include='*.php' --exclude-dir=vendor .
# Case 4
grep -rnE '\$this->instructions([^(A-Za-z0-9_]|$)|string[[:space:]]+\$instructions([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor .
# Case 7
grep -rnE 'function systemPrompt[[:space:]]*\(' --include='*.php' --exclude-dir=vendor .
# Case 8 and Case 9
grep -rnE 'systemPromptBlocks|withPromptCaching' --include='*.php' --exclude-dir=vendor .
# Case 10
grep -rnE '[-]>systemPrompt([^(A-Za-z0-9_]|$)|systemPrompt:' --include='*.php' --exclude-dir=vendor .
# Case 11
grep -rnE '(match|switch)[[:space:]]*\(.*getType\(\)|ContentBlockType::' --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:
- Cases 1 to 5 apply only to classes that extend `NeuronAI\Agent\Agent` or `NeuronAI\RAG\RAG`, directly or through an application base class. Ignore hits in other classes.
- Case 7 applies to classes that implement `AIProviderInterface` or extend a built-in provider.
- In the Case 10 search, act only on `RequestRecord` reads and constructions. Other `systemPrompt:` named arguments are unrelated.
- In the Case 11 search, act only on `match` or `switch` statements over a content block's `getType()`.

Apply the cases in order. Cases 1 and 2 change method definitions that Case 6 would otherwise rewrite wrongly. If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: An Agent or RAG subclass declares its own `getInstructions()`

`getInstructions()` is now a final framework method, so the subclass fails to load. Rename the app's method, for example to `reportInstructions()`, and update every caller before you apply Case 6. Case 6 introduces `getInstructions()` calls that must reach the framework method.

### Case 2: An override of `resolveInstructions()`

The Agent no longer calls `resolveInstructions()`, so the override is silently ignored. `getInstructions()` is final and cannot take its place. Move the logic into the `instructions()` hook.

Before (3.x):

```php
use NeuronAI\Agent\Agent;

class TenantAgent extends Agent
{
    public function __construct(protected string $tenantPrompt)
    {
        parent::__construct();
    }

    public function resolveInstructions(): string
    {
        return $this->tenantPrompt;
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\SystemMessage;

class TenantAgent extends Agent
{
    public function __construct(protected string $tenantPrompt)
    {
        parent::__construct();
    }

    protected function instructions(): SystemMessage|string
    {
        return $this->tenantPrompt;
    }
}
```

1. Move the body into `protected function instructions(): SystemMessage|string`, and delete `resolveInstructions()`.
2. Replace `parent::resolveInstructions()` in the moved body with `parent::instructions()`, then apply Case 3.
3. If the class already overrides `instructions()`, merge the two methods into one. In 3.x, `parent::resolveInstructions()` returned the result of that `instructions()` override.
4. A value passed to `setInstructions()` replaces the hook, so the moved logic does not run for it. If the app calls `setInstructions()` on this agent, apply the same logic where `setInstructions()` is called.

### Case 3: `parent::instructions()` used as a string

The framework's default `instructions()` now returns a `SystemMessage`, which cannot be concatenated. The code fails with "Object of class SystemMessage could not be converted to string".

Before (3.x):

```php
use NeuronAI\Agent\Agent;

class FrenchAgent extends Agent
{
    protected function instructions(): string
    {
        return parent::instructions() . "\nAnswer in French.";
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\SystemMessage;

class FrenchAgent extends Agent
{
    protected function instructions(): SystemMessage|string
    {
        $instructions = parent::instructions();
        $instructions = is_string($instructions) ? new SystemMessage($instructions) : $instructions;
        $instructions->addContent(new SystemContent('Answer in French.'));

        return $instructions;
    }
}
```

If the parent is an application class whose `instructions()` declares `: string`, the concatenation still works. Leave it unchanged.

### Case 4: The `$instructions` property

A redeclared `protected string $instructions` is a fatal error ("Type of ...::$instructions must be NeuronAI\Chat\Messages\SystemMessage"). Assigning a string to the property is a TypeError.

Before (3.x):

```php
use NeuronAI\Agent\Agent;

class SupportAgent extends Agent
{
    protected string $instructions = 'You are a support agent.';
}

class ContextAgent extends Agent
{
    public function withContext(string $context): void
    {
        $this->instructions = $context;
    }

    public function describe(): string
    {
        return $this->instructions;
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Agent;

class SupportAgent extends Agent
{
    protected function instructions(): string
    {
        return 'You are a support agent.';
    }
}

class ContextAgent extends Agent
{
    public function withContext(string $context): void
    {
        $this->setInstructions($context);
    }

    public function describe(): string
    {
        return $this->getInstructions()->getContent() ?? '';
    }
}
```

1. Delete the redeclared property. If it had a default value, return that text from `instructions(): string`. If the class already overrides `instructions()`, replace that method's body with the property's text, because 3.x never called the hook while the property had a value.
2. Change each `$this->instructions = $text` to `$this->setInstructions($text)`.
3. Change each read of `$this->instructions` as a string to `$this->getInstructions()->getContent()`. Add `?? ''` where a string is required.

### Case 5: An override of `setInstructions()`

The 3.x signature narrows the parameter and fails to load. Declare the 4.x signature, and make the body accept a `SystemMessage`.

Before (3.x):

```php
use NeuronAI\Agent\Agent;

class TrimmedAgent extends Agent
{
    public function setInstructions(string $instructions): self
    {
        parent::setInstructions(trim($instructions));
        return $this;
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\SystemMessage;

class TrimmedAgent extends Agent
{
    public function setInstructions(SystemMessage|string $instructions): static
    {
        parent::setInstructions(is_string($instructions) ? trim($instructions) : $instructions);
        return $this;
    }
}
```

### Case 6: Reading the instructions

Before (3.x):

```php
$prompt = $agent->resolveInstructions();

$this->assertStringContainsString('support', $agent->resolveInstructions());
```

After (4.x):

```php
$prompt = $agent->getInstructions()->getContent() ?? '';

$this->assertTrue($agent->getInstructions()->contains('support'));
```

1. Replace every remaining `resolveInstructions()` call with `getInstructions()->getContent()`. Add `?? ''` where a string is required. This includes calls on variables typed `AgentInterface`.
2. Exception: leave `$this->resolveInstructions()` unchanged where it is an argument to a node constructor in an Agent or RAG subclass. Guide 26 migrates it.
3. After a run in 3.x, `resolveInstructions()` also contained the toolkit `<TOOLS-GUIDELINES>` block. `getInstructions()` never contains it. A test that checks guideline text must read the prompt the provider received, for example `$this->assertTrue($provider->getRecorded()[0]->systemPrompt?->contains('<TOOLS-GUIDELINES>'));`.

### Case 7: A custom provider's `systemPrompt()`

A `systemPrompt(?string $prompt)` implementation fails to load. The Agent now passes a `SystemMessage` on every inference.

Before (3.x):

```php
use NeuronAI\Providers\AIProviderInterface;

class AcmeProvider implements AIProviderInterface
{
    protected ?string $system = null;

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->system = $prompt;
        return $this;
    }

    // ...
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;

class AcmeProvider implements AIProviderInterface
{
    protected ?string $system = null;

    public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
    {
        $this->system = $prompt instanceof SystemMessage ? $prompt->getContent() : $prompt;
        return $this;
    }

    // ...
}
```

- **Standalone provider:** store the text, as shown above.
- **Subclass of a built-in provider:** widen the parameter the same way and keep handing the value to `parent::systemPrompt()`. Do not assign `$this->system` in the override. If the body edits the prompt as text, convert it first, for example: `$text = $prompt instanceof SystemMessage ? $prompt->getContent() : $prompt; return parent::systemPrompt($text . "\nTenant: acme");`.
- **Provider whose API takes system blocks:** store `$this->system = is_string($prompt) ? new SystemMessage($prompt) : $prompt;` (typed `?SystemMessage`), and when building the request iterate `$this->system?->getTextBlocks() ?? []`, so that the toolkit guidelines block is included too. Add the vendor's cache marker when `$block instanceof SystemContent && $block->isCached()`.

### Case 8: `Anthropic::systemPromptBlocks()`

Before (3.x):

```php
use NeuronAI\Providers\Anthropic\Anthropic;

$provider = (new Anthropic(key: $key, model: $model))->systemPromptBlocks([
    ['type' => 'text', 'text' => $static, 'cache_control' => ['type' => 'ephemeral']],
    ['type' => 'text', 'text' => $dynamic],
]);
```

After (4.x):

```php
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\Anthropic\Anthropic;

$provider = (new Anthropic(key: $key, model: $model))->systemPrompt(new SystemMessage([
    (new SystemContent($static))->cache(),
    new SystemContent($dynamic),
]));
```

1. A block that carried `cache_control` becomes `(new SystemContent($text))->cache()`. The other blocks become `new SystemContent($text)`.
2. 4.x always sends `cache_control: {"type": "ephemeral"}`. A block with a custom `ttl` (such as `'1h'`) cannot keep it. Keep the block cached and report the lost TTL to the developer.
3. If an Agent uses this provider (it is returned from `provider()` or passed to `setAiProvider()`), the call never had an effect. The Agent replaced the system prompt before every inference in 3.x, and it still does in 4.x. Delete the call. Then ask the developer whether these blocks should become the agent's instructions. If they should, return the `SystemMessage` from the agent's `instructions()`, or pass it to `setInstructions()`.
4. In a subclass of `Anthropic` or `AnthropicVertex`, leave reads of `$this->systemBlocks` and `$this->promptCachingEnabled` for guide 43.

### Case 9: `Anthropic::withPromptCaching()`

In 3.x the flag put `cache_control` on the last tool definition. In 4.x a tool carries it as a tool parameter. Anthropic's tool mapper merges tool parameters into the tool definition.

Before (3.x provider, with tools as guide 3 left them):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;

class OrdersAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return (new Anthropic(key: 'ANTHROPIC_KEY', model: 'claude-sonnet-4-5'))->withPromptCaching();
    }

    protected function tools(): array
    {
        return [
            new SearchOrdersTool(),
            new RefundOrderTool(),
        ];
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;

class OrdersAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new Anthropic(key: 'ANTHROPIC_KEY', model: 'claude-sonnet-4-5');
    }

    protected function tools(): array
    {
        return [
            new SearchOrdersTool(),
            (new RefundOrderTool())->setParameters(['cache_control' => ['type' => 'ephemeral']]),
        ];
    }
}
```

1. Delete every `->withPromptCaching(false)` call.
2. Delete every `->withPromptCaching()` and `->withPromptCaching(true)` call. If that provider is sent tools, find the tool sent last:
   - The agent sends the tools passed to `setTools()` and `addTool()` first, then the entries of `tools()`. `tools()` is ignored after `setTools()`.
   - A toolkit expands in place into its tools.
   - For a provider called directly, the last tool is the last element passed to its `setTools()`.
3. Chain `->setParameters([...])` on that tool, with `'cache_control' => ['type' => 'ephemeral']` added to the parameters the tool already has, because `setParameters()` replaces them. Look for existing parameters in the tool class (a `protected array $parameters = [...]` default or a `$this->parameters` assignment) and in any chained `setParameters()` call, and repeat them in the array, for example `(new RefundOrderTool())->setParameters(['strict' => true, 'cache_control' => ['type' => 'ephemeral']])`.
4. If the last tool comes from a toolkit, set the parameter through the toolkit: `->with(RefundOrderTool::class, fn (RefundOrderTool $tool) => $tool->setParameters([...$tool->getParameters(), 'cache_control' => ['type' => 'ephemeral']]))`.
5. Add the parameter only to tools sent to `Anthropic` or `AnthropicVertex`. Other providers also merge tool parameters into their payload, and they would send an unknown field.
6. If the last tool comes from an MCP connector or is a provider tool, report it to the developer instead.
7. When the agent's instructions are static, another option is to cache them instead: return `(new SystemMessage($text))->cache()` from `instructions()`, importing `NeuronAI\Chat\Messages\SystemMessage`. Anthropic caches the tool definitions together with a cached system block.

### Case 10: `FakeAIProvider` recordings

Before (3.x):

```php
use NeuronAI\Testing\RequestRecord;

$record = $provider->getRecorded()[0];
$this->assertSame('You are a support agent.', $record->systemPrompt);

$record = new RequestRecord(method: 'chat', messages: [], systemPrompt: 'Be brief.', tools: []);
```

After (4.x):

```php
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Testing\RequestRecord;

$record = $provider->getRecorded()[0];
$this->assertSame('You are a support agent.', $record->systemPrompt?->getContent());

$record = new RequestRecord(method: 'chat', messages: [], systemPrompt: new SystemMessage('Be brief.'), tools: []);
```

1. Change each read of `$record->systemPrompt` as a string to `$record->systemPrompt?->getContent()`.
2. Wrap each string passed to `new RequestRecord(... systemPrompt: ...)` in `new SystemMessage(...)`.
3. `assertSystemPrompt(string)` is unchanged. It compares the text of `getContent()`.
4. Update expected prompts that include toolkit guidelines. The guidelines are now a separate block, rendered as `"<instructions>\n\n<TOOLS-GUIDELINES>\n# <tool names, comma-separated>\n<guidelines>\n</TOOLS-GUIDELINES>"`. You can also assert with `->contains()`.
5. The framework's default instructions no longer start with the `# IDENTITY AND PURPOSE` heading. Update tests that expect the 3.x default prompt text.

### Case 11: `match` or `switch` over `ContentBlockType`

A `match` without a default arm throws `UnhandledMatchError` when it meets a `SystemContent` block. A `switch` without a default case skips it silently. System blocks reach code that iterates a `SystemMessage`, such as a custom provider's `systemPrompt()` or code reading `getInstructions()->getContentBlocks()`.

Before (3.x):

```php
use NeuronAI\Chat\Enums\ContentBlockType;

return match ($block->getType()) {
    ContentBlockType::TEXT => $this->mapText($block),
    ContentBlockType::REASONING => $this->mapReasoning($block),
    ContentBlockType::IMAGE, ContentBlockType::FILE, ContentBlockType::AUDIO, ContentBlockType::VIDEO => $this->mapMedia($block),
};
```

After (4.x):

```php
use NeuronAI\Chat\Enums\ContentBlockType;

return match ($block->getType()) {
    ContentBlockType::TEXT, ContentBlockType::SYSTEM => $this->mapText($block),
    ContentBlockType::REASONING => $this->mapReasoning($block),
    ContentBlockType::IMAGE, ContentBlockType::FILE, ContentBlockType::AUDIO, ContentBlockType::VIDEO => $this->mapMedia($block),
};
```

`SystemContent` extends `TextContent`, so mapping it as text is correct unless the code must treat instructions differently.

## Checklist

- No Agent or RAG subclass declares its own `getInstructions()`.
- No `resolveInstructions` remains, except as a node-constructor argument in an Agent or RAG subclass, which guide 26 migrates.
- The logic of every former `resolveInstructions()` override now lives in `instructions()`.
- No `parent::instructions()` result from the framework default is concatenated as a string.
- No Agent or RAG subclass redeclares `$instructions` or assigns a string to it.
- Every `setInstructions()` override declares `setInstructions(SystemMessage|string $instructions): static`.
- Every `systemPrompt()` implementation declares `systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface`.
- No `systemPromptBlocks(` or `withPromptCaching(` call remains.
- A `cache_control` tool parameter is set only on tools sent to `Anthropic` or `AnthropicVertex`.
- Lost cache TTLs, uncacheable last tools and `systemPromptBlocks()` calls on agent providers have been reported to the developer.
- Tests read `$record->systemPrompt` through `getContent()` or `contains()`, and build `RequestRecord` with a `SystemMessage`.
- Every `match` over `ContentBlockType` handles `SYSTEM` or has a default arm.
