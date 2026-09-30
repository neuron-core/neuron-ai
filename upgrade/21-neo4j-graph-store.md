# Upgrade: Neo4jGraphStore passes relationship types as parameters

## Summary

`NeuronAI\RAG\GraphStore\Neo4jGraphStore` keeps its namespace, its public methods and `GraphStoreInterface`. It now sends relationship types to Neo4j as query parameters and checks the node label. For the application this means:

- **Neo4j 5.26 or later is required.** An older server rejects `upsert()` and `delete()` with a Cypher syntax error.
- **The node label is validated.** The constructor throws PHP's built-in `\InvalidArgumentException` ("nodeLabel must not be empty or contain a backtick or a backslash, ...") when `nodeLabel` is empty or contains a backtick or a backslash. This usually hits a PHP class name passed as the label. All other labels keep working, including punctuated ones such as `Knowledge-Entity`.
- **The `$database` property is typed `?string`.** It was `string`. A subclass that redeclares it must use the same type.

| 3.x | 4.x |
|---|---|
| Any Neo4j version | Neo4j 5.26 or later |
| `nodeLabel` containing a backslash, such as `App\Models\KnowledgeNode`, accepted | `\InvalidArgumentException` from the constructor |
| `protected string $database = 'neo4j'` | `protected ?string $database = null` |

**Data stored by 3.x:** relationship types are still normalized as in 3.x (uppercased, spaces turned into underscores), so 4.x reads the existing edges as they are. `upsert()` merges into them and `delete()` removes them. Nodes are also read as they are, except nodes stored under a label that contains a backslash (Case 2).

## What to Search For

Run from the application root:

```bash
# 1. Every use of the store: constructions, subclasses, imports, container and service definitions
grep -rn 'Neo4jGraphStore' --include='*.php' --include='*.yaml' --include='*.yml' --include='*.xml' --exclude-dir=vendor .

# 2. The Neo4j server and its connection settings: compose files, Dockerfiles, CI, .env, config
grep -rnE "(image:|FROM) *[\"']?([^ \"'#]*/)?neo4j([:@ \"']|$)|neo4j:[0-9]|NEO4J_|(bolt|neo4j)(\+s|\+ssc)?://" --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git .
```

How to follow the hits:

- For each construction (`new Neo4jGraphStore(`, `parent::__construct(` in a subclass, a container factory or a service definition), find the `nodeLabel` argument. It is passed by name as `nodeLabel:` or as the 5th positional argument (the order is `uri`, `username`, `password`, `database`, `nodeLabel`). A construction without it uses the default `Entity`, which is valid.
- When the label comes from config, an env variable, a constant or `::class`, follow it to its source and work out the actual string.
- For each `extends Neo4jGraphStore`, check whether the subclass declares a `$database` property, including through a promoted constructor parameter (Case 3).
- Ignore hits in generated caches, such as a compiled container.

If pattern 1 finds nothing, this guide does not apply.

## How to Refactor

### Case 1: The Neo4j server is older than 5.26

No application code changes. Upgrading the server is the developer's decision.

1. Find the server version. If you can reach the database the application uses, for example a local development container, run this read-only query:

   ```cypher
   CALL dbms.components() YIELD name, versions, edition
   ```

   If no database is reachable, use the image tag, version variable or URI found by pattern 2.
2. Compare the version with the requirement:
   - These satisfy it: 5.26 and every later 5.x patch, every calendar-versioned release (2025.01, 2025.x, 2026.x, ...), and Neo4j Aura (URIs on `databases.neo4j.io`). Calendar versions come after 5.26, so do not compare `2025.x` with `5.26` as plain numbers.
   - These do not: 4.x and 5.0 to 5.25.
   - An unpinned tag (`neo4j`, `neo4j:latest`, `neo4j:5`) pulls 5.26 or later today, but a machine can still run an older image it pulled before. Report it as unverified.
