# Upgrade: FileSystem, Retrieval and SES tool results changed shape

## Summary

This guide applies only to application code that calls these built-in tools directly, subclasses them, or reads their results: tests, evaluations, middleware, and code that reads conversation history. Registering the tools or `FileSystemToolkit::make()` on an agent needs no change.

| Tool | 3.x | 4.x |
|---|---|---|
| `ReadFileTool`, `GrepFileContentTool`, `GlobPathTool`, `ParseFileTool`: failure | string `Error: <message>` | `ToolOutput` error whose `getText()` is `<message>`, without the `Error: ` prefix. Return type `string\|ToolOutput` |
| `WriteFileTool`, `EditFileTool`, `DeleteFileTool`: failure | array `['status' => 'error', 'operation' => ..., 'file_path' => ..., 'message' => ...]` | `ToolOutput` error whose `getText()` is the 3.x `message`. Return type `array\|ToolOutput` |
| `BashTool`: non-zero exit | status array with `'status' => 'error'`, `exit_code`, and `output` (stdout, then stderr) | `ToolOutput` error: `Command exited with code N.`, followed by a blank line and the output only when the command printed something. stdout and stderr are interleaved, with no separator added. Return type `array\|ToolOutput` |
| `BashTool`: missing working directory, process failed to start | status array | `ToolOutput` error with the 3.x message |
| `EditFileTool`: search text occurs more than once | every occurrence replaced | `ToolOutput` error, file left untouched |
| Successful results | success array or text | unchanged, except: bash `output` interleaves stderr with stdout; `glob_path` text repeats the pattern as passed (3.x dropped a leading `**/`, e.g. `pattern '*.php'`) and a `**` segment in any position is recursive (`src/**/*.php` now also lists files directly in `src/`); `grep_file_content` line numbers are correct in files with multibyte characters (3.x could report `line 0`) |
| `RetrievalTool::__invoke()` | `NeuronAI\RAG\Document[]` | list of arrays with the keys `content`, `sourceType`, `sourceName`, `score` (`?float`) and `metadata`. No `id` and no `embedding` |
| `SESTool::__invoke()` | last parameter `?string $reply_to = null`, which was ignored | `reply_to` removed. `cc` and `bcc` are validated like `to` |

Every `ToolOutput` (`NeuronAI\Tools\ToolOutput`, introduced by guide 4) that a FileSystem tool returns is an error, so `isError()` is always true.

Stored data: 4.x reads back tool results that 3.x wrote to chat histories unchanged, with no data migration. They come back as the 3.x strings, with no error flag: JSON status arrays, `Error: ...` text, and Document JSON that includes `id` and `embedding`. See Case 3 for FileSystem results and Case 5 for `context_retrieval` results.

PHPStan at level 5 does not report the FileSystem cases below, so rely on the searches.

## What to Search For

Run from the application root:

```bash
# 1. Checks on status arrays and "Error:" strings
grep -rnE "\[[\"'](status|exit_code)[\"']\]|[\"']status[\"'] *=> *[\"']error|[\"']Error:" --include='*.php' --exclude-dir=vendor .

# 2. FileSystem tools, and code that selects their results by tool name
grep -rnE "FileSystemToolkit|(ReadFile|WriteFile|EditFile|DeleteFile|GlobPath|GrepFileContent|ParseFile|Bash)Tool|[\"'](read_file|write_file|edit_file|delete_file|glob_path|grep_file_content|parse_file|bash)[\"']" --include='*.php' --exclude-dir=vendor .

# 3. 3.x result texts in any file type (evaluation datasets, fixtures, snapshots)
grep -rnE '"status\\?": ?\\?"error|Error: (File|Directory|Unable to|Invalid regex|Unsupported file format)|Command exited with code' --exclude-dir=vendor --exclude-dir=.git .

# 4. RetrievalTool
grep -rnE "RetrievalTool|[\"']context_retrieval[\"']" --include='*.php' --exclude-dir=vendor .

# 5. SESTool
grep -rnE "SESTool|reply_to|[\"']send_email[\"']" --include='*.php' --exclude-dir=vendor .
```

