# Upgrade: `Neo4jGraphStore` passes relationship types as parameters

## Summary

In 3.x `Neo4jGraphStore::upsert()` and `delete()` wrote the relation into the Cypher statement between backticks,
after uppercasing it and replacing spaces with underscores. A relation containing a backtick closed the name, so a
triplet extracted from an untrusted document could append its own Cypher, such as `MATCH (x) DETACH DELETE x`, and
wipe the graph. A harmless relation containing a backtick failed with a syntax error. The node label from the
constructor was written into every statement the same way.

- **Relationship types travel as parameters.** The statements use Neo4j's dynamic types,
  `-[r:$($relationshipType)]->`, so the database treats the relation as data. **Neo4j 5.26 or later is required**: an
  older server rejects the statement with a syntax error.
- **Relationship types are unchanged.** The relation is still uppercased with spaces replaced by underscores, so a
  graph written by 3.x needs no migration: `upsert()` merges into its edges and `delete()` removes them.
- **The node label is checked.** Inside backticks only two characters mean anything: a backtick ends the name, and a
  backslash starts an escape that Neo4j can turn into a backtick. The constructor throws `InvalidArgumentException`
  for a label that is empty or contains either one. Every other label keeps working, including punctuated ones such as
  `Knowledge-Entity`.

| Before (3.x) | After |
|---|---|
| Any Neo4j version the client supports | Neo4j 5.26 or later |
| A relation containing a backtick: a syntax error, or Cypher injected into the statement | Stored as a plain relationship type |
| A node label containing a backslash, such as `App\Models\Entity`, accepted | `InvalidArgumentException` |

## How to Refactor

### Case 1: A Neo4j server older than 5.26

Find the server version:

```cypher
CALL dbms.components() YIELD name, versions
```

A server older than 5.26 must be upgraded before the application moves to this version. 5.26 is the long-term support
release of the 5.x line. Upgrading a database server is the developer's decision: report the version you found and
the requirement, and don't change infrastructure on your own.

### Case 2: A node label containing a backslash

A label with a backslash, typically a PHP class name passed as `nodeLabel`, now throws when the store is constructed.
The nodes already in the graph carry that label, so changing it in code alone would hide them from the store.
Relabeling them is a data migration on the developer's graph: report the affected store and the migration below, and
run nothing without their approval.

Before:

```php
new Neo4jGraphStore(uri: $uri, username: $user, password: $password, nodeLabel: Entity::class);
```

After:

```php
new Neo4jGraphStore(uri: $uri, username: $user, password: $password, nodeLabel: 'Entity');
```

with the existing nodes relabeled once, using the label the application passed before:

```cypher
MATCH (n:`App\Models\Entity`) SET n:Entity REMOVE n:`App\Models\Entity`
```

## What to Search For

```
grep -rn "Neo4jGraphStore" --include="*.php" .
grep -rn "nodeLabel" --include="*.php" .
grep -rnE "image: *neo4j|NEO4J_VERSION|neo4j:[0-9]" .
```

## Checklist

- The Neo4j server the application uses is 5.26 or later, or the developer knows it must be upgraded.
- No `nodeLabel` passed to `Neo4jGraphStore` is empty or contains a backtick or a backslash.
- A relabeling migration was run only with the developer's approval.