3. If the version is older, or you could not determine it, report the version, where you found it, and the 5.26 requirement to the developer. Do not change images, compose files, CI or servers yourself.

### Case 2: `nodeLabel` contains a backslash

A PHP class name passed as the label now throws when the store is constructed. The nodes already in the graph carry that label, so changing it in code alone hides them from the store. They must be relabeled once.

**Before (3.x):**

```php
use App\Models\KnowledgeNode;
use NeuronAI\RAG\GraphStore\Neo4jGraphStore;

$store = new Neo4jGraphStore(
    uri: $uri,
    username: $user,
    password: $password,
    nodeLabel: KnowledgeNode::class,
);
```

**After (4.x):**

```php
use NeuronAI\RAG\GraphStore\Neo4jGraphStore;

$store = new Neo4jGraphStore(
    uri: $uri,
    username: $user,
    password: $password,
    nodeLabel: 'KnowledgeNode',
);
```

1. Pick a replacement label that no other nodes in that database use. The class short name is usually a good choice when it is free. Check it with `CALL db.labels()`, or with `MATCH (n:KnowledgeNode) RETURN count(n)`, which must return 0. Be careful with `Entity`: it is the constructor's default, and relabeling onto a label that is already in use merges two graphs. If you cannot check, or the short name is taken, ask the developer which label to use.
2. Change the label where it is defined. For a config or env value, change it at its source (config file, `.env`, `.env.example`). Remove the `use` import if nothing else uses it.
3. Report the relabel to the developer, and run nothing without their approval. Write the old label between backticks exactly as 3.x received it: the runtime string, with single backslashes. That is the text 3.x wrote into its statements, so it matches the stored label.

   ```cypher
   MATCH (n:`App\Models\KnowledgeNode`) SET n:KnowledgeNode REMOVE n:`App\Models\KnowledgeNode`
   ```

   Run it in the database that holds the 3.x graph. 3.x never used the `database` constructor argument, so that is the server's default database, or the one named by `?database=` in the connection URI. To confirm the result, run ``MATCH (n:`App\Models\KnowledgeNode`) RETURN count(n)`` before the relabel (it returns the number of nodes) and after it (it returns 0). If the developer says the graph is rebuilt from its sources anyway, the code change is enough.

A label that is empty or contains a backtick never worked in 3.x, because Neo4j rejected the statements, so no data exists under it. Ask the developer for a valid label and replace it. No relabel is needed.

### Case 3: A subclass redeclares `$database`

The parent now declares `protected ?string $database`. A subclass that declares the property as `string` fails with a fatal error ("Type of ...::$database must be ?string"). Change only the type and keep the value.

**Before (3.x):**

```php
use NeuronAI\RAG\GraphStore\Neo4jGraphStore;

class TenantGraphStore extends Neo4jGraphStore
{
    public function __construct(string $uri, protected string $database = 'neo4j')
    {
        parent::__construct(uri: $uri, database: $database);
    }
}
```

**After (4.x):**

```php
use NeuronAI\RAG\GraphStore\Neo4jGraphStore;

class TenantGraphStore extends Neo4jGraphStore
{
    public function __construct(string $uri, protected ?string $database = 'neo4j')
    {
        parent::__construct(uri: $uri, database: $database);
    }
}
```

## Checklist

- The Neo4j server is 5.26 or later (any later 5.x patch, a calendar-versioned release, or Aura). Otherwise the developer was told the version found, or that it could not be verified, and the requirement.
- No `nodeLabel` given to `Neo4jGraphStore`, whether by name, as the 5th positional argument, from config or env, or through `parent::__construct()`, is empty or contains a backtick or a backslash.
- Every replacement label was not already used by other nodes in that database.
- The relabel query was reported to the developer and ran only with their approval, in the database that holds the 3.x graph.
- No subclass declares `$database` with a type other than `?string`.
- PHPStan reports no error about `Neo4jGraphStore`.