Follow the hits:
- For each tool hit, follow the variable that holds the result, find classes that `extends` the tool (Case 2), and find tests that assert on it.
- A hit that only registers a tool or toolkit on an agent (`tools()`, `addTool()`, `FileSystemToolkit::make()`) needs no change.
- Hits from search 1 on other tools' results, including the application's own tools, are out of scope for this guide. SQL tool results belong to guide 9. Calendar and to-do tools still return `Error: ...` strings, and SESTool's `'sent'`/`'failed'` result array is unchanged.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: Code that calls a FileSystem tool and checks the result

On 4.x, `str_starts_with($result, 'Error:')` throws a `TypeError` under strict types. Without strict types it silently never matches. `$result['status']` throws `Error: Cannot use object of type NeuronAI\Tools\ToolOutput as array`.

Before (3.x):

```php
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use NeuronAI\Tools\Toolkits\FileSystem\ReadFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\WriteFileTool;

$content = (new ReadFileTool())($path);
if (str_starts_with($content, 'Error:')) {
    throw new RuntimeException($content);
}

$written = (new WriteFileTool())($path, strtoupper($content));
if ($written['status'] === 'error') {
    throw new RuntimeException($written['message']);
}

$run = (new BashTool())('composer test', $projectDir);
if ($run['exit_code'] !== 0) {
    echo "Tests failed:\n{$run['output']}";
}
```

After (4.x):

```php
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use NeuronAI\Tools\Toolkits\FileSystem\ReadFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\WriteFileTool;
use NeuronAI\Tools\ToolOutput;

$content = (new ReadFileTool())($path);
if ($content instanceof ToolOutput) {
    throw new RuntimeException($content->getText());
}

$written = (new WriteFileTool())($path, strtoupper($content));
if ($written instanceof ToolOutput) {
    throw new RuntimeException($written->getText());
}

$run = (new BashTool())('composer test', $projectDir);
if ($run instanceof ToolOutput) {
    echo "Tests failed:\n{$run->getText()}"; // "Command exited with code N.", plus "\n\n<output>" when there was output
}
```

A failed bash command no longer carries `exit_code` or `output` keys: both are in the text. The success array keeps all its 3.x keys.

### Case 2: Subclasses of a FileSystem tool

An `__invoke()` override declared `: array` or `: string` still loads. It throws a `TypeError` as soon as the parent returns a `ToolOutput`.

Before (3.x):

```php
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;

class AuditedBashTool extends BashTool
{
    public function __invoke(string $command, ?string $working_directory = null): array
    {
        $result = parent::__invoke($command, $working_directory);

        if ($result['status'] === 'error') {
            error_log("bash failed: {$command} (exit {$result['exit_code']})");
        }

        return $result;
    }
}
```

After (4.x):

```php
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use NeuronAI\Tools\ToolOutput;

class AuditedBashTool extends BashTool
{
    public function __invoke(string $command, ?string $working_directory = null): array|ToolOutput
    {
        $result = parent::__invoke($command, $working_directory);

        if ($result instanceof ToolOutput) {
            error_log("bash failed: {$command}: {$result->getText()}");
        }

        return $result;
    }
}
```

1. Widen the return type: `array|ToolOutput` for `BashTool`, `WriteFileTool`, `EditFileTool` and `DeleteFileTool`, and `string|ToolOutput` for `ReadFileTool`, `GrepFileContentTool`, `GlobPathTool` and `ParseFileTool`.
2. Replace status and `Error:` checks on the parent's result as in Case 1.
3. FileSystem tools now extend `NeuronAI\Tools\Toolkits\FileSystem\FileSystemTool`. It declares `protected ?string $scope` and the protected methods `resolve()`, `isAbsolute()`, `canonicalize()`, `contains()`, `splitDrive()` and `segments()`. `GlobPathTool` also declares `globstar()` and `tree()`, and `FileSystemToolkit` declares `protected ?string $scope`. If a subclass declares a member with one of these names, rename it and update its call sites. Otherwise the class fails to load (for example `Type of MyTool::$scope must be ?string`) or replaces the framework's path handling. If the subclass confined paths to a directory, ask the developer whether to keep that code under the new name or replace it with the built-in scope (`new ReadFileTool('/path')`, `FileSystemToolkit::make('/path')`).

