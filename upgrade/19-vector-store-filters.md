# Upgrade: Vector stores take a SearchRequest, delete by filter, and filter with portable expressions

## Summary

| 3.x | 4.x |
|---|---|
| `$store->similaritySearch($embedding)` | `$store->search(new SearchRequest($embedding))` |
| `$store->deleteBy($type, $name)` and the deprecated `$store->deleteBySource($type, $name)` (every store and `FakeVectorStore`) | `$store->delete(Filter::where('sourceType', $type)->where('sourceName', $name))` |
| `NeuronAI\RAG\VectorStore\DeleteByInterface` | removed: `delete()` is declared on `VectorStoreInterface` |
| `withFilters(array $native)` on `ElasticsearchVectorStore`, `OpenSearchVectorStore`, `PineconeVectorStore`, `QdrantVectorStore`, `MeilisearchVectorStore`, kept on the store for every later search | removed: pass a `FilterExpression` with each search, or give the RAG a retrieval scope (`retrievalScope()` / `setRetrievalScope()`) |
| a filter could address any stored metadata field | a filter on a field other than `sourceType` or `sourceName` throws `NeuronAI\RAG\Schema\DocumentSchemaException` (`Filter field "tenant" is not declared in the vector store document schema.`) unless the store was built with a `DocumentSchema` that declares the field `filterable()` |
| `VectorStoreInterface`: `addDocument`, `addDocuments`, `deleteBySource`, `deleteBy`, `similaritySearch` | `getSchema`, `addDocument`, `addDocuments`, `delete`, `search` |
| `RetrievalInterface::retrieve(Message $query): array` | `retrieve(Message $query, ?FilterExpression $filters = null): array` |
| `FileVectorStore` accepted a `name` containing a path | `name` must be a plain file name |

The schema check is active as soon as `vendor/` holds 4.x, so filters and their schema are migrated together in this
guide. Imports used by the After code:

```php
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentField;
```

Every built-in store reads documents written by 3.x as they are. Weaviate, Typesense, Elasticsearch and OpenSearch
indexes that hold 3.x data need a check: see "Data stored by 3.x".

Other guides: guide 20 migrates `Document` property access (`$document->content`, `->metadata`, ...), guide 26 migrates
RAG graph hooks and nodes (`ragNodes()`), guide 56 migrates `FakeVectorStore` recordings and assertions, guide 57
migrates RAG setters and the final `resolve*()` methods, guide 2 migrates the HTTP client members of store subclasses.

## What to Search For

Run from the application root:

```bash
# 1. Removed methods and interface: calls, overrides, implementations
grep -rnE 'similaritySearch|withFilters|deleteBy(Source)?\(|DeleteByInterface' --include='*.php' --exclude-dir=vendor .
# 2. Custom stores and retrieval strategies, subclasses of built-in stores and of SimilarityRetrieval
grep -rnE 'VectorStoreInterface|RetrievalInterface|extends +\\?([A-Za-z_]+\\)*[A-Za-z_]*(VectorStore|SimilarityRetrieval)\b' --include='*.php' --exclude-dir=vendor .
# 3. Store constructions, hand-built retrieval, RAG node overrides
grep -rnE 'new +\\?([A-Za-z_]+\\)*[A-Za-z_]*(VectorStore|SimilarityRetrieval|RetrievalNode)\(|FakeVectorStore::make\(|function +ragNodes\(' --include='*.php' --exclude-dir=vendor .
```

Follow the hits:

- Pattern 1 does not match an Elasticsearch/OpenSearch client's `->deleteByQuery(`. A `withFilters()` hit on an
  object that is not a Neuron store (for example an application query builder) is unrelated.
- Pattern 2 also lists every `vectorStore()` / `retrieval()` hook. For every store or strategy class it finds, find
  where it is constructed, bound in a service container, or passed to `setVectorStore()` / `setRetrieval()`.
- If an import aliases a store (`use NeuronAI\RAG\VectorStore\QdrantVectorStore as Qdrant;`), search for the alias too.
- Include tests: `FakeVectorStore` had `similaritySearch()`, `deleteBy()` and `deleteBySource()` too.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: Direct searches

