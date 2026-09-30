# Upgrade: Data readers are objects: ReaderInterface::read() replaces static getText()

## Summary

`ReaderInterface::getText()` was static. Readers are now objects with the instance method `read()`, and
`FileDataLoader` calls it on the reader you registered, so the configuration from the constructor and setters applies.

- The static `getText()` is removed from `PdfReader`, `HtmlReader` and `TextFileReader`. Build the reader, configure it
  with its constructor and setters, then call `read()`.
- `FileDataLoader` accepts reader instances only. A class name passed as a reader throws a `TypeError`.
- `PdfReader` runs the bin path you configure as `pdftotext`, so it must be the `pdftotext` executable itself. A
  directory is rejected.
- Readers store nothing, so no stored data or database migration is involved.

| 3.x | 4.x |
|---|---|
| `public static function getText(string $filePath, array $options = []): string` | `public function read(string $filePath): string` |
| `PdfReader::getText($path, ['binPath' => $bin, 'options' => ['layout'], 'timeout' => 120])` | `(new PdfReader($bin))->setOptions(['layout'])->setTimeout(120)->read($path)` |
| `HtmlReader::getText($path)`, `TextFileReader::getText($path)` | `(new HtmlReader())->read($path)`, `(new TextFileReader())->read($path)` |
| `$reader->getText($path)`, `$reader::getText($path)` | `$reader->read($path)` |
| `FileDataLoader::for($dir, ['pdf' => PdfReader::class])` | `FileDataLoader::for($dir, ['pdf' => new PdfReader()])` |
| `new PdfReader('/opt/poppler/bin')` (a directory) | `new PdfReader('/opt/poppler/bin/pdftotext')` |

## What to Search For

Run from the application root:

```bash
# 1. Every file that names a reader: implementations, subclasses, imports, calls, registrations
grep -rnE 'ReaderInterface|PdfReader|HtmlReader|TextFileReader' --include='*.php' --exclude-dir=vendor .

# 2. getText() declarations, and getText() calls that take an argument (static or through an instance)
grep -rnE 'static function getText[(]|(::|->)getText[(]([[:space:]]*[^)[:space:]]|[[:space:]]*$)' --include='*.php' --exclude-dir=vendor .

# 3. Every data loader, to check what it is given as readers
grep -rnE 'FileDataLoader|setReaders[(]' --include='*.php' --exclude-dir=vendor .

# 4. Only if PdfReader is used: bin path values in code, config, .env and deployment files
grep -rniE 'pdftotext|poppler|binPath' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git .
```

Follow the hits:

- For every `use NeuronAI\RAG\DataLoader\... as Alias;`, search for `Alias` too.
- For every class found that implements `ReaderInterface` or extends a reader, search for its own name: its subclasses,
  its instantiations, its `::class` registrations.
- For every `new PdfReader(...)` or `setBinPath(...)` whose argument is a variable, config or env call, trace the value
  to where it is set.
- The reader `getText()` always took a path. `ToolOutput::getText()` and `Html2Text::getText()` take no argument, so
  pattern 2 skips them. Leave any other hit that is not called on a reader alone.

If none of the patterns finds a reader, this guide does not apply.

## How to Refactor

### Case 1: A class implementing `ReaderInterface`

In 4.x the class no longer loads:
`Class MarkdownReader contains 1 abstract method and must therefore be declared abstract or implement the remaining methods (NeuronAI\RAG\DataLoader\ReaderInterface::read)`.

Before (3.x):

```php
use NeuronAI\RAG\DataLoader\ReaderInterface;

class MarkdownReader implements ReaderInterface
{
    public static function getText(string $filePath, array $options = []): string
    {
        return file_get_contents($filePath);
    }
}
```

After (4.x):

```php
use NeuronAI\RAG\DataLoader\ReaderInterface;

class MarkdownReader implements ReaderInterface
{
    public function read(string $filePath): string
    {
        return file_get_contents($filePath);
    }
}
```

1. Replace the static `getText()` with `public function read(string $filePath): string` and keep its body.
2. `FileDataLoader` never passed `$options`. If direct callers passed options, turn each one into a constructor
   parameter and update those callers (Case 3).
