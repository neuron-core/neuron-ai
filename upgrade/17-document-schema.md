# Upgrade: structured RAG documents and metadata schemas

## Summary

`Document` is now one explicit processing object instead of a public property
bag. Embedding and score remain on the document, but are nullable runtime
values: `null` means that stage has not produced them. Vector stores now own a
collection-level `DocumentSchema`, which declares the type and filterability
of custom metadata fields.

Schema-less ingestion remains supported. Undeclared metadata is stored and
returned, but portable filters can only address `sourceType`, `sourceName`, or
custom fields declared filterable in the store schema.

## Document API

Replace direct property access with the corresponding methods:

| Before | After |
|--------|-------|
| `$document->id` | `$document->getId()` |
| `$document->id = $id` | `$document->setId($id)` |
| `$document->content` | `$document->getContent()` |
| `$document->embedding` | `$document->getEmbedding()` |
| `$document->embedding = $vector` | `$document->setEmbedding($vector)` |
| `$document->sourceType` | `$document->getSourceType()` |
| `$document->sourceType = $type` | `$document->setSourceType($type)` |
| `$document->sourceName` | `$document->getSourceName()` |
| `$document->sourceName = $name` | `$document->setSourceName($name)` |
| `$document->score` | `$document->getScore()` |
| `$document->score = $score` | `$document->setScore($score)` |
| `$document->metadata` | `$document->getMetadata()` |
| `$document->metadata = $metadata` | `$document->setMetadata($metadata)` |

`getEmbedding()` and `getScore()` now return nullable values. Use strict null
checks; `0.0` is a valid score. There are intentionally no `hasEmbedding()` or
`hasScore()` methods.

## Declaring filterable metadata

```php
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;

$schema = DocumentSchema::of(
    DocumentField::string('tenant')->required()->filterable(),
    DocumentField::integer('year')->filterable(),
    DocumentField::boolean('published')->filterable(),
    DocumentField::strings('tags'),
);

$store = new ElasticsearchVectorStore(
    client: $client,
    index: 'knowledge',
    schema: $schema,
);
```

Supported types are string, integer, float, boolean, and homogeneous arrays
of those scalar types. Arrays can be validated and round-tripped, but do not
yet have portable filter semantics; use a tagged raw backend filter when
needed.

Optional fields may be absent or null. Required fields must be present and
non-null. Portable `neq` filters require a required field so missing values
cannot match differently after switching databases.

## Custom vector stores

`VectorStoreInterface` now requires:

```php
public function getSchema(): DocumentSchema;
```

Custom stores must validate documents and filters against this schema before
database I/O, preserve undeclared JSON-safe metadata, and map declared
filterable fields to native database types. Existing indexes with incompatible
field mappings should be recreated and reindexed rather than silently changed.

## What to search for

```text
->id
->content
->embedding
->sourceType
->sourceName
->score
->metadata
implements VectorStoreInterface
Filter::
```

Review each result rather than replacing blindly: several unrelated framework
objects intentionally expose similarly named public properties.

## Verification checklist

- [ ] No application code reads or writes `Document` properties directly
- [ ] Nullable embedding and score are handled with strict null checks
- [ ] Every custom metadata filter field is declared filterable in the store schema
- [ ] Fields used by `neq` are declared required
- [ ] Custom vector stores expose and enforce `DocumentSchema`
- [ ] Existing typed indexes were recreated or verified compatible
- [ ] Tests and static analysis pass
