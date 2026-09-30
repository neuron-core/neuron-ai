# Upgrade: Document is a final object with accessors

## Summary

`NeuronAI\RAG\Document` is `final` in 4.x and all of its properties are `protected`. Reading or writing a property fails with `Error: Cannot access protected property NeuronAI\RAG\Document::$content`. `isset()`, `empty()` and `??` on these properties do not throw: they see no value (`isset($document->metadata['k'])` is `false`, `$document->score ?? 0.0` is `0.0`), so those reads fail silently and only search 2 finds them. Use the getters and setters instead. Every setter returns the document, so the calls chain. The getters that 3.x already had, plus `setScore()` and `addMetadata()`, keep their names.

| 3.x | 4.x |
|---|---|
| Public properties `id`, `content`, `embedding`, `sourceType`, `sourceName`, `score`, `metadata` | Getters and setters (Case 1) |
| `$document->content = $text` | No setter: content is set only through `new Document($text)` (Case 2) |
| `getEmbedding(): array`, `[]` until embedded | `getEmbedding(): ?array`, `null` until embedded. `setEmbedding(?array)` throws on `[]` and on non-numeric values (Case 3) |
| `getScore(): float`, `0.0` until scored | `getScore(): ?float`, `null` until scored. `setScore(?float)` (Case 3) |
| `json_encode($document)` writes `"embedding":[]` and `"score":0` when they are unset | Writes `"embedding":null` and `"score":null` (Case 3) |
| `class X extends Document`, PHPUnit or Mockery doubles of `Document` | Fatal error, because the class is final (Case 4) |
| Any metadata key. Any value through `$document->metadata` | `addMetadata()` and `setMetadata()` throw on empty and reserved keys, and `setMetadata()` also throws on integer keys. Storing a document throws when a metadata value is not null, a scalar, or an array of those (Case 5) |
| Stores accept a document that has no embedding | Every built-in store throws `VectorStoreException` (Case 6) |
| Some embedding paths embed a dynamic `$document->formattedContent` instead of the content | Ignored: providers always embed `getContent()` (Case 7) |

`NeuronAI\RAG\Schema\DocumentSchemaException` extends `NeuronAI\Exceptions\VectorStoreException`, so existing `catch (VectorStoreException $e)` blocks also catch it.

