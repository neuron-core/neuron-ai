# Upgrade: Provider subclasses: ToolCall entries and changed protected members

## Summary

This guide applies to application classes that extend a built-in provider class (or one of its `StreamState`,
`MessageMapper` or `ToolMapper` classes) and override or use its protected members. It also applies to custom
`AIProviderInterface` implementations that build tool-call entries. Applications that only construct providers and
pass them to agents are not affected.

| 3.x | 4.x |
|---|---|
| Tool-call entries built with `$this->findTool($name)->setInputs($inputs)->setCallId($id)` | `$this->newToolCall($name, $callId, $inputs)` returns a `NeuronAI\Tools\ToolCall`. `$this->decodeToolArguments($name, $raw)` decodes the arguments |
| `BedrockRuntime::createTool(array $toolContent): ToolInterface` | `createTool(array $toolContent): ToolCall` |
| `Anthropic::createToolCallMessage()` is public | It is protected |
| `protected ?string $system` on `Anthropic` and `OpenAIResponses` | `protected ?SystemMessage $system`. Every other provider keeps `?string` |
| `protected ?string $stopReason` on `Anthropic` | Removed. Use `$this->streamState->stopReason()` / `setStopReason()` |
| `Anthropic\StreamState::getToolCalls()` decoded each call's `input` | `input` is the raw JSON string |
| `OpenAIResponses::extractCitations(string $text, array $annotations)` | `extractCitations(array $annotations)` |
| `processAnnotation(array $annotation): ?Citation` on `OpenAI` and its subclasses | Removed, never called |
| `readLine(StreamInterface $stream): string` on `Gemini` and `OpenAIResponses` | Removed. Gemini streams are SSE |
| `Gemini\StreamState::addContentBlock(string $type, ContentBlockInterface $block)` | `addContentBlock(ContentBlockInterface $block)` |
| `Ollama::$url` | `Ollama::$baseUri`. The `url:` constructor argument is unchanged |
| `createAssistantMessage(array $message)` on `OpenAI` ran for plain answers only | It also runs for answers with tool calls, where `content` can be null |

No stored data is affected.

Other guides handle these, so leave them alone here:
- `chat()`, `stream()` and `structured()` overrides that return a `Message`: guide 41.
- `getModel()`, `messageMapper()` and `toolPayloadMapper()`: guide 42.
- `systemPrompt()` overrides, and `Anthropic::systemPromptBlocks()` / `withPromptCaching()`: guide 24.
- `requestUri()`, `requestHeaders()`, `createChatHttpRequest()`, `$httpHeaders`, `getHttpClient()` and relative request URLs: guide 2.
- `messageId($vendorId)` calls on a stream state: guide 38.
- `stream()` overrides that must end on the vendor's closing event: guide 40.
- `AzureOpenAI::$version` and its constructor: guide 44.
- `use HandleContent;` and `$this->removeDelimitedContent()`: guide 57.
- Mapper subclasses that read message entries (`getTools()`, closures typed `ToolInterface`, `getResult()`): guide 4.

## What to Search For

Run from the application root:

```bash
# 1. Classes this guide applies to
grep -rnE 'extends[[:space:]]+([A-Za-z0-9_\\]*\\)?(OpenAI|OpenAILike|OpenAIResponses|OpenAILikeResponses|AzureOpenAI|Anthropic|AnthropicVertex|Gemini|GeminiVertex|Mistral|Ollama|Deepseek|Cohere|Grok|ZAI|HuggingFace|DashScopeOpenAI|BedrockRuntime|OpenAIImage|ZAIImage|OpenAISpeechToText|OpenAITextToSpeech|ElevenLabsSpeechToText|ElevenLabsTextToSpeech|ZAITranscription|BasicStreamState|StreamState|MessageMapper|ToolMapper)([^A-Za-z0-9_]|$)|implements[^{]*AIProviderInterface' --include='*.php' --exclude-dir=vendor .
grep -rnE 'use[[:space:]]+NeuronAI\\Providers\\[A-Za-z0-9_\\]+[[:space:]]+as[[:space:]]' --include='*.php' --exclude-dir=vendor .
# 2. Cases 1 to 3: tool-call entries
grep -rnE 'findTool\(|setCallId\(|ToolCall::make\(|new ToolCall\(|createToolCallMessage[[:space:]]*\(|function createTool[[:space:]]*\(' --include='*.php' --exclude-dir=vendor .
# 3. Case 4
grep -rnE 'this->system([^A-Za-z0-9_]|$)|string[[:space:]]+\$system([^A-Za-z0-9_]|$)|systemBlocks|promptCachingEnabled' --include='*.php' --exclude-dir=vendor .
# 4. Case 5
grep -rnE 'this->stopReason([^A-Za-z0-9_(]|$)|streamState->getToolCalls\(' --include='*.php' --exclude-dir=vendor .
# 5. Case 6
grep -rnE 'extractCitations|processAnnotation' --include='*.php' --exclude-dir=vendor .
# 6. Case 7
grep -rnE 'function readLine[[:space:]]*\(|(this->|parent::)readLine\(|addContentBlock\(' --include='*.php' --exclude-dir=vendor .
# 7. Case 8
grep -rnE 'this->(url|version)([^A-Za-z0-9_(]|$)' --include='*.php' --exclude-dir=vendor .
# 8. Case 9
grep -rnE 'function (createAssistantMessage|processToolCallDelta)[[:space:]]*\(' --include='*.php' --exclude-dir=vendor .
# 9. Case 10
grep -rnE 'function (newToolCall|decodeToolArguments|earlyEndResponse|applyStreamMetadata|requestBody|attachSystemPrompt|createImageContent|handlePart|describeBlockedPrompt|credentials|accessToken|extractContent|contentBlocks|getToolCall|setStopReason|stopReason|decodeBase64|stripNullableTypes|prompt|voiceUri|audioFilePart|openAudioFile|decodeAudio|audioExtension)[[:space:]]*\(|(public|protected|private)[^;(]*\$(pathJsonCredentials|credentials|stopReason)([^A-Za-z0-9_]|$)|CONVERSE_FORMATS' --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:
- Search 1 lists the classes this guide applies to. Keep a `StreamState`, `MessageMapper` or `ToolMapper` hit only when its parent is imported from `NeuronAI\Providers\`. The second command finds aliased imports (`use NeuronAI\Providers\OpenAI\OpenAI as BaseOpenAI;`): add the classes that extend the alias. When a hit is an application base class, add the classes that extend it too.
- Searches 2 to 9: act only on hits inside the classes from search 1. There is one exception: `->createToolCallMessage(` called on an `Anthropic` or `AnthropicVertex` instance anywhere is Case 3. Other hits are unrelated, such as `ToolCall::make(` in tests (guide 4 wrote those) or `$this->system` in an Agent.

If search 1 finds no class and search 2 finds no `->createToolCallMessage(` call, this guide does not apply.

## How to Refactor

### Case 1: `createToolCallMessage()` and `createTool()` overrides

A `ToolCallMessage` now holds `ToolCall` records. An override that still puts tool objects into it produces a message
the agent cannot run. A `BedrockRuntime::createTool()` override that declares `: ToolInterface` fails to load.

Before (3.x):

```php
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Tools\ToolInterface;

class AcmeProvider extends OpenAILike
{
    protected function createToolCallMessage(array $toolCalls, array|ContentBlockInterface|null $blocks = null): ToolCallMessage
    {
        $tools = array_map(
            fn (array $item): ToolInterface => $this->findTool($item['function']['name'])
                ->setInputs(json_decode((string) $item['function']['arguments'], true))
                ->setCallId($item['id']),
            $toolCalls
        );

        $message = new ToolCallMessage($blocks, array_values($tools));
        $message->addMetadata('acme_tool_calls', $toolCalls);

        return $message;
    }
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Tools\ToolCall;

class AcmeProvider extends OpenAILike
{
    protected function createToolCallMessage(array $toolCalls, array|ContentBlockInterface|null $blocks = null): ToolCallMessage
    {
        $tools = array_map(
            fn (array $item): ToolCall => $this->newToolCall(
                $item['function']['name'],
                $item['id'],
                $this->decodeToolArguments($item['function']['name'], $item['function']['arguments'] ?? null),
            ),
            $toolCalls
        );

        $message = new ToolCallMessage($blocks, array_values($tools));
        $message->addMetadata('acme_tool_calls', $toolCalls);

        return $message;
    }
}
```

1. Build each entry as `$this->newToolCall($name, $callId, $this->decodeToolArguments($name, $rawArguments))`. Take the three values from the table below, and remove the override's own `json_decode()`.
   - `newToolCall(string $name, ?string $callId, array $inputs): ToolCall` is public. Like `findTool()`, it throws `ProviderException` for a name that no registered tool has. It also copies the registered tool's description and deferred flag onto the call, and the agent needs that flag.
   - `decodeToolArguments(string $toolName, array|string|null $arguments): array` is protected. It accepts a JSON string, an already-decoded array or null (which gives `[]`). It throws `ProviderException` when the arguments are not a JSON object.
2. Change `ToolInterface` to `ToolCall` in closure return types, `@var` tags and return types, and import `NeuronAI\Tools\ToolCall`. Remove the `ToolInterface` import if nothing else uses it.
3. In a `BedrockRuntime` subclass, declare `createTool(array $toolContent): ToolCall`. If the override only calls `parent::createTool()`, changing the return type is enough.
4. If guide 4 already rewrote an entry inside a provider class to `ToolCall::make(...)` or `new ToolCall(...)`, replace it with `newToolCall()` too.
5. The method signatures are otherwise unchanged. An `Anthropic` override declared `public` may stay public.

| Parent class (and its subclasses) | Name | Call ID | Raw arguments |
|---|---|---|---|
| `OpenAI` (`OpenAILike`, `AzureOpenAI`, `Cohere`, `Deepseek`, `Grok`, `HuggingFace`, `DashScopeOpenAI`, `ZAI`), `Mistral` | `$item['function']['name']` | `$item['id']` | `$item['function']['arguments'] ?? null` |
| `Anthropic`, `AnthropicVertex` | `$tool['name']` | `$tool['id']` | `$tool['input'] ?? null`: a JSON string when streamed, an array otherwise |
| `OpenAIResponses`, `OpenAILikeResponses` | `$item['name']` | `$item['call_id']` | `$item['arguments'] ?? null` |
| `Gemini`, `GeminiVertex` | `$item['functionCall']['name']` | `$item['functionCall']['id'] ?? uniqid($name . '_' . $index . '_')` | `$item['functionCall']['args'] ?? null` |
| `Ollama` | `$item['function']['name']` | `uniqid($name . '_' . $index . '_')` | `$item['function']['arguments'] ?? null` |
| `BedrockRuntime` (`createTool()`) | `$toolContent['toolUse']['name']` | `$toolContent['toolUse']['toolUseId']` | `$toolContent['toolUse']['input'] ?? null` |

`$index` is the call's position in `array_values($toolCalls)`. Every call needs its own call ID. Never use the tool name
(the 3.x Gemini pattern) or null (the 3.x Ollama pattern), because 4.x matches approvals and results to calls by ID.

### Case 2: Custom providers that build entries themselves

This case covers `AIProviderInterface` implementations that keep their own tool list instead of using
`NeuronAI\Providers\HandleWithTools`.

Before (3.x):

```php
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Tools\ToolInterface;

protected function toToolCall(array $call): ToolInterface
{
    foreach ($this->tools as $tool) {
        if ($tool->getName() === $call['name']) {
            return (clone $tool)->setInputs($call['arguments'])->setCallId($call['id']);
        }
    }

    throw new ProviderException("Unknown tool: {$call['name']}");
}
```

After (4.x):

```php
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Tools\DeferredToolInterface;
use NeuronAI\Tools\ToolCall;

protected function toToolCall(array $call): ToolCall
{
    foreach ($this->tools as $tool) {
        if ($tool->getName() === $call['name']) {
            return new ToolCall(
                name: $call['name'],
                callId: $call['id'],
                inputs: $call['arguments'],
                description: $tool->getDescription(),
                deferred: $tool instanceof DeferredToolInterface,
            );
        }
    }

    throw new ProviderException("Unknown tool: {$call['name']}");
}
```

- Always pass `description` and `deferred`. This also applies to a `ToolCall::make(...)` entry that guide 4 wrote in a provider.
- Give every call its own call ID, as Case 1 requires.
- Alternatively, replace the class's own `$tools` property, `setTools()` and tool lookup with `use NeuronAI\Providers\HandleWithTools;`, then apply Case 1.

### Case 3: Calls to `Anthropic::createToolCallMessage()` from outside the provider

The method is protected, so external calls fail with "Call to protected method".

Before (3.x):

```php
$message = $provider->createToolCallMessage([
    ['id' => 'toolu_01', 'name' => 'search', 'input' => ['query' => 'php']],
]);
```

After (4.x):

```php
use NeuronAI\Chat\Messages\ToolCallMessage;

$message = new ToolCallMessage(null, [
    $provider->newToolCall('search', 'toolu_01', ['query' => 'php']),
]);
```

`newToolCall()` needs the tool registered on the provider through `setTools()`, as `findTool()` did in 3.x. In tests
that only need the message, `ToolCall::make(name: ..., callId: ..., inputs: ...)` also works (guide 4, Case 2).

### Case 4: `$system` in subclasses of `Anthropic`, `AnthropicVertex`, `OpenAIResponses` and `OpenAILikeResponses`

In these classes `$system` is now `protected ?SystemMessage $system` (`NeuronAI\Chat\Messages\SystemMessage`). A
redeclared `protected ?string $system` fails to load. Assigning a string to it is a `TypeError`, and concatenating it
fails with "Object of class SystemMessage could not be converted to string". Subclasses of other providers keep
`?string $system`, so leave them alone.

Before (3.x):

```php
use NeuronAI\Providers\Anthropic\Anthropic;

class TenantAnthropic extends Anthropic
{
    protected ?string $system = 'You answer for the Acme tenant.';

    public function withTenantNote(string $note): self
    {
        $this->system .= "\n" . $note;
        return $this;
    }

    public function currentPrompt(): ?string
    {
        return $this->system;
    }
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\Anthropic\Anthropic;

class TenantAnthropic extends Anthropic
{
    public function __construct(string $key, string $model, ?HttpClientInterface $httpClient = null)
    {
        parent::__construct(key: $key, model: $model, httpClient: $httpClient);
        $this->systemPrompt('You answer for the Acme tenant.');
    }

    public function withTenantNote(string $note): self
    {
        $this->system = new SystemMessage([...($this->system?->getContentBlocks() ?? []), new TextContent($note)]);
        return $this;
    }

    public function currentPrompt(): ?string
    {
        return $this->system?->getContent();
    }
}
```

1. Delete a redeclared `$system`. If it had a default value, set that value with `$this->systemPrompt('...')` right after `parent::__construct()`. If the class has no constructor, add one that forwards the arguments the application passes.
2. Read the text with `$this->system?->getContent()`. It joins the blocks with a blank line and returns null when there is no text.
3. Replace `$this->system = $text` with `$this->systemPrompt($text)`.
4. To append text, build a new message as shown, importing `TextContent`. Do not call `addContent()` on `$this->system`, because it is the object the agent passed in.
5. Replace reads of the removed `$this->systemBlocks` with `$this->system?->getTextBlocks()`. Each block has a `->content` string and `->isCached()`. Delete uses of `$this->promptCachingEnabled` (guide 24 covers caching).

### Case 5: Anthropic streaming internals

This case applies to subclasses of `Anthropic` and `AnthropicVertex`. The stream states of the other providers return
the same `getToolCalls()` entries as in 3.x.

Before (3.x):

```php
use NeuronAI\Providers\Anthropic\Anthropic;

class GuardedAnthropic extends Anthropic
{
    /** @var string[] */
    public array $truncatedCalls = [];

    protected function handleMessageDelta(array $event): void
    {
        parent::handleMessageDelta($event);

        if ($this->stopReason === 'max_tokens') {
            foreach ($this->streamState->getToolCalls() as $call) {
                $this->truncatedCalls[] = $call['name'] . ' ' . json_encode($call['input']);
            }
        }
    }
}
```

After (4.x):

```php
use NeuronAI\Providers\Anthropic\Anthropic;

class GuardedAnthropic extends Anthropic
{
    /** @var string[] */
    public array $truncatedCalls = [];

    protected function handleMessageDelta(array $event): void
    {
        parent::handleMessageDelta($event);

        if ($this->streamState->stopReason() === 'max_tokens') {
            foreach ($this->streamState->getToolCalls() as $call) {
                $this->truncatedCalls[] = $call['name'] . ' ' . $call['input'];
            }
        }
    }
}
```

1. Replace reads of `$this->stopReason` with `$this->streamState->stopReason()`. Replace assignments with `$this->streamState->setStopReason($reason)`. The value belongs to the current stream.
2. `$this->streamState->getToolCalls()` now gives each call's `input` as the raw JSON string. Where the code needs the array, decode it with `$this->decodeToolArguments($call['name'], $call['input'])`. It throws `ProviderException` for incomplete JSON.

### Case 6: Citations

**`OpenAIResponses` and `OpenAILikeResponses`:** `extractCitations()` now takes only the annotations. An override with the
3.x signature fails to load.

Before (3.x):

```php
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;

class FilteredResponses extends OpenAIResponses
{
    protected function extractCitations(string $text, array $annotations): array
    {
        return array_values(array_filter(
            parent::extractCitations($text, $annotations),
            fn (Citation $citation): bool => $citation->source !== '',
        ));
    }
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;

class FilteredResponses extends OpenAIResponses
{
    protected function extractCitations(array $annotations): array
    {
        return array_values(array_filter(
            parent::extractCitations($annotations),
            fn (Citation $citation): bool => $citation->source !== '',
        ));
    }
}
```

If the override used `$text`, move that logic into an override of `createAssistantMessage(array $response): AssistantMessage`,
which receives the whole response.

**`OpenAI` and its subclasses:** `processAnnotation()` is never called, so an override of it is silently ignored.
`parent::processAnnotation()` fails. The 4.x `extractCitations(array $message): array` reads the `url_citation`
entries of `$message['annotations']`. 3.x read the annotations of each block in `$message['content']`.

Before (3.x):

```php
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Providers\OpenAILike;

class CitingProvider extends OpenAILike
{
    protected function processAnnotation(array $annotation): ?Citation
    {
        if (($annotation['type'] ?? null) === 'url_citation') {
            return new Citation(id: uniqid('acme_'), source: $annotation['url'] ?? '');
        }

        return parent::processAnnotation($annotation);
    }
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Providers\OpenAILike;

class CitingProvider extends OpenAILike
{
    protected function extractCitations(array $message): array
    {
        $citations = parent::extractCitations($message);

        foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
            foreach ($block['annotations'] ?? [] as $annotation) {
                if ($citation = $this->processAnnotation($annotation)) {
                    $citations[] = $citation;
                }
            }
        }

        return $citations;
    }

    protected function processAnnotation(array $annotation): ?Citation
    {
        if (($annotation['type'] ?? null) === 'url_citation') {
            return new Citation(id: uniqid('acme_'), source: $annotation['url'] ?? '');
        }

        return null;
    }
}
```

1. Keep the application's `processAnnotation()`, which is now an ordinary method. Add the `extractCitations()` override shown, which calls it the way 3.x did.
2. Replace `parent::processAnnotation($annotation)` with `null`. The 3.x parent mapped only `file_citation` and `file_path` annotations, which OpenAI's Chat Completions API does not send. If the provider's API sends them, ask the developer how to map them.

### Case 7: Stream reading

**Gemini:** `readLine()` is gone, and Gemini streams are now SSE. Where application code reads the Gemini stream itself:
1. Append `?alt=sse` to the request URI, so that it ends in `:streamGenerateContent?alt=sse`.
2. Read each event with `NeuronAI\Providers\SSEParser::parseNextSSEEvent($stream)`, which returns the decoded event array or null.

Before (3.x):

```php
while (! $stream->eof()) {
    $line = $this->readLine($stream);

    if (($line = json_decode((string) $line, true)) === null) {
        continue;
    }

    // ... handle $line
}
```

After (4.x):

```php
use NeuronAI\Providers\SSEParser;

while (! $stream->eof()) {
    if (!$line = SSEParser::parseNextSSEEvent($stream)) {
        continue;
    }

    // ... handle $line
}
```

**`OpenAIResponses` and `OpenAILikeResponses`:** replace `$this->readLine($stream)` with `$stream->readLine()`. It returns
the same line, including the newline.

Before (3.x):

```php
use NeuronAI\HttpClient\StreamInterface;

protected function parseNextDataLine(StreamInterface $stream): ?array
{
    $this->rawLines[] = $line = $this->readLine($stream);
    return json_decode(substr($line, 6), true);
}
```

After (4.x):

```php
use NeuronAI\HttpClient\StreamInterface;

protected function parseNextDataLine(StreamInterface $stream): ?array
{
    $this->rawLines[] = $line = $stream->readLine();
    return json_decode(substr($line, 6), true);
}
```

An application override of `readLine()` in either class is never called. Delete it. For `OpenAIResponses`, move its
logic into `parseNextDataLine(StreamInterface $stream): ?array`, which reads and decodes one event. For Gemini there is
no equivalent hook, so ask the developer where the logic should go.

**`Gemini\StreamState::addContentBlock()`** lost its first argument. Remove that argument from calls, and give overrides
in a `Gemini\StreamState` subclass the new signature. Each call now appends a block, where 3.x kept one block per
type. `Anthropic\StreamState` and the Responses `StreamState` keep their two-argument `addContentBlock()`.

Before (3.x):

```php
$this->streamState->addContentBlock('image', new ImageContent($data, SourceType::BASE64, 'image/png'));
```

After (4.x):

```php
$this->streamState->addContentBlock(new ImageContent($data, SourceType::BASE64, 'image/png'));
```

### Case 8: Renamed properties

- **Ollama:** replace `$this->url` with `$this->baseUri`. It holds the same value, the `url:` constructor argument.
- **AzureOpenAI:** `$this->version` is removed (guide 44).
- **HuggingFace:** when an inference provider is passed, `$this->model` (and `getModel()`) is `"{model}:{provider}"`. Where a subclass needs the bare model name, strip the `':' . $this->inferenceProvider->value` suffix.

Before (3.x):

```php
use NeuronAI\Providers\Ollama\Ollama;

class LocalOllama extends Ollama
{
    public function endpoint(): string
    {
        return $this->url;
    }
}
```

After (4.x):

```php
use NeuronAI\Providers\Ollama\Ollama;

class LocalOllama extends Ollama
{
    public function endpoint(): string
    {
        return $this->baseUri;
    }
}
```

### Case 9: `OpenAI` hooks that now run in more situations

This case applies to subclasses of `OpenAI` and its subclasses.

- `createAssistantMessage(array $message)` now also runs for answers that carry tool calls, and its blocks become the content of the `ToolCallMessage`. In those answers `$message['content']` is usually null, so make the override accept null.
- The parent `processToolCallDelta(array $choice)` now yields `ToolArgumentChunk`s while tool arguments stream. An override that does not call `yield from parent::processToolCallDelta($choice);` drops them, so add that line.

Before (3.x):

```php
use NeuronAI\Chat\Messages\AssistantMessage;

protected function createAssistantMessage(array $message): AssistantMessage
{
    return new AssistantMessage(trim($message['content']));
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\AssistantMessage;

protected function createAssistantMessage(array $message): AssistantMessage
{
    return new AssistantMessage($message['content'] === null ? null : trim($message['content']));
}
```

### Case 10: Members that 4.x added

A subclass member with one of these names now collides with the framework's member. Different signatures fail to load.
Matching signatures silently replace the framework's method.

| Added in 4.x | On these classes and their subclasses |
|---|---|
| `newToolCall()`, `decodeToolArguments()` | `OpenAI` and its subclasses, `Anthropic`, `AnthropicVertex`, `Gemini`, `GeminiVertex`, `Mistral`, `Ollama`, `OpenAIResponses`, `OpenAILikeResponses`, `BedrockRuntime` |
| `earlyEndResponse()` | The same classes, except `BedrockRuntime` |
| `applyStreamMetadata()` | `OpenAI` and its subclasses |
| `requestBody()`, `attachSystemPrompt()`, `createImageContent()` | `OpenAIResponses`, `OpenAILikeResponses` |
| `handlePart()`, `describeBlockedPrompt()` | `Gemini`, `GeminiVertex` |
| `credentials()`, `accessToken()`, properties `$pathJsonCredentials` and `$credentials` | `AnthropicVertex`, `GeminiVertex` |
| `extractContent()` | `Mistral` |
| `contentBlocks()` | `Ollama` |
| `getToolCall()`, `setStopReason()`, `stopReason()`, property `$stopReason` | `BasicStreamState` and every provider `StreamState` except `Ollama\StreamState` |
| `decodeBase64()`, constant `CONVERSE_FORMATS` | `NeuronAI\Providers\AWS\MessageMapper` |
| `stripNullableTypes()` | `NeuronAI\Providers\Gemini\ToolMapper` |
| `prompt()` | `OpenAIImage`, `ZAIImage` |
| `voiceUri()` | `ElevenLabsTextToSpeech` |
| `audioFilePart()`, `openAudioFile()`, `decodeAudio()`, `audioExtension()` | `OpenAISpeechToText`, `ElevenLabsSpeechToText` |

A hit from search 9 matters only when its class extends a class listed for that name. Rename the application's member
and every reference to it. `getModel()` is guide 42's and `requestHeaders()` is guide 2's.

Before (3.x):

```php
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;

class TaggedResponses extends OpenAIResponses
{
    protected function requestBody(array $messages): array
    {
        return ['metadata' => ['app' => 'acme'], 'input' => $messages];
    }
}
```

After (4.x):

```php
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;

class TaggedResponses extends OpenAIResponses
{
    protected function acmeRequestBody(array $messages): array
    {
        return ['metadata' => ['app' => 'acme'], 'input' => $messages];
    }
}
```

## Checklist

- Provider classes build every tool-call entry with `newToolCall()`, or with `new ToolCall(...)` carrying `description` and `deferred` when they do not use `HandleWithTools`. No `findTool()->setInputs()->setCallId()` chain remains.
- Every call gets its own call ID, never the tool name or null.
- Every `BedrockRuntime::createTool()` override declares `: ToolCall`.
- No code outside the provider calls `Anthropic::createToolCallMessage()`.
- No subclass of `Anthropic`, `AnthropicVertex`, `OpenAIResponses` or `OpenAILikeResponses` redeclares `$system`, or treats it as a string. `$this->systemBlocks` and `$this->promptCachingEnabled` are gone.
- No `$this->stopReason` remains in Anthropic subclasses, and their code reading `streamState->getToolCalls()` treats `input` as a JSON string.
- `extractCitations()` overrides match the 4.x signatures, and no `parent::processAnnotation()` call remains.
- No `readLine()` override or `$this->readLine()` call remains in subclasses of `Gemini`, `GeminiVertex`, `OpenAIResponses` or `OpenAILikeResponses`. Gemini code that reads the stream itself requests `?alt=sse` and uses `SSEParser`.
- `Gemini\StreamState::addContentBlock()` is called with one argument.
- No `$this->url` remains in Ollama subclasses.
- `createAssistantMessage()` overrides accept a null `content`, and `processToolCallDelta()` overrides call the parent.
- No subclass declares a member listed in Case 10 for its parent class.
- PHPStan loads every class from search 1 without errors about the members this guide migrates.
