# Upgrade: Tool Class Is Now Abstract

## Summary

The `Tool` class (`NeuronAI\Tools\Tool`) is now `abstract`. It cannot be instantiated directly or used via `Tool::make(...)`. All tools must be concrete classes extending `Tool`.

The `Tool` constructor has been removed. Tool `name` and `description` are now set as class properties, not via `parent::__construct()`.

## What to Search For

Search the entire application codebase for these patterns:

1. **Direct instantiation** — any file importing or referencing `Tool` that calls `Tool::make(...)` or `new Tool(...)`:

```
grep -rn "Tool::make(" --include="*.php" .
grep -rn "new Tool(" --include="*.php" .
```

2. **Inline closures with `->setCallable()`** — the old fluent pattern chained `setCallable()` onto a `Tool::make(...)` call. This pattern is gone:

```
grep -rn "->setCallable(" --include="*.php" .
```

3. **Subclasses calling `parent::__construct()`** — existing Tool subclasses that pass name/description to the parent constructor:

```
grep -rn "parent::__construct(" --include="*.php" .
```

Also check for `use NeuronAI\Tools\Tool;` imports to find files that interact with the Tool class.

## Refactoring Instructions

### Case 1: Inline `Tool::make(...)` with `setCallable()`

This is the most common pattern and the highest priority to fix.

**Before:**

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

class MyAgent extends Agent
{
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
                    required: true
                )
            )->setCallable(function (string $video_url) {
                return "Video transcription...";
            }),
        ];
    }
}
```

**After — create a dedicated Tool class:**

1. Create a new PHP class file (e.g., `Tools/GetTranscriptionTool.php`) that extends `Tool`:

```php
<?php

declare(strict_types=1);

namespace App\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

class GetTranscriptionTool extends Tool
{
    protected string $name = 'get_transcription';

    protected ?string $description = 'Retrieve the transcription of a YouTube video.';

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'video_url',
                type: PropertyType::STRING,
                description: 'The URL of the YouTube video.',
                required: true
            ),
        ];
    }

    public function __invoke(string $video_url): string
    {
        return "Video transcription...";
    }
}
```

2. Update the agent's `tools()` method to instantiate the new class:

```php
use App\Tools\GetTranscriptionTool;

class MyAgent extends Agent
{
    protected function tools(): array
    {
        return [
            new GetTranscriptionTool(),
        ];
    }
}
```

If the original closure captured variables from the agent scope (e.g., `$this` or local variables), pass those values through the tool's constructor:

```php
class GetTranscriptionTool extends Tool
{
    protected string $name = 'do_something';

    protected ?string $description = 'Does something with a dependency.';

    public function __construct(
        protected SomeService $service,
    ) {}

    protected function properties(): array
    {
        return [
            // ...
        ];
    }

    public function __invoke(string $input): string
    {
        return $this->service->handle($input);
    }
}
```

Then in the agent:

```php
new GetTranscriptionTool($this->service),
```

### Case 2: Existing Tool subclass calling `parent::__construct()`

**Before:**

```php
class GetTranscriptionTool extends Tool
{
    public function __construct(protected string $key)
    {
        parent::__construct(
            'get_transcription',
            'Retrieve the transcription of a YouTube video.',
        );
    }

    // ...
}
```

**After — remove the `parent::__construct()` call and set properties directly:**

```php
class GetTranscriptionTool extends Tool
{
    protected string $name = 'get_transcription';

    protected ?string $description = 'Retrieve the transcription of a YouTube video.';

    public function __construct(protected string $key)
    {
        // No parent::__construct() call needed
    }

    // ...
}
```

The constructor is now only for injecting external dependencies. Tool identity (`name`, `description`) is declared as class properties.

## Checklist

For each file you modify:

- [ ] The new Tool subclass has `protected string $name = '...';`
- [ ] The new Tool subclass has `protected ?string $description = '...';`
- [ ] The `properties()` method returns the same properties that were previously chained via `addProperty()`
- [ ] The `__invoke()` method has the same signature and logic as the original closure/callback
- [ ] No call to `parent::__construct()` remains in the tool class
- [ ] The agent's `tools()` method instantiates the new class instead of using `Tool::make()`
- [ ] All imports are updated: remove unused `Tool` import if the agent no longer references it directly, add the new Tool class import
- [ ] The file has `declare(strict_types=1);`
