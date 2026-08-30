# Upgrade: filter-aware vector stores

## Summary

Vector stores are redesigned around one idea: **every search input is an
immutable, per-call value**. The stateful `withFilters()` pattern is gone —
a filter set for one search could silently constrain the next, and once the
framework itself injects filters (tenant scoping, source scoping) that
hazard becomes a correctness bug. Filters are now expressed in a portable,
backend-neutral vocabulary that each store compiles to its native syntax,
so the same filter works on Pinecone, Qdrant, Meilisearch, and every other
backend. Filter expressions can contain nested boolean logic, while mandatory
scopes are always composed with AND so one scope cannot relax another.

What changed:

1. **`VectorStoreInterface::similaritySearch(array $embedding)` is now
   `search(SearchRequest $request)`**. The request carries the embedding,
   optional filters, and an optional per-call `topK` (null falls back to the
   store's constructor default):

   ```php
   use NeuronAI\RAG\VectorStore\SearchRequest;

   $documents = $store->search(new SearchRequest(
       embedding: $embedding,
       filters: Filter::eq('sourceType', 'pdf'),
       topK: 8,
   ));
   ```

2. **`withFilters()` is removed from every store.** Nothing on a store
   outlives a call anymore. Pass filters on the `SearchRequest` instead.

3. **`deleteBy(string $sourceType, ?string $sourceName)` is now
   `delete(FilterExpression $filters)`** — deletion by any filter, not just the
   two hardcoded fields:

   ```php
   $store->delete(
       Filter::where('sourceType', 'file')
           ->where('sourceName', 'manual.pdf'),
   );
   ```

4. **`RetrievalInterface::retrieve()` gained a filters parameter**:
   `retrieve(Message $query, ?FilterExpression $filters = null)`. The parameter
   carries filters injected for the current run; custom strategies must AND
   them with their own — never drop them
   (`FilterScope::merge($own, $filters)?->expression()` is the null-tolerant
   scope composition for exactly this). RAG applications can define mandatory
   constraints with `retrievalScope()` or `setRetrievalScope()`.

5. **`QueryPreProcessedEvent` is the per-run injection channel.** Middleware
   on `RetrievalNode` (or a custom pre-retrieval node) calls
   `$event->addFilters($expression)`; `RetrievalNode` forwards the accumulated
   filters to the strategy. Filters only accumulate by AND, and the event is
   born fresh every run, so an injected filter can never leak into the next
   run or relax another injector's scope.

## What to Search For

```
grep -rn "similaritySearch" --include="*.php" .
grep -rn "withFilters" --include="*.php" .
grep -rn "deleteBy" --include="*.php" .
grep -rn "implements VectorStoreInterface" --include="*.php" .
grep -rn "implements RetrievalInterface" --include="*.php" .
```

Custom `VectorStoreInterface` implementations must adopt the new `search()`
and `delete()` signatures (compile the `FilterExpression` to your backend's
syntax with whatever internal architecture best suits that store, or evaluate it in PHP with
`NeuronAI\RAG\VectorStore\Filter\FilterEvaluator`). Custom
`RetrievalInterface` strategies must accept and honor the new `$filters`
parameter.

## The filter vocabulary

```php
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;

$filters = Filter::where('tenant', 'acme')
    ->whereNot('status', 'draft')
    ->whereIn('sourceType', ['pdf', 'html'])
    ->whereGreaterThanOrEqual('published_at', 1767225600)
    ->whereLessThan('price', 100);

$visible = FilterGroup::allOf(
    $filters,
    FilterGroup::anyOf(
        Filter::eq('visibility', 'public'),
        Filter::eq('owner_id', $userId),
    ),
);
```

The filter value object enforces the backend-neutral vocabulary. After the
next upgrade step, the vector store also validates field names, types, and
filterability against its `DocumentSchema` before database I/O:

- Values are scalars — `null` throws (missing-vs-null semantics differ per
  backend and cannot be promised portably).
- Range operators (`gt/gte/lt/lte`) are numeric. `DateTimeInterface` values
  normalize to epoch timestamps, and backed enums normalize to their scalar values.
- Groups support nested `allOf()` and `anyOf()` expressions. Use
  `FilterScope::merge()` rather than an OR group to combine mandatory scopes.
- Filterable string arrays support `containsAny()` and `containsAll()`.

Backend capabilities outside the vocabulary go through the tagged escape
hatch:

```php
Filter::raw(MeilisearchVectorStore::class, "_geoRadius(45.4, 9.1, 2000)")
```

The tagged store passes the fragment through verbatim; every other store
throws — swapping stores fails loudly at the first search instead of
silently matching the wrong documents.

## Migration

| Before | After |
|--------|-------|
| `$store->similaritySearch($embedding)` | `$store->search(new SearchRequest($embedding))` |
| `$store->withFilters($native)->similaritySearch($embedding)` | `$store->search(new SearchRequest($embedding, filters: $group))` |
| `$store->deleteBy('file', 'a.pdf')` | `$store->delete(Filter::where('sourceType', 'file')->where('sourceName', 'a.pdf'))` |
| `$store->deleteBySource('file', 'a.pdf')` (Meilisearch, deprecated since 3.x) | same as `deleteBy` above |
| `$store->deleteBy('file')` | `$store->delete(Filter::eq('sourceType', 'file'))` |
| `retrieve(Message $query)` (custom strategy) | `retrieve(Message $query, ?FilterExpression $filters = null)` — apply `$filters` when present |

Native filter arrays previously passed to `withFilters()` translate to the
vocabulary in most cases; anything genuinely backend-specific becomes a
`Filter::raw()` tagged with your store class.

## Backend caveats

- **Custom metadata fields**: starting with the next upgrade step, portable
  filters require a `DocumentSchema`; continue with
  `17-document-schema.md` before declaring custom fields.
- **Existing indexes**: typed mappings may require recreation and reindexing
  after applying the document schema upgrade.
- **Pinecone**: filter-based deletion works on pod-based indexes only
  (a pre-existing Pinecone limitation, unchanged by this redesign).

## Verification Checklist

- [ ] No `->similaritySearch(` calls remain — all migrated to `search(new SearchRequest(...))`
- [ ] No `->withFilters(` calls remain — filters travel on the `SearchRequest`
- [ ] No `->deleteBy(` calls remain — all migrated to `delete(FilterExpression)`
- [ ] Custom `VectorStoreInterface` implementations expose `search()`/`delete()`
- [ ] Custom `RetrievalInterface` strategies accept `?FilterExpression $filters` and apply it when present
- [ ] The application's test suite and static analysis pass
