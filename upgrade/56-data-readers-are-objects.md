# Upgrade: Data readers are objects

## Summary

In 3.x `ReaderInterface` declared a static method, `getText(string $filePath, array $options = [])`. `FileDataLoader`
received reader objects but called the static method on their class, so a reader's configuration never reached it:
`PdfReader::getText()` built a fresh `PdfReader`, dropping the binary path, the options and the timeout of the reader
you registered. Where `pdftotext` existed only at the configured path the load failed, and where a system `pdftotext`
existed it silently replaced the configured one.

- **`ReaderInterface` declares an instance method**, `read(string $filePath): string`. `FileDataLoader` calls it on the
  reader you registered, so the reader's configuration applies.
- **The static `getText()` is removed** from `PdfReader`, `HtmlReader` and `TextFileReader`. Build the reader,
  configure it with its constructor and setters, and call `read()`.
- **`PdfReader::read()` works on a copy**, so one configured reader serves every file of a loader. `setPdf()` and
  `text()` are unchanged.
- **`PdfReader` runs the executable you configure, whatever its name.** In 3.x it ran a file named `pdftotext` next to
  it, or a system `pdftotext` in its place. The bin path must be that executable: a directory is rejected. `pdfinfo`,
  used by `getPageCount()`, is still looked up next to it first.

| Before (3.x) | After |
|---|---|
| `public static function getText(string $filePath, array $options = []): string` | `public function read(string $filePath): string` |
| `PdfReader::getText($path, ['binPath' => $bin, 'options' => ['layout'], 'timeout' => 120])` | `(new PdfReader($bin))->setOptions(['layout'])->setTimeout(120)->read($path)` |
| `HtmlReader::getText($path)`, `TextFileReader::getText($path)` | `(new HtmlReader())->read($path)`, `(new TextFileReader())->read($path)` |
| `addReader('pdf', new PdfReader($bin))` runs the system `pdftotext` when there is one | Runs `$bin` |
| `new PdfReader('/opt/poppler/bin')`, a directory | `DataReaderException`: `The provided path is not executable.` |

## How to Refactor

### Case 1: A reader implementing `ReaderInterface`

A reader written for 3.x no longer loads. PHP stops as soon as the class is used:

```
Class MarkdownReader contains 1 abstract method and must therefore be declared abstract or implement the remaining
methods (NeuronAI\RAG\DataLoader\ReaderInterface::read)
```

Turn the static method into `read()`:

Before:

```php
class MarkdownReader implements ReaderInterface
{
    public static function getText(string $filePath, array $options = []): string
    {
        return file_get_contents($filePath);
    }
}
```

After:

```php
class MarkdownReader implements ReaderInterface
{
    public function read(string $filePath): string
    {
        return file_get_contents($filePath);
    }
}
```

`FileDataLoader` never passed `$options`, so a reader used through the loader cannot have relied on it. Settings a
reader needs, such as a path or a client, now go in its constructor.

### Case 2: A class extending a built-in reader that overrides `getText()`

Nothing fails here, which makes this case easy to miss: the class still loads, but the loader calls `read()`, which the
class inherits, so the override is **silently skipped**. Configure the reader in its constructor instead:

Before:

```php
class LayoutPdfReader extends PdfReader
{
    public static function getText(string $filePath, array $options = []): string
    {
        return parent::getText($filePath, ['options' => ['layout']]);
    }
}
```

After:

```php
class LayoutPdfReader extends PdfReader
{
    public function __construct(?string $binPath = null)
    {
        parent::__construct($binPath);
        $this->setOptions(['layout']);
    }
}
```

Or drop the subclass and register `(new PdfReader())->setOptions(['layout'])`. An override with its own extraction logic
moves that logic into `read()`.

### Case 3: Static calls to `getText()`

`PdfReader::getText()`, `HtmlReader::getText()` and `TextFileReader::getText()` no longer exist: the call fails with
`Call to undefined method` when it runs. Build the reader and call `read()`, moving the options array to the
constructor and setters:

Before:

```php
$text = PdfReader::getText($path, ['binPath' => '/opt/poppler/bin/pdftotext', 'options' => ['layout'], 'timeout' => 120]);
```

After:

```php
$text = (new PdfReader('/opt/poppler/bin/pdftotext'))->setOptions(['layout'])->setTimeout(120)->read($path);
```

### Case 4: A `PdfReader` configured with a directory

In 3.x a directory passed as the bin path was accepted, and extraction then found a `pdftotext` elsewhere, often the
system one. The constructor and `setBinPath()` now throw. Pass the executable itself:

Before:

```php
new PdfReader('/opt/poppler/bin');
```

After:

```php
new PdfReader('/opt/poppler/bin/pdftotext');
```

## What to Search For

```
grep -rn "ReaderInterface" --include="*.php" .
grep -rnE "extends (PdfReader|HtmlReader|TextFileReader)\b" --include="*.php" .
grep -rnE "static function getText\(|::getText\(" --include="*.php" .
grep -rnE "new PdfReader\(|setBinPath\(" --include="*.php" .
```

## Checklist

- Every class implementing `ReaderInterface` declares `public function read(string $filePath): string` and no static
  `getText()`.
- Every class extending `PdfReader`, `HtmlReader` or `TextFileReader` that overrode `getText()` now overrides `read()`
  or is configured in its constructor.
- No code calls `getText()` statically on a reader, and options once passed as an array are set with the constructor
  and setters.
- Every bin path given to `PdfReader` is the `pdftotext` executable, not its directory.