Before (3.x):

```php
$documents = $store->similaritySearch($embedding);
```

After (4.x):

```php
use NeuronAI\RAG\VectorStore\SearchRequest;

$documents = $store->search(new SearchRequest($embedding));

// A per-call result limit; null (the default) keeps the store's constructor topK
$documents = $store->search(new SearchRequest($embedding, topK: 8));
```

- The result is still `iterable<Document>`.
- `SearchRequest` throws `NeuronAI\Exceptions\VectorStoreException` for an empty or non-numeric embedding and for a
  `topK` below 1. A test that searched a `FakeVectorStore` with `[]` must pass a non-empty vector, such as `[0.1, 0.2]`.
- `FakeVectorStore` calls migrate the same way, here and in Case 2.

### Case 2: Deletes

Before (3.x):

```php
$store->deleteBySource($sourceType, $sourceName);
$store->deleteBy($sourceType, $sourceName);
$store->deleteBy($sourceType);
```

After (4.x):

```php
use NeuronAI\RAG\VectorStore\Filter\Filter;

$store->delete(Filter::where('sourceType', $sourceType)->where('sourceName', $sourceName));
$store->delete(Filter::where('sourceType', $sourceType)->where('sourceName', $sourceName));
$store->delete(Filter::eq('sourceType', $sourceType));
```

Filter values must be non-null scalars. When `$sourceName` can be null at runtime:

```php
$store->delete($sourceName === null
    ? Filter::eq('sourceType', $sourceType)
    : Filter::where('sourceType', $sourceType)->where('sourceName', $sourceName));
```

`DeleteByInterface` in a type hint or an `instanceof` check becomes `VectorStoreInterface`. Remove
`implements DeleteByInterface` and its import.

Before (3.x):

```php
use NeuronAI\RAG\VectorStore\DeleteByInterface;

function purgeSource(DeleteByInterface $store, string $sourceType): void
{
    $store->deleteBy($sourceType);
}
```

After (4.x):

```php
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

function purgeSource(VectorStoreInterface $store, string $sourceType): void
{
    $store->delete(Filter::eq('sourceType', $sourceType));
}
```

### Case 3: withFilters()

Two steps: translate the native filter into a `FilterExpression`, then put the expression where the 3.x filter took
effect. 3.x wrote metadata as top-level fields on these five stores, so the field names stay the same.

**Step A: translate the native filter.**

| 3.x native condition | 4.x expression |
|---|---|
| Qdrant `['key' => f, 'match' => ['value' => v]]`; Pinecone `[f => ['$eq' => v]]` or `[f => v]`; Meilisearch `"f = 'v'"`; Elasticsearch/OpenSearch `['term' => [f => v]]` | `Filter::eq(f, v)` |
| Qdrant `match.any`, Pinecone `$in`, Meilisearch `f IN [a, b]`, Elasticsearch/OpenSearch `terms`, on a field holding one value | `Filter::in(f, [a, b])` |
| the same on a field holding a list of strings | `Filter::containsAny(f, [a, b])`; every value required: `Filter::containsAll(f, [a, b])` |
| Pinecone `$ne`; Meilisearch `f != v`; Qdrant `must_not` + `match`; Elasticsearch/OpenSearch `bool.must_not` + `term` | `Filter::neq(f, v)` (the field must be `required()`, Case 4) |
| Qdrant `range`; Pinecone `$gt`/`$gte`/`$lt`/`$lte`; Meilisearch `f > n`, `f n TO m`; Elasticsearch/OpenSearch `range` | `Filter::gt()`, `gte()`, `lt()`, `lte()`, on numeric fields only. A `DateTimeInterface` value becomes epoch seconds, so the stored field must hold integers. A range over date strings stays raw |
| several conditions: Qdrant's list, Meilisearch's array, Pinecone `$and`, Elasticsearch/OpenSearch `bool.must`/`bool.filter` | `Filter::where(a, x)->where(b, y)` or `FilterGroup::allOf(...)` |
| Qdrant `should`, Pinecone `$or`, a nested Meilisearch array or `OR`, Elasticsearch/OpenSearch `bool.should` | `FilterGroup::anyOf(...)` |
| anything else: `$nin`, `$exists`, `IS NULL`, `EXISTS`, geo filters, `has_id`, full-text matches, conditions on `id` or `content` | `Filter::raw(<built-in store>::class, $fragment)` |

