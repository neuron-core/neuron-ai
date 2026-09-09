# RAG Module

Retrieval Augmented Generation. `RAG` extends `Agent`, so it inherits the whole Agent/Workflow machinery (thread identity, persistence, resume, memory, approvals) and only replaces the entry chain of the graph.

## The retrieval chain

`RAG::entryNodes()` swaps the Agent's `StartNode` for a retrieval pipeline whose last node produces the inference event:

```text
AgentStartEvent → PreProcessNode → RetrievalNode → PostProcessNode → InstructionsNode → [RecallMemoryNode] → inference
```

- `PreProcessNode` reads the question from the start event, initializes `state->request` (the role `StartNode` plays in the Agent) and runs the pre-processors (query rewriting, expansion). Nothing is written to chat history before inference: pending messages commit only after the provider call succeeds, so a failed turn never leaves a dangling user message.
- `RetrievalNode` asks the retrieval strategy. `QueryPreProcessedEvent` is the **injection channel for filters**: middleware (`before()` on `RetrievalNode`) and preceding nodes call `addFilters()`, the node ANDs every mandatory scope at the root and forwards the expression. A scope may contain nested AND/OR logic but can never relax another scope, and the event is born fresh every run, so a filter cannot leak into the next one.
- `PostProcessNode` re-ranks or filters the documents.
- `InstructionsNode` enriches `state->request->instructions` with the retrieved documents, preserving earlier middleware changes. Messages and options stay in the state request throughout; intermediate events carry only query, filters and documents.

Collaborators come through lazy hooks with setter twins, like the Agent's provider: `embeddings()`, `vectorStore()`, `retrieval()`, `retrievalScope()`, `preProcessors()`, `postProcessors()`.

```php
class WorkoutTipsAgent extends RAG
{
    protected function provider(): AIProviderInterface { /* ... */ }

    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new OpenAIEmbeddingsProvider(key: env('OPENAI_API_KEY'), model: 'text-embedding-3-small');
    }

    protected function vectorStore(): VectorStoreInterface
    {
        return new FileVectorStore(directory: storage_path('app/embeddings'));
    }

    protected function retrievalScope(): ?FilterExpression
    {
        return Filter::where('tenant', $this->tenantId)->whereIn('status', ['published', 'reviewed']);
    }
}
```

`RetrievalInterface::retrieve(Message $query, ?FilterExpression $filters)` receives the per-run filters; a strategy must AND them with its own, never drop them. `SimilarityRetrieval` is the built-in strategy.

## Vector stores are stateless per call

`VectorStoreInterface::search(SearchRequest)` takes an immutable per-call value (embedding, optional filters, optional `topK` falling back to the store's default). There is no mutable search state on a store, so a filter set for one call cannot leak into the next. `delete(FilterExpression)` uses the same filter model.

### The filter model (`VectorStore/Filter/`)

A portable, backend-neutral expression tree compiled to each store's native syntax by a compiler in `VectorStore/Compilers/` (internal wiring: no shared interface, not injectable; the file and memory stores evaluate the tree in PHP through `FilterEvaluator` instead). This is *filtered similarity search*; reserve "hybrid search" for strategies that combine vector and lexical ranking.

- `Filter::eq/neq/in/gt/gte/lt/lte/containsAny/containsAll()` are the comparison leaves; `Filter::where()` starts an immutable fluent `Criteria` for the common path. Values are scalars only (`null` throws: there is no portable missing-vs-null semantics), ranges normalize to `int|float`, backed enums to their value, dates to epoch timestamps.
- `FilterGroup::allOf()` / `anyOf()` nest boolean logic; `FilterScope::merge()` combines independently supplied mandatory scopes with a root AND. Query expressiveness and scope safety are separate concerns on purpose.
- `Filter::raw(StoreClass::class, $fragment)` is the backend-native escape hatch, tagged with its target store: that store passes it through verbatim, every other compiler throws. Fail loud on a store swap, never misfilter silently. Raw fragments are trusted developer syntax; never interpolate request values into them.
- Retrieval logs include fields, operators and boolean structure but omit comparison values and raw fragments, so authorization data does not leak into the default log context.

### Document and schema

`Document` is the single processing object across loading, splitting, embedding, storage, retrieval and reranking. Embedding and score are nullable runtime values, so strict `null` checks say whether a stage produced them (`0.0` is a valid score).

Custom metadata stays schema-less for storage and round-tripping. Portable *filtering* needs a collection-level `DocumentSchema` passed to the store, because backends differ in what they can filter and how:

```php
$store = new MemoryVectorStore(schema: DocumentSchema::of(
    DocumentField::string('tenant')->required()->filterable(),
    DocumentField::integer('year')->filterable(),
    DocumentField::strings('tags')->filterable(),
));
```

Only `sourceType`, `sourceName` and declared filterable fields are portable filter targets; stores validate values and filters locally, and RAG validates documents before embedding. Filterable `string[]` fields support `containsAny` / `containsAll`; other array types need raw filters. `neq` requires a required field so missing-field behavior cannot diverge between databases. A `DocumentField` can be passed directly to the filter factories for schema-aware construction.

## Ingestion

`DataLoader/` (file and string loaders with pluggable readers) → `Splitter/` (`SplitterInterface` chunking strategies) → `Embeddings/` (`EmbeddingsProviderInterface`) → `RAG::addDocuments()` / `reindexBySource()`. `GraphStore/` is the separate knowledge-graph integration (subject-relation-object triplets, Neo4j).