### Case 3: Code that reads FileSystem results after the agent ran the tool

This covers `getResult()` on a `ToolCall` from a `ToolResultMessage`, or on a tool your test executed. Failures used to be JSON status strings or `Error:` strings. On 4.x they are error `ToolOutput`s. `json_decode()` of their text returns null, so the old check never detects a failure.

Before (as guide 4 left it; 3.x read `getTools()` and `getResult()` without the cast):

```php
foreach ($message->getToolCalls() as $call) {
    if ($call->getName() !== 'edit_file') {
        continue;
    }

    $result = json_decode((string) $call->getResult(), true);
    if ($result['status'] === 'error') {
        $failures[] = $result['message'];
    }
}
```

After (4.x):

```php
use NeuronAI\Tools\ToolOutput;

foreach ($message->getToolCalls() as $call) {
    if ($call->getName() !== 'edit_file' || !$call->hasResult()) {
        continue;
    }

    $result = $call->getResult();
    if ($result instanceof ToolOutput && $result->isError()) {
        $failures[] = $result->getText();
    }
}
```

Results that 3.x stored in chat histories come back as their 3.x strings, with no error flag. Ask the developer whether this code still reads conversations recorded before the upgrade. If it does, keep the 3.x check as a fallback branch for string results, for example `elseif (is_string($result) && (json_decode($result, true)['status'] ?? null) === 'error')` for status arrays, or `elseif (is_string($result) && str_starts_with($result, 'Error:'))` for the read, grep, glob and parse tools.

### Case 4: Expected results in tests, fixtures and evaluation datasets

Before (3.x):

```php
use NeuronAI\Tools\Toolkits\FileSystem\DeleteFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\ReadFileTool;

$this->assertSame("Error: File 'missing.txt' does not exist.", (new ReadFileTool())('missing.txt'));

$result = (new DeleteFileTool())('missing.txt');
$this->assertSame('error', $result['status']);
$this->assertSame("File 'missing.txt' does not exist.", $result['message']);
```

After (4.x):

```php
use NeuronAI\Tools\Toolkits\FileSystem\DeleteFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\ReadFileTool;
use NeuronAI\Tools\ToolOutput;

$result = (new ReadFileTool())('missing.txt');
$this->assertInstanceOf(ToolOutput::class, $result);
$this->assertTrue($result->isError());
$this->assertSame("File 'missing.txt' does not exist.", $result->getText());

$result = (new DeleteFileTool())('missing.txt');
$this->assertInstanceOf(ToolOutput::class, $result);
$this->assertTrue($result->isError());
$this->assertSame("File 'missing.txt' does not exist.", $result->getText());
```

1. In expected texts (search 3), apply the table in the Summary:
   - Drop the `Error: ` prefix.
   - Replace a failure status array with its `message`.
   - Replace a bash failure with `Command exited with code N.`, followed by a blank line and the output only when the 3.x `output` was not empty.
   - The `ParseFileTool` unsupported-format message no longer mentions `preview_file`.
2. Exact bash `output` expectations: stdout and stderr now appear in the order they were written, with no newline inserted between them. 3.x put all of stdout first, then stderr, with a newline between them when both were non-empty.
3. Exact `glob_path` expectations: the header and the no-match text quote the pattern as passed (`'**/*.php'`, not `'*.php'`), and a `**` segment in any position now matches recursively. Exact `grep_file_content` expectations on files with non-ASCII text: use the real line numbers.
4. A test that calls `EditFileTool` with a search string that occurs more than once now gets the error `The search string appears N times in '<file>'. Include more surrounding lines so it matches exactly once.`, and the file is left unchanged. Make the search string unique.
5. Guide 34 owns fixtures that hold serialized messages or tool calls, where an error result is stored as `{"is_error": true, "blocks": [...]}`.

### Case 5: RetrievalTool called directly or subclassed

Before (3.x):

```php
use NeuronAI\Tools\Toolkits\RetrievalTool;

$documents = (new RetrievalTool($retrieval))('refund policy');
foreach ($documents as $document) {
    $context[] = "[{$document->getSourceName()}] {$document->getContent()}";
    $ids[] = $document->getId();
}
```