`Filter::where()` returns a chain that ANDs its conditions: `where()`, `whereNot()`, `whereIn()`,
`whereGreaterThan()`, `whereGreaterThanOrEqual()`, `whereLessThan()`, `whereLessThanOrEqual()`,
`whereContainsAny()`, `whereContainsAll()`, `whereAny(...$expressions)` (an OR sub-group) and `with($expression)`.
Only `Filter::where()` starts a chain; to AND conditions that start with another operator, use `FilterGroup::allOf()`.

Rules for `Filter::raw()`:

- Tag the fragment with Neuron's own class, for example `QdrantVectorStore::class`, even when the application uses a
  subclass of it. A subclass tag throws `VectorStoreException` (`Raw filter targets ...`).
- Qdrant: a fragment is one condition. Translate each condition of the 3.x list, or wrap the whole list:
  `Filter::raw(QdrantVectorStore::class, ['must' => $conditions])`. Never pass the bare list.
- Meilisearch: a fragment is one string. Join the 3.x array entries with `' AND '`; a nested array (OR) becomes its
  entries joined with `' OR '` inside parentheses.
- Pinecone: pass the 3.x filter array as it is.
- Elasticsearch/OpenSearch: a fragment is one query object. A 3.x list of queries becomes
  `['bool' => ['filter' => $queries]]`.
- Never interpolate request input into a fragment. Values that come from variables belong in portable filters.
- Every custom field a raw fragment references must still be declared `filterable()` (Case 4). 4.x writes undeclared
  metadata only inside the `_neuron_metadata` JSON field, so a raw filter on an undeclared field matches only documents
  written by 3.x.
- `MemoryVectorStore` and `FileVectorStore` cannot run raw filters: they throw `VectorStoreException`.

Worked example.

Before (3.x):

```php
$store = (new QdrantVectorStore(collectionUrl: $url, key: $key))->withFilters([
    ['key' => 'tenant', 'match' => ['value' => $tenant]],
    ['key' => 'year', 'range' => ['gte' => 2024]],
]);
```

Expression (4.x):

```php
$filters = Filter::where('tenant', $tenant)->whereGreaterThanOrEqual('year', 2024);
```

**Step B: put the expression where the 3.x filter applied.** Delete every `withFilters()` call; a `withFilters([])`
call that cleared the filters has no replacement, or becomes `setRetrievalScope(null)` in situation (c).

(a) `withFilters()` right before a direct search:

```php
// Before (3.x)
$documents = $store->withFilters($native)->similaritySearch($embedding);

// After (4.x)
$documents = $store->search(new SearchRequest($embedding, filters: $filters));
```

(b) `withFilters()` inside a RAG subclass's `vectorStore()` hook, or inside an override of `resolveVectorStore()`
(guide 57 later moves that override into `vectorStore()`). Return the store without it and return the expression from
the protected `retrievalScope()` hook, which is read on every run.

Before (3.x):

```php
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class SupportBot extends RAG
{
    protected string $tenant;

    protected function vectorStore(): VectorStoreInterface
    {
        return (new QdrantVectorStore(collectionUrl: $_ENV['QDRANT_URL'], key: $_ENV['QDRANT_KEY']))
            ->withFilters([['key' => 'tenant', 'match' => ['value' => $this->tenant]]]);
    }
}
```

After (4.x):

```php
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class SupportBot extends RAG
{
    protected string $tenant;

    protected function vectorStore(): VectorStoreInterface
    {
        return new QdrantVectorStore(
            collectionUrl: $_ENV['QDRANT_URL'],
            key: $_ENV['QDRANT_KEY'],
            schema: DocumentSchema::of(DocumentField::string('tenant')->filterable()),
        );
    }

    protected function retrievalScope(): ?FilterExpression
    {
        return Filter::eq('tenant', $this->tenant);
    }
}
```