3. If the class already has another method named `read`, rename that method and its callers first.

### Case 2: A subclass of `PdfReader`, `HtmlReader` or `TextFileReader` that overrides `getText()`

The subclass still loads, but `FileDataLoader` calls the inherited `read()`, so the override is silently skipped. A
direct call to an override that calls `parent::getText()` fails.

If the override only configured the reader, configure it in the constructor.

Before (3.x):

```php
use NeuronAI\RAG\DataLoader\PdfReader;

class LayoutPdfReader extends PdfReader
{
    public static function getText(string $filePath, array $options = []): string
    {
        return parent::getText($filePath, ['options' => ['layout']]);
    }
}
```

After (4.x):

```php
use NeuronAI\RAG\DataLoader\PdfReader;

class LayoutPdfReader extends PdfReader
{
    public function __construct(?string $binPath = null)
    {
        parent::__construct($binPath);
        $this->setOptions(['layout']);
    }
}
```

When nothing else uses the subclass, you can drop it and register `(new PdfReader())->setOptions(['layout'])` instead.

If the override has its own logic, move it into `read()`.

Before (3.x):

```php
use NeuronAI\RAG\DataLoader\PdfReader;

class PagedPdfReader extends PdfReader
{
    public static function getText(string $filePath, array $options = []): string
    {
        return self::splitPages(parent::getText($filePath, $options));
    }

    protected static function splitPages(string $text): string
    {
        return str_replace("\f", "\n\n", $text);
    }
}
```

After (4.x):

```php
use NeuronAI\RAG\DataLoader\PdfReader;

class PagedPdfReader extends PdfReader
{
    public function read(string $filePath): string
    {
        return self::splitPages(parent::read($filePath));
    }

    protected static function splitPages(string $text): string
    {
        return str_replace("\f", "\n\n", $text);
    }
}
```

1. Replace `public static function getText(string $filePath, array $options = []): string` with
   `public function read(string $filePath): string`.
2. Replace `parent::getText($filePath, ...)` with `parent::read($filePath)`, and `self::getText(` or `static::getText(`
   with `$this->read(`. The rest of the body, including calls to static helpers, stays as it is.
3. `parent::read()` takes no options. Apply the options the body passed to the parent, or set on its own
   `new static()` instance, with `setBinPath()`, `setOptions()` or `setTimeout()` in the constructor, as in the first
   example.

### Case 3: Calls to `getText()` on a reader

Both static calls (`PdfReader::getText($path, ...)`) and calls through an instance (`$reader->getText($path)`,
`(new PdfReader($bin))->getText($path)`, `$reader::getText($path)`) now fail with
`Call to undefined method ...::getText()`.

Before (3.x):

```php
use NeuronAI\RAG\DataLoader\HtmlReader;
use NeuronAI\RAG\DataLoader\PdfReader;
use NeuronAI\RAG\DataLoader\TextFileReader;

$text = PdfReader::getText($path, ['binPath' => '/opt/poppler/bin/pdftotext', 'options' => ['layout'], 'timeout' => 120]);
$text = HtmlReader::getText($path);
$text = TextFileReader::getText($path);

$reader = new PdfReader('/opt/poppler/bin/pdftotext');
$text = $reader->getText($path);
```

After (4.x):

```php
use NeuronAI\RAG\DataLoader\HtmlReader;
use NeuronAI\RAG\DataLoader\PdfReader;
use NeuronAI\RAG\DataLoader\TextFileReader;

$text = (new PdfReader('/opt/poppler/bin/pdftotext'))->setOptions(['layout'])->setTimeout(120)->read($path);
$text = (new HtmlReader())->read($path);
$text = (new TextFileReader())->read($path);

$reader = new PdfReader('/opt/poppler/bin/pdftotext');
$text = $reader->read($path);
```

1. Static call: build the reader and call `read()`. Move each key of the `PdfReader` options array to its setter:
   `'binPath'` to the constructor (or `setBinPath()`), `'options'` to `setOptions()`, `'timeout'` to `setTimeout()`.
   Skip the keys the call did not pass.