After (4.x), when the content fields are enough:

```php
use NeuronAI\Tools\Toolkits\RetrievalTool;

$items = (new RetrievalTool($retrieval))('refund policy');
foreach ($items as $item) {
    $context[] = "[{$item['sourceName']}] {$item['content']}";
}
```

After (4.x), when the code needs `Document` objects (`getId()`, `getEmbedding()`), call the retrieval directly:

```php
use NeuronAI\Chat\Messages\UserMessage;

$documents = $retrieval->retrieve(new UserMessage('refund policy'));
foreach ($documents as $document) {
    $context[] = "[{$document->getSourceName()}] {$document->getContent()}";
    $ids[] = $document->getId();
}
```

1. Read items as arrays (`$item['content']`, `$item['sourceType']`, `$item['sourceName']`, `$item['score']`, `$item['metadata']`). This applies in subclasses that post-process `parent::__invoke()` too. `score` can be null.
2. If the code read Document properties (`$document->content`), use the array keys in the array form and the getters in the `retrieve()` form. Guide 20 migrates Document property reads elsewhere.
3. Code that JSON-decodes the `context_retrieval` result of a tool call finds no `id` or `embedding`. Ask the developer whether it can do without them. If it cannot, add them back in a `RetrievalTool` subclass: its `__invoke()` builds the arrays from `$this->retrieval->retrieve(new UserMessage($query))`, and the model then receives those fields too.

Guide 19 migrates custom `RetrievalInterface` implementations.

### Case 6: SESTool called with `reply_to`

Before (3.x):

```php
use NeuronAI\Tools\Toolkits\AWS\SESTool;

$sendEmail = new SESTool($ses, 'noreply@example.com');

$result = $sendEmail(
    to: ['customer@example.com'],
    subject: 'Your refund',
    body: '<p>Your refund was approved.</p>',
    reply_to: 'support@example.com',
);
```

After (4.x):

```php
use NeuronAI\Tools\Toolkits\AWS\SESTool;

$sendEmail = new SESTool($ses, 'noreply@example.com');

$result = $sendEmail(
    to: ['customer@example.com'],
    subject: 'Your refund',
    body: '<p>Your refund was approved.</p>',
);
```

1. Remove the `reply_to:` argument, which now fails with `Error: Unknown named parameter $reply_to`. Also remove a sixth positional argument, which is silently ignored. 3.x never used the value, so the email sent does not change. If the code implies the developer expected a Reply-To header, report that it was never set.
2. `cc` and `bcc` are now validated like `to`. A direct call that passes a display-name address (`Jane <jane@example.com>`) or any other address `filter_var(..., FILTER_VALIDATE_EMAIL)` rejects now gets the failed-result array (`'success' => false`), and nothing is sent. Pass bare addresses.

`SESTool` is deprecated in 4.x but still works, so it needs no further migration.

## Checklist

- [ ] No FileSystem tool result is checked through `['status']`, `['exit_code']` or an `Error:` prefix. Hits from search 1 that remain are on other tools.
- [ ] FileSystem failures are detected with `instanceof ToolOutput` (plus `->isError()` on `getResult()` values), and their text is read with `getText()`.
- [ ] Every app override of a FileSystem tool's `__invoke()` returns `array|ToolOutput` or `string|ToolOutput`.
- [ ] No subclass of a FileSystem tool declares a member named `scope`, `resolve`, `isAbsolute`, `canonicalize`, `contains`, `splitDrive` or `segments`, no `GlobPathTool` subclass declares `globstar` or `tree`, and no `FileSystemToolkit` subclass declares `scope`.
- [ ] The developer has answered whether code that reads history still reads results recorded by 3.x, and the string fallback is kept or left out to match.
- [ ] Test, fixture and dataset expectations use the 4.x texts: no `Error: ` prefix and no failure status arrays.
- [ ] `RetrievalTool` results are read as arrays, and nothing reads `id` or `embedding` from them.
- [ ] No `SESTool` call passes `reply_to`, and `cc`/`bcc` hold bare addresses.
- [ ] Searches 1 to 5 return only hits that were reviewed and left as they are.