(c) `withFilters()` on a store passed to `setVectorStore()`, or on the store returned by `$rag->resolveVectorStore()`,
before the RAG runs. Call `setRetrievalScope()` on the RAG instead. It wins over `retrievalScope()` (passing null
falls back to the hook), and like 3.x `withFilters()` it stays on the instance for later runs.

```php
// Before (3.x)
$store = new QdrantVectorStore(collectionUrl: $url, key: $key);
$store->withFilters([['key' => 'tenant', 'match' => ['value' => $tenant]]]);
$rag->setVectorStore($store);

// After (4.x), $schema from Case 4
$rag->setVectorStore(new QdrantVectorStore(collectionUrl: $url, key: $key, schema: $schema));
$rag->setRetrievalScope(Filter::eq('tenant', $tenant));
```

The scope reaches only the RAG's own retrieval step. In 3.x the filtered store also filtered every other search
through it. Find those searches in the RAG class and its callers
(`grep -rnE 'resolveRetrieval\(|resolveVectorStore\(|RetrievalTool' --include='*.php' --exclude-dir=vendor .`) and pass
the scope yourself: `->retrieve($message, $this->resolveRetrievalScope())`; replace
`new RetrievalTool($this->resolveRetrieval())` with
`new RetrievalTool(new SimilarityRetrieval($this->resolveVectorStore(), $this->resolveEmbeddingsProvider(), filters: $this->resolveRetrievalScope()))`;
`->search(new SearchRequest($embedding, filters: $this->resolveRetrievalScope()))`.

(d) `withFilters()` on a store handed to `SimilarityRetrieval` (through `setRetrieval()`, a `retrieval()` hook or
`new RetrievalTool(...)`).
Pass the expression as the strategy's `filters:` argument.

```php
// Before (3.x)
$store = new PineconeVectorStore(key: $key, indexUrl: $indexUrl);
$rag->setRetrieval(new SimilarityRetrieval($store->withFilters(['tenant' => ['$eq' => $tenant]]), $embeddings));

// After (4.x)
$store = new PineconeVectorStore(key: $key, indexUrl: $indexUrl, schema: $schema);
$rag->setRetrieval(new SimilarityRetrieval($store, $embeddings, filters: Filter::eq('tenant', $tenant)));
```

(e) A store that received `withFilters()` once and is then searched in several places: pass `filters: $filters` to
every `search()` that used to inherit the filter.

For (b) and (c): if the RAG subclass overrides `ragNodes()` and builds `new RetrievalNode($this->resolveRetrieval())`
itself, pass the scope as the second argument, or the scope is ignored:
`new RetrievalNode($this->resolveRetrieval(), $this->resolveRetrievalScope())`. Guide 26 migrates the rest of that
method.

### Case 4: Declare the filtered fields in a DocumentSchema

Applies when any filter (portable or raw, from Case 3, application code or tests) references a field other than
`sourceType` and `sourceName`. Those two never need a declaration. Otherwise skip this case.

1. List the field names the filters reference, with the operators used on each.
2. Find every construction of that store with pattern 3, including ingestion commands, queue jobs, container bindings
   and `FakeVectorStore` in tests.
3. Add the same `schema:` argument to each of them. It is the last constructor parameter of every built-in store and
   of `FakeVectorStore` (`new FakeVectorStore($results, schema: $schema)` or `FakeVectorStore::make(schema: $schema)`).
   Build the schema in one place, for example a method on the RAG class, and reuse it.

Before:

```php
return new PineconeVectorStore(key: $key, indexUrl: $indexUrl);
```

After (for `Filter::where('tenant', $t)->whereNot('status', 'draft')->whereGreaterThanOrEqual('year', 2024)->whereContainsAny('tags', ['php'])`):

