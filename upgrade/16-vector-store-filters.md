# Upgrade: filter-aware vector stores

## Summary

Vector stores are redesigned around one idea: **every search input is an
immutable, per-call value**. The stateful `withFilters()` pattern is gone —
a filter set for one search could silently constrain the next, and once the
framework itself injects filters (tenant scoping, source scoping) that
hazard becomes a correctness bug. Filters are now expressed in a portable,
backend-neutral vocabulary that each store compiles to its native syntax,
so the same filter works on Pinecone, Qdrant, Meilisearch, and every other
backend — and composed filters can only narrow a search, never widen it.

What changed:

1. **`VectorStoreInterface::similaritySearch(array $embedding)` is now
   `search(SearchRequest $request)`**. The request carries the embedding,
   optional filters, and an optional per-call `topK` (null falls back to the
   store's constructor default):

   ```php
   use NeuronAI\RAG\VectorStore\SearchRequest;

   $documents = $store->search(new SearchRequest(
       embedding: $embedding,
       filters: FilterGroup::and(Filter::eq('sourceType', 'pdf')),
       topK: 8,
   ));
   ```

2. **`withFilters()` is removed from every store.** Nothing on a store
   outlives a call anymore. Pass filters on the `SearchRequest` instead.

3. **`deleteBy(string $sourceType, ?string $sourceName)` is now
   `delete(FilterGroup $filters)`** — deletion by any filter, not just the
   two hardcoded fields:

   ```php
   $store->delete(FilterGroup::and(
       Filter::eq('sourceType', 'file'),
       Filter::eq('sourceName', 'manual.pdf'),
   ));
   ```

4. **`RetrievalInterface::retrieve()` gained a filters parameter**:
   `retrieve(Message $query, ?FilterGroup $filters = null)`. The parameter
   carries filters injected for the current run; custom strategies must AND
   them with their own — never drop them (`FilterGroup::merge($own, $filters)`
   is the null-tolerant AND for exactly this). `SimilarityRetrieval` takes an
   optional static scope: `new SimilarityRetrieval($store, $embeddings,
   filters: $group)`.

5. **`QueryPreProcessedEvent` is the per-run injection channel.** Middleware
   on `RetrievalNode` (or a custom pre-retrieval node) calls
   `$event->addFilters($group)`; `RetrievalNode` forwards the accumulated
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
and `delete()` signatures (compile the `FilterGroup` to your backend's
syntax, or evaluate it in PHP with
`NeuronAI\RAG\VectorStore\Filter\FilterEvaluator`). Custom
`RetrievalInterface` strategies must accept and honor the new `$filters`
parameter.

## The filter vocabulary

```php
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;

FilterGroup::and(
    Filter::eq('tenant', 'acme'),
    Filter::neq('status', 'draft'),
    Filter::in('sourceType', ['pdf', 'html']),
    Filter::gte('published_at', 1767225600),
    Filter::lt('price', 100),
);
```

The filter value object enforces the backend-neutral vocabulary. After the
next upgrade step, the vector store also validates field names, types, and
filterability against its `DocumentSchema` before database I/O:

- Values are scalars — `null` throws (missing-vs-null semantics differ per
  backend and cannot be promised portably).
- Range operators (`gt/gte/lt/lte`) are `int|float` only — string ranges are
  not portable; index dates as epoch timestamps.
- Groups are AND-only conjunctions; `in()` covers OR-over-one-field. Nested
  groups flatten, so merging scopes is appending.

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
| `$store->deleteBy('file', 'a.pdf')` | `$store->delete(FilterGroup::and(Filter::eq('sourceType', 'file'), Filter::eq('sourceName', 'a.pdf')))` |
| `$store->deleteBySource('file', 'a.pdf')` (Meilisearch, deprecated since 3.x) | same as `deleteBy` above |
| `$store->deleteBy('file')` | `$store->delete(FilterGroup::and(Filter::eq('sourceType', 'file')))` |
| `retrieve(Message $query)` (custom strategy) | `retrieve(Message $query, ?FilterGroup $filters = null)` — apply `$filters` when present |

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
- [ ] No `->deleteBy(` calls remain — all migrated to `delete(FilterGroup::and(...))`
- [ ] Custom `VectorStoreInterface` implementations expose `search()`/`delete()`
- [ ] Custom `RetrievalInterface` strategies accept `?FilterGroup $filters` and apply it when present
- [ ] The application's test suite and static analysis pass