Stored data: 4.x reads the documents that 3.x wrote to vector stores. Guide 19 covers store formats and re-indexing. The exception is metadata stored under a reserved or numeric key (see [Stored data](#stored-data)).

PHPStan reports property access (`property.protected`) and subclasses (`class.extendsFinal`) only where it knows that a variable holds a `Document`. It misses untyped arrays, closures and Blade templates, so rely on the searches.

## What to Search For

Run these from the application root:

```bash
# 1. Files that import Document. This also shows aliases such as `use NeuronAI\RAG\Document as RagDocument`
grep -rnE 'NeuronAI\\RAG\\Document([^A-Za-z0-9_\\]|$)' --include='*.php' --exclude-dir=vendor .

# 2. Property reads and writes. --include='*.php' also covers Blade templates (*.blade.php)
grep -rnE -e '->(id|content|embedding|sourceType|sourceName|score|metadata)([^A-Za-z0-9_(]|$)' --include='*.php' --exclude-dir=vendor .

# 3. Subclasses, test doubles and formattedContent
grep -rnE 'extends +[A-Za-z0-9_\\]*Document([^A-Za-z0-9_]|$)|Document::class|formattedContent' --include='*.php' --exclude-dir=vendor .

# 4. Embedding and score reads
grep -rnE 'getEmbedding\(|getScore\(' --include='*.php' --exclude-dir=vendor .

# 5. Metadata writes, then reserved keys passed to addMetadata()
grep -rnE '(addMetadata|setMetadata)\(' --include='*.php' --exclude-dir=vendor .
grep -rnE "addMetadata\( *['\"](id|content|embedding|score|sourceType|sourceName|metadata|_neuron_metadata|_vectors|_rankingScore|vector_distance)['\"]" --include='*.php' --exclude-dir=vendor .

# 6. Documents written to a vector store
grep -rnE -e '->addDocuments?\(' --include='*.php' --exclude-dir=vendor .

# 7. Properties read by name: array_column(), get_object_vars(), (array) casts, data_get(), Laravel collections
grep -rnE "array_column\(|get_object_vars\(|\(array\) *\\\$|data_get\(|->(pluck|sortBy|sortByDesc|groupBy|keyBy|where|whereIn|firstWhere|sum|avg|max|min|unique)\( *['\"](id|content|embedding|sourceType|sourceName|score|metadata)" --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:
- Keep only the hits whose receiver is a `Document`. Documents come from these places:
  - `new Document(...)`
  - data loaders' `getDocuments()`
  - splitters' `splitDocument()` and `splitDocuments()`
  - embedding providers' `embedDocument()` and `embedDocuments()`
  - vector stores' `search()`
  - retrievals' `retrieve()`
  - post-processors' `process()`
  - the `documents` array of `DocumentsRetrievedEvent` and `DocumentsProcessedEvent`
  - the `documents` array of the 3.x observability events `Retrieved`, `PostProcessing` and `PostProcessed`. In observers, convert only the Document reads; guide 46 migrates the observers themselves.
- Check the `Document` parameters of app classes that do either of the following:
  - implement `PostProcessorInterface`, `SplitterInterface`, `RetrievalInterface`, `EmbeddingsProviderInterface`, `DataLoaderInterface` or `VectorStoreInterface`
  - extend `AbstractEmbeddingsProvider`, `AbstractSplitter` or `AbstractDataLoader`

  Guide 19 migrated custom vector stores. Convert any Document property access left in them here.
- Stream chunks, observability events, messages, Eloquent models and other objects also have `->content`, `->id`, `->score` or `->metadata`. Leave those alone. Since guide 7, `RetrievalTool` results are arrays, not Documents.
- Messages also have `addMetadata()` and `setMetadata()`. For search 5, only calls on Documents matter.
- In the files these searches found, check whether Documents are JSON-encoded: `json_encode()`, JSON API responses, cache or queue payloads. See Case 3, step 4.
- Twig templates need no change, because `doc.content` resolves to `getContent()`.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: Code that reads or writes Document properties

| 3.x | 4.x |
|---|---|
| `$document->id` | `$document->getId()` |
| `$document->id = $id` | `$document->setId($id)` |
| `$document->content` | `$document->getContent()` |
| `$document->embedding` | `$document->getEmbedding()` (can be null: Case 3) |
| `$document->embedding = $vector` | `$document->setEmbedding($vector)` (Case 3) |
| `$document->sourceType` | `$document->getSourceType()` |
| `$document->sourceType = $type` | `$document->setSourceType($type)` |
| `$document->sourceName` | `$document->getSourceName()` |
| `$document->sourceName = $name` | `$document->setSourceName($name)` |
| `$document->score` | `$document->getScore()` (can be null: Case 3) |
| `$document->score = $score` | `$document->setScore($score)` |
| `$document->metadata` | `$document->getMetadata()` |
| `$document->metadata = $metadata` | `$document->setMetadata($metadata)` (Case 5) |
| `$document->metadata['key']` | `$document->getMetadata()['key'] ?? null` (keep an existing `?? $default`) |
| `isset($document->metadata['key'])` | `isset($document->getMetadata()['key'])` |
| `$document->metadata['key'] = $value` | `$document->addMetadata('key', $value)` |
| `unset($document->metadata['key'])` | `$metadata = $document->getMetadata(); unset($metadata['key']); $document->setMetadata($metadata);` |
| Other in-place changes: `$document->metadata['tags'][] = $tag`, `$document->metadata += [...]`, `$document->score *= 2` | Read with the getter, change the local value, write it back with the setter |
| `$document->content = $text` | Case 2 |

`$document->getMetadata()['key'] = $value` runs without an error but changes nothing, because `getMetadata()` returns a copy. Write metadata with `addMetadata()` or `setMetadata()`.

Code that reads a property by its name (search 7) gets nothing in 4.x and raises no error. `array_column($documents, 'content')` returns `[]`. Laravel `pluck('content')`, `sortBy('score')`, `where('sourceType', ...)`, `sum('score')` and `data_get($document, 'metadata.url')` see `null`. `get_object_vars($document)`, `(array) $document` and `foreach ($document as ...)` see no public properties. Replace each with a closure over the getter: `array_map(fn (Document $d): string => $d->getContent(), $documents)`, `->map(fn (Document $d) => $d->getContent())`, `->sortByDesc(fn (Document $d) => $d->getScore())`, `->filter(fn (Document $d) => $d->getSourceType() === 'faq')`, `$d->getMetadata()['url'] ?? null`. To get all fields as an array, use `$document->jsonSerialize()`.

Before (3.x):

```php
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;

class FaqBoostPostProcessor implements PostProcessorInterface
{
    public function process(Message $question, array $documents): array
    {
        foreach ($documents as $document) {
            if ($document->sourceType === 'faq') {
                $document->score = $document->score * 1.2;
            }

            $document->metadata['boosted'] = $document->sourceType === 'faq';

            if (isset($document->metadata['draft'])) {
                unset($document->metadata['draft']);
            }
        }

        return $documents;
    }
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;

class FaqBoostPostProcessor implements PostProcessorInterface
{
    public function process(Message $question, array $documents): array
    {
        foreach ($documents as $document) {
            if ($document->getSourceType() === 'faq') {
                $document->setScore(($document->getScore() ?? 0.0) * 1.2);
            }

            $document->addMetadata('boosted', $document->getSourceType() === 'faq');

            if (isset($document->getMetadata()['draft'])) {
                $metadata = $document->getMetadata();
                unset($metadata['draft']);
                $document->setMetadata($metadata);
            }
        }

        return $documents;
    }
}
```

### Case 2: Code that changes a document's content

There is no content setter, so build a copy with the new content. 3.x changed the shared object in place, so arrays and callers saw the new text. Put the copy everywhere the original was used: the array element, the return value, the caller's variable. The copy keeps the embedding and the score, as the 3.x in-place change did.

Before (3.x):

```php
use NeuronAI\RAG\Document;

/**
 * @param Document[] $documents
 * @return Document[]
 */
function clean(array $documents): array
{
    foreach ($documents as $document) {
        $document->content = strip_tags($document->content);
    }

    return $documents;
}
```

After (4.x):

```php
use NeuronAI\RAG\Document;

/**
 * @param Document[] $documents
 * @return Document[]
 */
function clean(array $documents): array
{
    foreach ($documents as $index => $document) {
        $documents[$index] = (new Document(strip_tags($document->getContent())))
            ->setId($document->getId())
            ->setSourceType($document->getSourceType())
            ->setSourceName($document->getSourceName())
            ->setMetadata($document->getMetadata())
            ->setEmbedding($document->getEmbedding())
            ->setScore($document->getScore());
    }

    return $documents;
}
```

### Case 3: Code that relies on an empty embedding or a zero score

Before (3.x):

```php
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

function prepare(Document $document, EmbeddingsProviderInterface $embeddings, array $vectorFromDb): string
{
    if ($document->getEmbedding() === []) {
        $document->embedding = $embeddings->embedText($document->getContent());
    }

    $dimensions = count($document->getEmbedding());
    $relevance = round($document->getScore() * 100);

    $document->embedding = [];   // clear
    $document->embedding = $vectorFromDb;   // ['0.12', '0.34'] from a DB column, or []

    return "{$dimensions} {$relevance}";
}
```

After (4.x):

```php
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

function prepare(Document $document, EmbeddingsProviderInterface $embeddings, array $vectorFromDb): string
{
    if ($document->getEmbedding() === null) {
        $document->setEmbedding($embeddings->embedText($document->getContent()));
    }

    $dimensions = count($document->getEmbedding() ?? []);
    $relevance = round(($document->getScore() ?? 0.0) * 100);

    $document->setEmbedding(null);   // clear
    $document->setEmbedding($vectorFromDb === [] ? null : array_map('floatval', $vectorFromDb));

    return "{$dimensions} {$relevance}";
}
```

1. Replace embedding presence checks (`=== []`, `empty(...)`, `count(...) === 0`) with `=== null` or `!== null`.
2. Handle null before a `getEmbedding()` or `getScore()` value reaches `count()`, an `array` or `float` parameter, or arithmetic. `?? []` and `?? 0.0` reproduce the 3.x defaults exactly. `0.0` is a valid score, so test whether a score is present with `!== null`, not with truthiness.
3. Clear an embedding with `setEmbedding(null)`. These calls throw `DocumentSchemaException`:
   - `setEmbedding([])`: "Document embedding cannot be empty."
   - a vector that holds anything other than ints and floats: "Document embedding accepts numeric values only."

   Cast vectors read from a database or from JSON with `array_map('floatval', $vector)`. Integers are stored as floats.
4. `json_encode($document)` now writes `"embedding":null` and `"score":null` for unset values, where 3.x wrote `[]` and `0`. Update the consumers: API clients, front-end code, snapshot and fixture tests. Code that rebuilds Documents from JSON saved by 3.x must turn `"embedding":[]` into `null` before calling `setEmbedding()`.
5. When app code creates the documents that a RAG retrieves (a custom `RetrievalInterface` or post-processor), give each one a score with `setScore()`. `FixedThresholdPostProcessor` and `AdaptiveThresholdPostProcessor` now drop documents whose score is null; 3.x counted them as `0.0`.

### Case 4: Classes that extend Document, and test doubles

`class X extends Document` fails with `Class X cannot extend final class NeuronAI\RAG\Document`. Framework APIs accept and return plain `Document` objects. The 3.x built-in loaders, splitters and stores already returned plain `Document` objects, so only metadata ever survived storage.

1. Move each extra property of the subclass into a metadata key. Move the constructor logic into a static factory that returns `Document`.
2. Replace the subclass with `Document` in type hints, and replace its `new` calls with the factory. An `instanceof` check becomes a check of what the factory sets, such as the source type or a metadata key.
3. Overridden methods (`addMetadata()`, getters, `jsonSerialize()`) cannot be kept. Widening the `addMetadata()` parameter to 4.x's `mixed` does not help, because the class is final. Move their behaviour into the factory or the call sites.
4. The subclass may have behaviour beyond extra fields and construction, such as methods called across the app. In that case, ask the developer whether to wrap a `Document` in an app class instead (the app class holds the Document and hands it to framework APIs).

Test doubles: PHPUnit's `createMock()`, `createStub()` and `getMockBuilder()` fail with `Class "NeuronAI\RAG\Document" is declared "final" and cannot be doubled`. Mockery also refuses final classes. Use a real `Document` configured with setters.

Before (3.x):

```php
use NeuronAI\RAG\Document;
use PHPUnit\Framework\TestCase;

class ArticleDocument extends Document
{
    public function __construct(string $content, string $url)
    {
        parent::__construct($content);
        $this->sourceType = 'article';
        $this->addMetadata('url', $url);
    }

    public function getUrl(): string
    {
        return $this->metadata['url'];
    }
}

function cite(ArticleDocument $document): string
{
    return "{$document->getContent()} ({$document->getUrl()})";
}

echo cite(new ArticleDocument('Paris is the capital of France.', 'https://example.com/paris'));

class AnswerFormatterTest extends TestCase
{
    public function test_it_formats_documents(): void
    {
        $document = $this->createMock(Document::class);
        $document->method('getContent')->willReturn('Paris is the capital of France.');
        $document->method('getScore')->willReturn(0.9);

        $this->assertSame('Paris is the capital of France.', $document->getContent());
    }
}
```

After (4.x):

```php
use NeuronAI\RAG\Document;
use PHPUnit\Framework\TestCase;

final class ArticleDocument
{
    public static function make(string $content, string $url): Document
    {
        return (new Document($content))
            ->setSourceType('article')
            ->addMetadata('url', $url);
    }
}

function cite(Document $document): string
{
    return "{$document->getContent()} ({$document->getMetadata()['url']})";
}

echo cite(ArticleDocument::make('Paris is the capital of France.', 'https://example.com/paris'));

class AnswerFormatterTest extends TestCase
{
    public function test_it_formats_documents(): void
    {
        $document = (new Document('Paris is the capital of France.'))->setScore(0.9);

        $this->assertSame('Paris is the capital of France.', $document->getContent());
    }
}
```

### Case 5: Metadata keys and values

- `addMetadata()` and `setMetadata()` throw `DocumentSchemaException` for:
  - an empty key
  - the reserved keys in `NeuronAI\RAG\Schema\DocumentSchema::RESERVED_FIELDS`: `id`, `content`, `embedding`, `score`, `sourceType`, `sourceName`, `metadata`, `_neuron_metadata`, `_vectors`, `_rankingScore`, `vector_distance`
  - integer keys (`setMetadata()` only). This includes numeric strings such as `'2024'`, which PHP turns into integers. `addMetadata('2024', $value)` does not throw, but the key is stored as the integer `2024`: after that, `setMetadata($document->getMetadata())` throws, and every built-in store throws when it reads the document back. Treat numeric-string keys passed to `addMetadata()`, literal or built from a number, as integer keys and rename them.
- When a document is stored, every metadata value must be null, a string, an int, a float, a bool, or an array of those. Otherwise the call throws `DocumentSchemaException`. This applies to `RAG::addDocuments()`, `RAG::reindexBySource()`, and a store's `addDocument()` or `addDocuments()`. In 4.x, `addMetadata()` itself accepts `mixed`, so the error appears only when the document is stored.

1. Search 5 finds `addMetadata()` calls with a reserved key. Also review these places for reserved and integer keys:
   - `setMetadata()` calls
   - the metadata arrays built for documents in loaders, splitters and ingestion jobs
   - the `->metadata` writes found by search 2
   - `addMetadata()` calls whose key is numeric or built from a number
2. Rename each colliding key in the writer, and in every reader and filter: `getMetadata()['id']`, `Filter::eq('id', ...)`, the `DocumentSchema` fields from guide 19, templates. The new name is persisted and may be read outside the app (dashboards, other services), so propose a name such as `article_id` or `rating` and ask the developer to confirm it.
3. Convert values that are not JSON-safe before they reach the document:
   - `DateTimeInterface` to `->format(DATE_ATOM)`, or to `->getTimestamp()` when a filter compares the value with greater-than or less-than
   - a backed enum to `->value`
   - an object to an array of the fields its readers use

Before (3.x):

```php
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;

function ingest(RAG $rag, Article $article): void
{
    $document = new Document($article->body);
    $document->metadata = [
        'id' => $article->id,
        'score' => $article->rating,
        'published_at' => $article->publishedAt,   // DateTimeImmutable
        'status' => $article->status,              // backed enum
    ];

    $rag->addDocuments([$document]);
}

function articleId(Document $document): int
{
    return $document->metadata['id'];
}
```

After (4.x):

```php
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;

function ingest(RAG $rag, Article $article): void
{
    $document = (new Document($article->body))->setMetadata([
        'article_id' => $article->id,
        'rating' => $article->rating,
        'published_at' => $article->publishedAt->format(DATE_ATOM),
        'status' => $article->status->value,
    ]);

    $rag->addDocuments([$document]);
}

function articleId(Document $document): int
{
    return $document->getMetadata()['article_id'];
}
```

### Case 6: Documents stored without an embedding

`addDocument()` and `addDocuments()` on every built-in store now throw `VectorStoreException: Document <id> must have an embedding before it can be stored.` In 3.x, `MemoryVectorStore` and `FileVectorStore` accepted such documents and failed only at search time, and the other stores sent an empty vector. `FakeVectorStore` applies the same rule; guide 56 covers the testing fakes.

Ingest through `RAG::addDocuments()`, which embeds the documents, or embed them yourself before calling the store. Tests that seed a store can embed with `FakeEmbeddingsProvider`, or set a vector with `setEmbedding([...])`.

Before (3.x):

```php
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

/** @param Document[] $documents */
function ingest(RAG $rag, VectorStoreInterface $store, EmbeddingsProviderInterface $embeddings, array $documents): void
{
    $chunks = (new DelimiterTextSplitter(maxLength: 800))->splitDocuments($documents);

    $rag->resolveVectorStore()->addDocuments($chunks);   // through a RAG
    $store->addDocuments($chunks);                       // standalone store

    $memory = new MemoryVectorStore();                   // test seeding
    $memory->addDocument(new Document('Paris is the capital of France.'));
}
```

After (4.x):

```php
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

/** @param Document[] $documents */
function ingest(RAG $rag, VectorStoreInterface $store, EmbeddingsProviderInterface $embeddings, array $documents): void
{
    $chunks = (new DelimiterTextSplitter(maxLength: 800))->splitDocuments($documents);

    $rag->addDocuments($chunks);                                  // embeds, then stores
    $store->addDocuments($embeddings->embedDocuments($chunks));

    $memory = new MemoryVectorStore();
    $memory->addDocument($embeddings->embedDocument(new Document('Paris is the capital of France.')));
}
```

### Case 7: Code that sets `formattedContent`

The 4.x built-in providers always embed `getContent()`, and nothing in the framework reads `formattedContent`. An embeddings provider of the app's own that reads `$document->formattedContent` keeps working as in 3.x, because PHP only deprecates dynamic properties. Leave that provider and the code that sets the property unchanged. In 3.x, the built-in providers used it only on some paths:

| 3.x path | Was `formattedContent` embedded? |
|---|---|
| `embedDocument()` on any built-in provider | Yes |
| `embedDocuments()`, `RAG::addDocuments()` or `RAG::reindexBySource()` with `OllamaEmbeddingsProvider`, `GeminiEmbeddingsProvider`, `AwsBedrockEmbeddingsProvider`, `FakeEmbeddingsProvider`, or an app provider that extends `AbstractEmbeddingsProvider` without overriding `embedDocuments()` | Yes |
| `embedDocuments()`, `RAG::addDocuments()` or `RAG::reindexBySource()` with `OpenAIEmbeddingsProvider`, `OpenAILikeEmbeddings`, `MistralEmbeddingsProvider`, `VoyageEmbeddingsProvider` or `CohereEmbeddingsProvider` | No, the content was embedded |

1. On a path where 3.x ignored it, delete the assignment. Tell the developer that the formatted text was never embedded, and ask whether they want it embedded now. That would change retrieval, so existing collections would need re-ingesting.
2. On a path where 3.x embedded it, embed the formatted text yourself. Then store the documents without embedding them again: `RAG::addDocuments()` would re-embed the content, so store through `resolveVectorStore()`.
3. `RAG::reindexBySource()` always re-embeds the content. If the app passed it documents with `formattedContent` on a "Yes" path, ask the developer which of these to do:
   - make the formatted text the document content. It is then also stored and sent to the model.
   - replace the call: delete the source with the store's `delete()` (guide 19), then store self-embedded documents as in step 2.

Before (3.x), with a provider from a "Yes" row:

```php
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;

/** @param Document[] $documents */
function ingest(RAG $rag, array $documents, string $title): void
{
    foreach ($documents as $document) {
        $document->formattedContent = $title . "\n\n" . $document->content;
    }

    $rag->addDocuments($documents);
}
```

After (4.x):

```php
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;

/** @param Document[] $documents */
function ingest(RAG $rag, array $documents, string $title): void
{
    $embeddings = $rag->resolveEmbeddingsProvider();

    foreach ($documents as $document) {
        $document->setEmbedding($embeddings->embedText($title . "\n\n" . $document->getContent()));
    }

    $rag->resolveVectorStore()->addDocuments($documents);
}
```

## Stored data

- Vector stores: 4.x reads the documents that 3.x stored, and guide 19 covers formats and re-indexing. Two kinds of metadata keys are exceptions:
  - Reserved keys (Case 5): 4.x never returns metadata that 3.x stored under a reserved key. The built-in stores skip the key when they read the document. `FileVectorStore::search()` throws `DocumentSchemaException` (`Document metadata field "id" is reserved by the framework.`) when such a row is among the results.
  - Numeric keys, such as `2024`: reading a document that has one throws a `TypeError` or a `DocumentSchemaException`.

  If the app wrote either kind of key, tell the developer that those sources must be re-ingested after the Case 5 rename, for example with `RAG::reindexBySource()` on the original sources. Do not re-ingest yourself.
- JSON the app saved with `json_encode($document)`: see Case 3, step 4.

## Checklist

- Search 7 finds no Document property read by name.
- Searches 2 and 3 find no Document property access, no class extending `Document`, no test double of `Document`, and no `formattedContent` except in an app embeddings provider that reads it and in the code that sets it for that provider (Case 7).
- Every content change builds a new `Document` and puts it where the old one was used.
- Embedding and score checks handle `null`. No `setEmbedding([])` remains. Vectors read from storage are cast to floats.
- Consumers of Document JSON accept `null` for `embedding` and `score`.
- No empty, integer or reserved metadata key is written. Renamed keys are updated in every reader and filter, and the developer confirmed the new names.
- Metadata values that reach a store are null, scalars, or arrays of those.
- Every `addDocument()` and `addDocuments()` call on a store receives embedded documents.
- The developer was told about renamed keys, sources to re-ingest, and any `formattedContent` that 3.x never embedded.
- PHPStan shows no `property.protected` or `class.extendsFinal` error on `NeuronAI\RAG\Document`.