```php
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;

return new PineconeVectorStore(key: $key, indexUrl: $indexUrl, schema: DocumentSchema::of(
    DocumentField::string('tenant')->filterable(),
    DocumentField::string('status')->required()->filterable(),
    DocumentField::integer('year')->filterable(),
    DocumentField::strings('tags')->filterable(),
));
```

Declaration rules (violations throw `DocumentSchemaException`, which extends `VectorStoreException`):

- Types: `string()`, `integer()`, `float()`, `boolean()`, `strings()`, `integers()`, `floats()`, `booleans()`. Pick
  the type the loaders and splitters actually store; if the ingestion code does not make it clear, ask the developer.
- Filter values must match the declared type: `Filter::eq('year', '2024')` on an `integer()` field throws. Integer
  fields reject numeric strings; float fields accept integers.
- A field used with `neq()` / `whereNot()` must be `->required()`. Every stored document must then carry a non-null
  value for it.
- `gt()`, `gte()`, `lt()`, `lte()` and their `where*` forms need an `integer()` or `float()` field.
- `containsAny()` / `containsAll()` need a `strings()` field; `eq()` and `in()` on a list field throw.
- `integers()`, `floats()` and `booleans()` cannot be filterable (`->filterable()` throws). The five stores that had
  `withFilters()` write such a list only inside `_neuron_metadata`, so no filter, raw or portable, matches it on
  documents written by 4.x. If the application filtered such a field in 3.x, ask the developer whether to store it as a
  `strings()` list or drop the filter.
- A declared list value must be a non-empty list: write null or omit the key instead of `[]`.
- Names must match `^[A-Za-z_][A-Za-z0-9_]*$` and must not be reserved: `id`, `content`, `embedding`, `score`,
  `sourceType`, `sourceName`, `metadata`, `_neuron_metadata`, `_vectors`, `_rankingScore`, `vector_distance`. For a
  filtered metadata key that breaks these rules (for example one containing `-`, `.` or a space), ask the developer for
  a new name, rename it in every writer, reader and filter, and report that existing documents need re-ingestion.
- Declared fields are validated at ingestion too: `RAG::addDocuments()`, `reindexBySource()` and every store's
  `addDocument()` / `addDocuments()` throw for a missing required field or a wrongly typed declared field. Make the
  loaders and splitters set them.
- Pass the same schema at every construction of the same index. `MeilisearchVectorStore` rewrites the index's
  filterable attributes from the schema each time it is constructed: a construction without the schema removes them,
  including attributes made filterable by hand for 3.x. Elasticsearch, OpenSearch, Typesense and Weaviate apply the
  declared fields only when the store creates the index.

### Case 5: Custom VectorStoreInterface implementations

Before (3.x):

```php
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class RedisVectorStore implements VectorStoreInterface
{
    public function __construct(protected Client $client, protected int $topK = 4)
    {
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    public function addDocuments(array $documents): VectorStoreInterface
    {
        // write the documents
        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        // delete the documents of the source
        return $this;
    }

    public function similaritySearch(array $embedding): iterable
    {
        // return the $this->topK documents nearest to $embedding
    }
}
```

After (4.x):

```php
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\HasDocumentSchema;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class RedisVectorStore implements VectorStoreInterface
{
    use HasDocumentSchema;

    public function __construct(protected Client $client, protected int $topK = 4, ?DocumentSchema $schema = null)
    {
        $this->initializeSchema($schema);
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->validateDocuments($documents);
        // write the documents
        return $this;
    }

    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        $this->validateFilters($filters);
        // delete every document matching $filters
        return $this;
    }

    public function search(SearchRequest $request): iterable
    {
        if ($request->filters !== null) {
            $this->validateFilters($request->filters);
        }
        $limit = $request->topK ?? $this->topK;

        // return the $limit documents nearest to $request->embedding that match $request->filters
    }
}
```

1. Delete `similaritySearch()`, `deleteBy()`, `deleteBySource()` and any `withFilters()`.
2. `use HasDocumentSchema;` provides `getSchema()` and the protected `validateDocument()`, `validateDocuments()` and
   `validateFilters()`. Add the trailing `?DocumentSchema $schema = null` constructor parameter and call
   `$this->initializeSchema($schema)`.