2. Call through an instance: call `read()` on the same instance. If the call also passed an options array, apply its
   keys with the setters first.
3. An application reader (Case 1 or 2) called as `MyReader::getText($path, $options)` becomes
   `(new MyReader(...))->read($path)`, with its options passed to the constructor.

In 3.x a call through an instance ignored that instance's bin path, options and timeout. `read()` uses them, so check
its bin path with Case 4.

`setPdf($path)->text()` and `getPageCount()` on `PdfReader` are unchanged.

### Case 4: The `PdfReader` bin path

The bin path is the constructor argument, the `setBinPath()` argument, or the former `'binPath'` key. It must be the
`pdftotext` executable file itself:

- A directory throws `NeuronAI\Exceptions\DataReaderException` `The provided path is not executable.` from the
  constructor or `setBinPath()`.
- Any other file, such as `/usr/bin/pdfinfo` or a wrapper with a different name, is now executed as `pdftotext`. In 3.x
  only its directory was used, to find `pdftotext` there.

Readers registered on a `FileDataLoader` ran without their bin path in 3.x, so a wrong value there never showed up.
Check every `PdfReader`, including the ones you only register.

Before (3.x):

```php
use NeuronAI\RAG\DataLoader\PdfReader;

$reader = new PdfReader('/opt/poppler/bin');
```

After (4.x):

```php
use NeuronAI\RAG\DataLoader\PdfReader;

$reader = new PdfReader('/opt/poppler/bin/pdftotext');
```

1. Fix literal paths in code, and values committed in the repository such as config defaults and `.env.example`.
2. If the path is a wrapper script or another binary, ask the developer whether it accepts `pdftotext`'s arguments
   (`[options] <pdf> -`) and prints the text. If it does, keep it. Otherwise point to `pdftotext`.
3. If the value is set outside the repository (a deployed `.env`, secrets, CI variables), report to the developer that
   it must be the full path to the `pdftotext` executable. If some environment holds a directory, ask whether to fix
   those values or to append `/pdftotext` where the code reads the value.

### Case 5: Readers given to `FileDataLoader` as class names

In 3.x the readers array passed to the `FileDataLoader` constructor, `for()` or `setReaders()` could hold class names. In
4.x every entry must be a `ReaderInterface` instance, or the loader throws
`TypeError: NeuronAI\RAG\DataLoader\FileDataLoader::addReader(): Argument #2 ($reader) must be of type NeuronAI\RAG\DataLoader\ReaderInterface, string given`.

Before (3.x):

```php
use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\DataLoader\HtmlReader;
use NeuronAI\RAG\DataLoader\PdfReader;

$documents = FileDataLoader::for($directory, [
    'pdf' => PdfReader::class,
    'html' => HtmlReader::class,
])->getDocuments();
```

After (4.x):

```php
use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\DataLoader\HtmlReader;
use NeuronAI\RAG\DataLoader\PdfReader;

$documents = FileDataLoader::for($directory, [
    'pdf' => new PdfReader(),
    'html' => new HtmlReader(),
])->getDocuments();
```

Apply the same change to arrays passed to `new FileDataLoader(...)` and `setReaders()`, including arrays built in config
or a service container.

## Checklist

- Every class implementing `ReaderInterface` declares `public function read(string $filePath): string` and no
  `getText()`.
- No subclass of `PdfReader`, `HtmlReader` or `TextFileReader` declares `getText()`. Its logic lives in `read()`, and
  its options are set in the constructor.
- No code calls `getText()` on a reader, statically or through an instance. Pattern 2 returns no reader call.
- Former options arrays are constructor arguments or setter calls.
- Every reader given to `FileDataLoader` (constructor, `for()`, `setReaders()`, `addReader()`) is an instance, not a
  class name.
- Every `PdfReader` bin path is the `pdftotext` executable itself, not its directory or another binary. Values set
  outside the repository are reported to the developer.
- If the application runs PHPStan, it reports no reader `getText()` or missing `read()` error. PHPStan does not catch
  Cases 4 and 5, nor a `getText()` override that never calls `parent::getText()`.