3. Apply the filters in one of two ways:
   - Compile them to the backend's syntax by walking the tree: `NeuronAI\RAG\VectorStore\Filter\Filter` (public
     `field`, `operator` (a `FilterOperator`), `value`), `FilterGroup` (`operator()` returns a `FilterCombinator`,
     `conditions()`), and `RawFilter` (public `store`, `fragment`). Accept a `RawFilter` only when `store` is your own
     class, and throw otherwise.
   - Or evaluate them in PHP: `$evaluator = new FilterEvaluator(); $evaluator->assertEvaluable($filters);`, then keep
     the documents for which `$evaluator->matchesDocument($filters, $document)` is true.
4. Build returned documents with the setters: `(new Document($content))->setId($id)->setEmbedding($vector)
   ->setSourceType($type)->setSourceName($name)->setScore($score)->setMetadata($metadata)`. When rebuilding metadata
   from a flat payload, skip the keys in `DocumentSchema::RESERVED_FIELDS`, which `setMetadata()` refuses. Guide 20
   migrates the rest of the class's `Document` property access.
5. To make declared fields filterable in your backend, write them as native fields. The optional helpers
   `NeuronAI\RAG\VectorStore\MetadataMapper::toStorage($document, $this->schema)` (all metadata as a JSON string plus
   the declared filterable fields) and `MetadataMapper::hydrate($document, $row)` do this for key-value payloads.

### Case 6: Subclasses of built-in stores

- Overrides of `similaritySearch()`, `deleteBy()`, `deleteBySource()` and `withFilters()` are never called again. Move
  their logic into `search(SearchRequest $request)` or `delete(FilterExpression $filters): VectorStoreInterface` and call
  `parent::search($request)` / `parent::delete($filters)`. Keep the parent's return type on `search()`: `array` for
  `ElasticsearchVectorStore`, `TypesenseVectorStore`, `MemoryVectorStore`, `FileVectorStore` and `FakeVectorStore`,
  `iterable` for the other stores. A wider type fails with `Declaration ... must be compatible`.
- Remove reads and writes of `$this->filters`: the property no longer exists. Pass the translated expression on the
  `SearchRequest` instead (Case 3).
- A constructor override must accept `?DocumentSchema $schema = null` and forward it as `schema:` to the parent when
  the store needs a schema (Case 4).
- Compare every other override with the 4.x parent in `vendor/` and report any overridden method that no longer exists
  there: it is dead code. Guide 2 migrates `$this->host`, `$this->indexUrl`, `$this->collectionUrl` and requests made
  through `$this->httpClient`.

Before (3.x):

```php
class CountingQdrantStore extends QdrantVectorStore
{
    public int $searches = 0;

    public function similaritySearch(array $embedding): iterable
    {
        $this->searches++;

        return parent::similaritySearch($embedding);
    }
}
```

After (4.x):

```php
use NeuronAI\RAG\VectorStore\SearchRequest;

class CountingQdrantStore extends QdrantVectorStore
{
    public int $searches = 0;

    public function search(SearchRequest $request): iterable
    {
        $this->searches++;

        return parent::search($request);
    }
}
```

### Case 7: Custom retrieval strategies and SimilarityRetrieval subclasses

A `retrieve()` with the 3.x signature fails with `Declaration ... must be compatible`.

Before (3.x):

```php
public function retrieve(Message $query): array
{
    return $this->store->similaritySearch($this->embeddings->embedText($query->getContent()));
}
```

After (4.x):

```php
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\SearchRequest;

public function retrieve(Message $query, ?FilterExpression $filters = null): array
{
    $documents = $this->store->search(new SearchRequest(
        $this->embeddings->embedText($query->getContent()),
        filters: $filters,
    ));

    return is_array($documents) ? array_values($documents) : iterator_to_array($documents, false);
}
```

- Never drop `$filters`: the RAG passes its retrieval scope through it.
- A strategy with its own constraint ANDs the two: `filters: FilterGroup::merge($this->ownFilters, $filters)` (null
  when both are null).
- A strategy that delegates to another strategy passes `$filters` on: `$this->inner->retrieve($query, $filters)`.
- Callers of `->retrieve($message)` need no change.

### Case 8: FileVectorStore name containing a path

The constructor throws `VectorStoreException` when `name` plus `ext` is `''`, `.` or `..`, or contains `/`, `\` or a
NUL byte. Move the folders into `directory`.

Before (3.x):

```php
$store = new FileVectorStore(directory: $baseDir, name: 'tenants/acme');
```

After (4.x):

```php
$store = new FileVectorStore(directory: $baseDir . '/tenants', name: 'acme');
```

The file path is `directory/name.ext` in both versions, so the existing file stays where it is and is read as-is.

## Data stored by 3.x

Every built-in store reads documents written by 3.x without migration: metadata that 3.x wrote as top-level fields is
returned as metadata, and `FileVectorStore` files are read line by line as before. Writing to, or filtering declared
fields over, an index that already holds 3.x documents needs a check on some backends. Report these to the developer,
and never recreate an index or delete data without their approval:

- Qdrant, Pinecone, Meilisearch, Chroma, MariaDB, File, Memory: no action.
- Weaviate: 3.x kept metadata only in the `metadata` JSON property, so filters on declared fields do not match 3.x
  objects. Recreate the collection and re-ingest.
- Typesense: 3.x created a required field for every metadata key of the first document it stored. 4.x writes only the
  declared filterable fields as top-level fields, so inserts into such a collection are rejected, with or without
  filters, unless each of those keys is declared `DocumentField::string('key')->required()->filterable()`. The
  alternative is to recreate the collection and re-ingest.
- Elasticsearch/OpenSearch: 3.x mapped metadata fields as `keyword`. Check `GET <index>/_mapping`: a declared
  `integer()`, `float()` or `boolean()` field mapped as `keyword`, or a declared `string()` field mapped as `text`,
  needs a new index and re-ingestion.
- To re-ingest: recreate the index or collection, then call `RAG::addDocuments()` or `reindexBySource()` with the
  original sources.
- Code outside Neuron that reads metadata straight from the backend: documents written by 4.x carry all metadata as a
  JSON string in the `_neuron_metadata` field, and only declared filterable fields are also written as native fields.

## Checklist

- Search pattern 1 returns no Neuron hits: no `similaritySearch`, `withFilters`, `deleteBy`, `deleteBySource` or
  `DeleteByInterface` remains, in code or tests.
- No subclass of a built-in store overrides a removed method or reads `$this->filters`.
- Every custom `VectorStoreInterface` implementation uses `HasDocumentSchema` (or declares `getSchema()`) and
  implements `search(SearchRequest)` and `delete(FilterExpression)`.
- Every custom `RetrievalInterface` and `SimilarityRetrieval` subclass declares `?FilterExpression $filters = null` and
  passes it to its search or inner strategy.
- A filter formerly applied through `withFilters()` is now passed on each `SearchRequest`, as the `filters:` argument
  of `SimilarityRetrieval`, or as the RAG's `retrievalScope()` / `setRetrievalScope()`; a hand-built `RetrievalNode`
  receives `$this->resolveRetrievalScope()`.
- Every `RetrievalTool`, `resolveRetrieval()->retrieve()` and `resolveVectorStore()->search()` that used to go through a
  filtered store now passes the filter or `resolveRetrievalScope()`.
- Every custom field used in a filter is declared `filterable()` with the stored type in the schema passed at every
  construction of that store, including `FakeVectorStore` in tests; fields used with `neq` / `whereNot` are
  `required()`.
- No `FileVectorStore` `name` contains a path.
- The index actions from "Data stored by 3.x" were reported to the developer, not performed.
- Static analysis shows no error about `SearchRequest`, `Filter`, `FilterGroup`, `FilterExpression`, `DocumentSchema`,
  `DocumentField`, `VectorStoreInterface` or `RetrievalInterface`. Errors about `Document` properties are resolved by
  guide 20.
