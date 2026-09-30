# Upgrade: SQL toolkits: read-only select tools, write-tool results, schema-tool hooks

## Summary

This guide applies to applications that use `MySQLToolkit`, `PGSQLToolkit` or their tools
(`NeuronAI\Tools\Toolkits\MySQL\*`, `NeuronAI\Tools\Toolkits\PGSQL\*`).

The select tools no longer inspect SQL with regular expressions. Each query runs alone in a
`START TRANSACTION READ ONLY` that is always rolled back, so the database enforces read-only access; only a few text
rules remain (Case 3). The write tools report errors and generated keys differently, and the schema tools lost `getConstraints()`.

Unchanged: tool names, the constructors (`PDO $pdo`, plus `?array $tables = null` on the schema tools, so subclass
constructors keep calling `parent::__construct()` with the same arguments), and successful select results (an array
of rows; `getResult()` is its JSON).
The toolkits store no data, so nothing stored with 3.x needs migrating.

| 3.x | 4.x |
|---|---|
| `MySQLSelectTool` refusal: the string `"The query was rejected for security reasons. …"` | `ToolOutput::error()` with the rule's message |
| `PGSQLSelectTool` refusal: `['error' => "The query was rejected for security reasons. …"]`, `getResult()` `{"error":"…"}` | `ToolOutput::error()` with the rule's message |
| `MySQLSelectTool`: a database error (missing table, syntax error) is thrown as `PDOException` | `ToolOutput::error()` with the exception message, returned to the model; a failure to start the read-only transaction still throws `PDOException` |
| `PGSQLSelectTool`: database errors are thrown as `PDOException` | Still thrown, except a write refused by the database (SQLSTATE `25006`): `ToolOutput::error()` |
| `__invoke(): string\|array` (MySQL), `__invoke(): array` (PostgreSQL) | `__invoke(): array\|ToolOutput` on both |
| `/* tag */ SELECT …` or `-- tag` + newline + `SELECT …` accepted by both tools (comments were stripped) | Refused: nothing but whitespace may come before the first keyword |
| `SELECT 1; SELECT 2` accepted by both select tools | Refused: one statement per call, a `;` only at the very end |
| `DESCRIBE` / `DESC` accepted by `PGSQLSelectTool` | Refused (default `$allowedStatements`: `SELECT`, `WITH`, `EXPLAIN`, `SHOW`) |
| MySQL: `OUTFILE`, `DUMPFILE`, `LOAD_FILE` refused as whole words | Refused as substrings anywhere, identifiers such as `upload_file_id`, `download_file_url` or `load_file_path` included |
| A select tool called while its PDO is inside a transaction ran | `NeuronAI\Exceptions\ToolException` |
| A select tool on a SQLite PDO ran the query | `PDOException` on every call (SQLite has no `START TRANSACTION READ ONLY`) |
| MySQL select, protected: `$forbiddenStatements`, `validateReadOnly()`, `sanitizeQuery()`, `containsKeyword()` | Removed. New protected members: `refusal(string $query): ?string`, `beginReadOnlyTransaction()`, `fetchRows(string $query, array $parameters): array`, `$fileAccessKeywords` (kept: `$allowedStatements`, `getFirstKeyword()`) |
| PostgreSQL select, protected: `$forbiddenPatterns`, `$allowedPatterns`, `validateReadOnlyQuery()`, `removeComments()`, `performAdditionalSecurityChecks()`, `splitStatements()`, `validateSingleStatement()` | Removed. New protected members: `$allowedStatements`, `getFirstKeyword()`, `refusal(string $query): ?string`, `beginReadOnlyTransaction()`, `fetchRows(string $query, array $parameters): array`, const `READ_ONLY_VIOLATION` |
| `MySQLWriteTool::__invoke(): string`; `PDOException` thrown, or the string `"Error executing query: …"` | `__invoke(): string\|ToolOutput`; both are `ToolOutput::error()` |
| `MySQLWriteTool` success: `"… row(s) affected. Last insert ID: N"` (only for queries starting with `INSERT`) | `"… row(s) affected. First generated ID: N."` whenever the statement generated an AUTO_INCREMENT value |
| `PGSQLWriteTool` success: `"… row(s) affected. Last insert ID: N"` | No ID: the query needs `RETURNING`, whose rows are appended as `" Returned rows: [JSON]"` (both write tools append it when the statement returns rows) |
| `MySQLSchemaTool` / `PGSQLSchemaTool`: protected `getConstraints()`; `formatForLLM()` received `$structure['constraints']` | Removed; `$structure` has only `tables`, `relationships`, `indexes` |

## What to Search For

Run from the application root:

```bash
# 1. The toolkits, their tools and subclasses of them
grep -rnE 'MySQL(Toolkit|SelectTool|WriteTool|SchemaTool)|PGSQL(Toolkit|SelectTool|WriteTool|SchemaTool)' --include='*.php' --exclude-dir=vendor .
# 2. Tool names and 3.x result texts in code, prompts, fixtures and tests
grep -rnE '(mysql|pgsql)_(select|write)_query|rejected for security reasons|Last insert ID|Error executing query' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git .
# 3. Removed protected members
grep -rnE 'forbiddenStatements|forbiddenPatterns|allowedPatterns|sanitizeQuery|removeComments|validateReadOnly|performAdditionalSecurityChecks|splitStatements|validateSingleStatement|containsKeyword|getConstraints|[[].constraints.[]]' --include='*.php' --exclude-dir=vendor .
# 4. Catch blocks that may expect database errors from the tools
grep -rnE 'catch \([^)]*PDOException' --include='*.php' --exclude-dir=vendor .
# 5. Transactions, transactional test traits and catch-all tool error handlers
grep -rnE 'DB::transaction|->transaction\(|beginTransaction\(|transactional\(|wrapInTransaction\(|RefreshDatabase|DatabaseTransactions|DAMA|toolErrorHandler\(|function resolveToolErrorHandler' --include='*.php' --include='phpunit.xml*' --exclude-dir=vendor .
# 6. Test suites running on SQLite
grep -rniE 'sqlite' --include='phpunit.xml*' --include='.env*' --include='*.php' --exclude-dir=vendor .
# 7. MySQL identifiers or prompt text containing file-access keywords
grep -rniE 'outfile|dumpfile|load_file' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git .
# 8. Queries in prompts or fixtures that start with a comment
grep -rniE '/\*.*\*/ *(select|with|show|explain|describe)\b' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git .
```

Follow the hits:

- Search 1: open every `extends` of a select, write or schema tool (Cases 2, 5 and 6), every call site that invokes a
  tool or reads its result (Cases 1 and 5), and every place a toolkit is built: note which PDO it receives
  (`DB::connection()->getPdo()`, a container service) for Case 4.
- Searches 2, 4, 8: keep only hits related to the SQL tools or to an agent that has them.
- Search 7: keep the table and column names of the database a MySQL select tool reads (migrations, models, SQL dumps)
  and any prompt text. If that database's schema is not defined in this repository, ask the developer whether any of
  its table or column names contains `outfile`, `dumpfile` or `load_file`.
- Searches 5 and 6: keep hits that open a transaction on, or configure SQLite for, the PDO a select tool uses
  (directly or through an agent), in application code or in tests.
- Grep cannot find everything in prompts: read the `instructions()` / system prompt text of every agent that has an
  SQL toolkit, plus its few-shot examples and test fixtures (Case 3).

If search 1 finds nothing, this guide does not apply.

## How to Refactor

### Case 1: Code that reads select-tool refusals or database errors

All 3.x shapes below become one check. It works for the value returned by `$tool(...)`, for `$tool->getResult()`,
and for `$call->getResult()` on a `ToolCall` (guide 4), including in event listeners (guide 46 migrates the event
object itself). Call `hasResult()` first where the tool may not have run.

Before (3.x):

```php
use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSelectTool;

// PostgreSQL: a refusal is an array with an "error" key
$rows = PGSQLSelectTool::make($pdo)($query);
if (is_array($rows) && isset($rows['error'])) {
    $logger->warning($rows['error']);
}

// MySQL: a refusal is a string, a database error is thrown
try {
    $rows = MySQLSelectTool::make($pdo)($query);
    if (is_string($rows)) {
        $logger->warning($rows);
    }
} catch (PDOException $e) {
    $logger->warning($e->getMessage());
}

// After the agent ran the tool
$result = $tool->getResult();
$error = json_decode($result, true)['error'] ?? null;
if ($error !== null || str_contains($result, 'rejected for security reasons')) {
    $logger->warning($error ?? $result);
}
```

After (4.x):

```php
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSelectTool;
use PDOException;

// Both select tools: PDOException still escapes when the read-only transaction cannot start
try {
    $rows = MySQLSelectTool::make($pdo)($query);
    if ($rows instanceof ToolOutput && $rows->isError()) {
        $logger->warning($rows->getText());
    }
} catch (PDOException $e) {
    $logger->warning($e->getMessage());
}

// PostgreSQL: query errors other than a refused write also escape
try {
    $rows = PGSQLSelectTool::make($pdo)($query);
    if ($rows instanceof ToolOutput && $rows->isError()) {
        $logger->warning($rows->getText());
    }
} catch (PDOException $e) {
    $logger->warning($e->getMessage());
}

// After the agent ran the tool
$result = $tool->getResult();
if ($result instanceof ToolOutput && $result->isError()) {
    $logger->warning($result->getText());
}
```

1. Replace each 3.x check (`isset($r['error'])`, `is_string($r)`, `'rejected for security reasons'`,
   `json_decode(...)['error']`) with `instanceof ToolOutput && isError()` and read the message with `getText()`.
2. Keep a `catch (PDOException)` around both select tools. `MySQLSelectTool` now returns query errors (missing table,
   syntax error) as `ToolOutput::error()`, but both tools still throw `PDOException` when the read-only transaction
   cannot start (lost connection, SQLite, a server without `START TRANSACTION READ ONLY`), and `PGSQLSelectTool` also
   throws for query errors other than SQLSTATE `25006`. Only a catch around a `MySQLWriteTool` call can go (Case 5).
3. A `catch (PDOException)` around an agent run no longer sees MySQL select or write errors: the model receives
   them and the run continues. Leave the catch in place (PostgreSQL errors still escape) and report it to the developer. A
   `toolErrorHandler` branch for PDOExceptions from `mysql_select_query` now fires only when the read-only transaction
   cannot start; leave it too.

### Case 2: Subclasses of `MySQLSelectTool` or `PGSQLSelectTool`

The regex hooks are gone. Rules go in `refusal(string $query): ?string`, which returns the message for the model or
`null`, and ends with `return parent::refusal($query);`.

**A PostgreSQL `$allowedPatterns` override** becomes `$allowedStatements`: UPPERCASE keywords, compared with the
uppercased first word of the query.

Before (3.x):

```php
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSelectTool;

class AnalyticsSelectTool extends PGSQLSelectTool
{
    protected array $allowedPatterns = ['/^\s*SELECT\s+/i', '/^\s*WITH\s+/i', '/^\s*TABLE\s+/i'];
}
```

After (4.x):

```php
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSelectTool;

class AnalyticsSelectTool extends PGSQLSelectTool
{
    protected array $allowedStatements = ['SELECT', 'WITH', 'TABLE'];
}
```

**Extra forbidden keywords or patterns** (`$forbiddenStatements`, `$forbiddenPatterns`) move into `refusal()`. Keep
only the entries the application added to the 3.x defaults: the database now refuses every write, so the default
entries need no replacement.

Before (3.x):

```php
use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;

class ReportingSelectTool extends MySQLSelectTool
{
    protected array $forbiddenStatements = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER',
        'TRUNCATE', 'REPLACE', 'MERGE', 'CALL', 'EXECUTE',
        'INTO', 'OUTFILE', 'DUMPFILE', 'LOAD_FILE',
        'BENCHMARK',
    ];
}
```

After (4.x):

```php
use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;

class ReportingSelectTool extends MySQLSelectTool
{
    protected function refusal(string $query): ?string
    {
        if (preg_match('/\bBENCHMARK\b/i', $query) === 1) {
            return 'BENCHMARK is not allowed.';
        }

        return parent::refusal($query);
    }
}
```

**An `__invoke()` override** must declare `: array|ToolOutput`: a MySQL `: string|array` is a fatal incompatible
declaration, and a PostgreSQL `: array` fails with a `TypeError` as soon as the parent returns a refusal. It returns
`ToolOutput::error('…')` instead of a string or `['error' => …]`, moves text checks into `refusal()`, and calls
`parent::__invoke($query, $parameters)` so the query runs in the read-only transaction.

Before (3.x):

```php
use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;

class LimitedSelectTool extends MySQLSelectTool
{
    public function __invoke(string $query, ?array $parameters = []): string|array
    {
        if (stripos($query, 'salary') !== false) {
            return 'Salary data is not available.';
        }

        $rows = parent::__invoke($query, $parameters);

        return is_array($rows) ? array_slice($rows, 0, 100) : $rows;
    }
}
```

After (4.x):

```php
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;

class LimitedSelectTool extends MySQLSelectTool
{
    public function __invoke(string $query, ?array $parameters = []): array|ToolOutput
    {
        $rows = parent::__invoke($query, $parameters);

        return $rows instanceof ToolOutput ? $rows : array_slice($rows, 0, 100);
    }

    protected function refusal(string $query): ?string
    {
        if (stripos($query, 'salary') !== false) {
            return 'Salary data is not available.';
        }

        return parent::refusal($query);
    }
}
```

Also:

- An override that runs the query itself (its own `prepare()`/`execute()`) bypasses the read-only transaction: move
  that code into `protected function fetchRows(string $query, array $parameters): array`, which runs inside it.
- Overrides of the removed methods are no longer called: move any extra rule they added into `refusal()`, delete the
  rest.
- Overrides that only loosened the 3.x checks (removed `REPLACE`, `INTO` or `SET`, or worked around the
  `eval`/`exec`/`system` substring check): delete them.
- MySQL `$allowedStatements` and `getFirstKeyword()` overrides: no change.

### Case 3: Prompts, examples and fixtures that shape the model's queries

The select tools refuse (with a message telling the model what to change):

- anything but whitespace before the first keyword, including a `/* … */` or `--` comment (both tools; a comment may
  follow the keyword);
- a `;` anywhere but at the very end: one statement per call, values containing `;` go in `parameters`
  (this matters mostly for MySQL prompts: 3.x `PGSQLSelectTool` already refused values containing `;`);
- MySQL only: `OUTFILE`, `DUMPFILE` or `LOAD_FILE` anywhere in the query, as a substring.

Before (3.x):

```sql
/* agent: weekly-report */ SELECT * FROM orders WHERE note = 'a;b'
```

After (4.x):

```sql
SELECT /* agent: weekly-report */ * FROM orders WHERE note = :note
```

with the parameter `note` set to `a;b`.

Update instructions, few-shot examples and test fixtures that produce the Before shape. If a MySQL table or column
name contains `outfile`, `dumpfile` or `load_file` (search 7, e.g. `upload_file_id`, `download_file_url` or `load_file_path`), the model can no longer query
it through `MySQLSelectTool`. Do not work around it: report it to the developer and ask which they prefer: a view whose
table and column names avoid these substrings (an `AS` alias does not help, because the whole query text is checked;
the schema tool lists base tables only, so the agent's instructions must name the view), renaming the column, or a
subclass that relaxes the check (for example a narrower `$fileAccessKeywords`), which is safe only when the
connection's database user lacks the `FILE` privilege.

### Case 4: Select tools on a connection with an open transaction, or on SQLite

A select tool throws `NeuronAI\Exceptions\ToolException` ("mysql_select_query cannot guarantee read-only access
inside an open transaction: give it its own database connection.", with the tool's name) when its PDO is inside a
transaction, because its rollback would discard the application's work. On a SQLite PDO every select call
throws `PDOException` (`near "START": syntax error`).

Look for, and list:

1. Agent runs that can call a select tool inside `DB::transaction()`, `->transaction(...)`, `beginTransaction()`,
   Doctrine `transactional()` / `wrapInTransaction()` on the connection whose PDO the toolkit receives, for example `MySQLToolkit::make(DB::connection()->getPdo())` inside a
   transactional job, command or controller.
2. Tests that exercise a select tool (directly or through an agent) while the test framework wraps each test in a
   transaction on that connection: Laravel `RefreshDatabase` / `LazilyRefreshDatabase` / `DatabaseTransactions`,
   Symfony DAMA DoctrineTestBundle.
3. Tests that run a select tool on a SQLite connection (search 6).
4. Catch-all tool error handlers (`toolErrorHandler(...)` or `resolveToolErrorHandler()` returning a result for any
   `Throwable`): they turn these exceptions into model feedback and hide the failure. Do not change them.

Report every site to the developer and ask whether to move the agent call out of the transaction or give the SQL
toolkit its own database connection (for tests: a separate connection, or a MySQL/PostgreSQL test database). Do not
create connections, database users or configuration yourself.

Also ask the developer to confirm the server supports `START TRANSACTION READ ONLY`: MySQL 5.6.5 or later, MariaDB
10.0 or later, any PostgreSQL. On an older server every select call fails.

### Case 5: Code that reads write-tool results

`MySQLWriteTool`, before (3.x):

```php
use NeuronAI\Tools\Toolkits\MySQL\MySQLWriteTool;

$orderId = null;
try {
    $result = MySQLWriteTool::make($pdo)(
        'INSERT INTO orders (total) VALUES (:total)',
        [['name' => 'total', 'value' => '10']]
    );
    if (str_starts_with($result, 'Error executing query')) {
        $logger->error($result);
    } elseif (preg_match('/Last insert ID: (\d+)/', $result, $matches) === 1) {
        $orderId = (int) $matches[1];
    }
} catch (PDOException $e) {
    $logger->error($e->getMessage());
}
```

After (4.x):

```php
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\Toolkits\MySQL\MySQLWriteTool;

$orderId = null;
$result = MySQLWriteTool::make($pdo)(
    'INSERT INTO orders (total) VALUES (:total)',
    [['name' => 'total', 'value' => '10']]
);

// MySQLWriteTool returns a ToolOutput only for errors
if ($result instanceof ToolOutput) {
    $logger->error($result->getText());
} elseif (preg_match('/First generated ID: (\d+)\./', $result, $matches) === 1) {
    $orderId = (int) $matches[1];
}
```

`PGSQLWriteTool`, before (3.x):

```php
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLWriteTool;

$orderId = null;
$result = PGSQLWriteTool::make($pdo)(
    'INSERT INTO orders (total) VALUES (:total)',
    [['name' => 'total', 'value' => '10']]
);
if (preg_match('/Last insert ID: (\d+)/', $result, $matches) === 1) {
    $orderId = (int) $matches[1];
}
```

After (4.x):

```php
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLWriteTool;

$orderId = null;
$result = PGSQLWriteTool::make($pdo)(
    'INSERT INTO orders (total) VALUES (:total) RETURNING id',
    [['name' => 'total', 'value' => '10']]
);
if (preg_match('/ Returned rows: (.*)$/s', $result, $matches) === 1) {
    $orderId = (int) json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR)[0]['id'];
}
```

1. `MySQLWriteTool`: replace `catch (PDOException)` and `"Error executing query"` checks with the `ToolOutput`
   check; parse `First generated ID: (\d+)\.` instead of `Last insert ID: (\d+)`. The same applies to
   `getResult()` readers and test assertions on `mysql_write_query` results.
2. `PGSQLWriteTool`: errors still escape as `PDOException` (keep those catches). Code, prompts or fixtures that
   relied on `Last insert ID` use a `RETURNING` clause and read the JSON after `Returned rows: `. When the model
   writes the query, update prompt text that mentions `Last insert ID`; the tool's own description already asks for
   `RETURNING`.
3. Assertions on the exact success text: the MySQL ID suffix now ends with `.`, and both tools append
   `Returned rows: [JSON]` when the statement returns rows.
4. A `MySQLWriteTool` subclass that overrides `__invoke()` declares `: string|ToolOutput` and returns a `ToolOutput`
   from `parent::__invoke()` unchanged. A 3.x `: string` declaration still compiles, and PHPStan does not report it,
   but it throws `TypeError` on the first database error. Drop a `catch (PDOException)` around `parent::__invoke()`:
   errors now come back as `ToolOutput::error()`. `PGSQLWriteTool::__invoke()` still returns `string`, so its
   overrides need no change.

```php
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\Toolkits\MySQL\MySQLWriteTool;

class AuditedWriteTool extends MySQLWriteTool
{
    public function __invoke(string $query, ?array $parameters = []): string|ToolOutput
    {
        $this->audit($query);

        return parent::__invoke($query, $parameters);
    }
}
```

### Case 6: Subclasses of `MySQLSchemaTool` or `PGSQLSchemaTool`

`getConstraints()` is gone and `formatForLLM()` no longer receives `$structure['constraints']` (the built-in
`formatForLLM()` never rendered it).

- A `getConstraints()` override with no `formatForLLM()` override reading its data had no effect in 3.x either:
  delete it.
- A `formatForLLM()` override that rendered `$structure['constraints']` fetches the data itself.

Before (3.x):

```php
use NeuronAI\Tools\Toolkits\MySQL\MySQLSchemaTool;

class DocumentedSchemaTool extends MySQLSchemaTool
{
    protected function formatForLLM(array $structure): string
    {
        $output = parent::formatForLLM($structure);

        $output .= "\n## Unique Constraints\n";
        foreach ($structure['constraints'] as $constraint) {
            $output .= "- {$constraint['TABLE_NAME']}: {$constraint['CONSTRAINT_NAME']}\n";
        }

        return $output;
    }
}
```

After (4.x):

```php
use NeuronAI\Tools\Toolkits\MySQL\MySQLSchemaTool;
use PDO;

class DocumentedSchemaTool extends MySQLSchemaTool
{
    protected function formatForLLM(array $structure): string
    {
        $output = parent::formatForLLM($structure);

        $output .= "\n## Unique Constraints\n";
        foreach ($this->uniqueConstraints() as $constraint) {
            $output .= "- {$constraint['TABLE_NAME']}: {$constraint['CONSTRAINT_NAME']}\n";
        }

        return $output;
    }

    protected function uniqueConstraints(): array
    {
        $whereClause = "WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE IN ('UNIQUE')";
        $params = [];

        if ($this->tables !== null && $this->tables !== []) {
            $whereClause .= ' AND TABLE_NAME IN (' . implode(',', array_fill(0, count($this->tables), '?')) . ')';
            $params = $this->tables;
        }

        $statement = $this->pdo->prepare("
            SELECT CONSTRAINT_NAME, TABLE_NAME, CONSTRAINT_TYPE
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            $whereClause
        ");
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
```

If the subclass overrode `getConstraints()` with its own query, move that body into the new method instead. For
`PGSQLSchemaTool` the 3.x query read `information_schema.table_constraints` with
`table_schema = current_schema() AND constraint_type IN ('UNIQUE', 'CHECK')` (plus `table_name = ANY(...)` for
`$tables`), and its keys are lowercase (`constraint_name`, `table_name`, `constraint_type`).

## Recommend to the developer (do not apply)

A read-only transaction does not limit what the connection's user can read, and it does not stop statements that do
not write: sleeps, file reads for privileged users, or ending other sessions of the same user. The schema tools'
`$tables` list only limits what the model sees. Recommend a dedicated connection for the SQL toolkit whose database
user can only `SELECT` the tables the agent needs, with a statement timeout. Creating users, changing grants and
editing connection settings are the developer's decision: do not do any of it without their explicit approval.

## Checklist

- Every reader of select-tool or `MySQLWriteTool` results checks `instanceof ToolOutput && isError()` (or
  `instanceof ToolOutput` for `MySQLWriteTool`); no `isset($r['error'])`, `is_string($r)`,
  `'rejected for security reasons'` or `'Error executing query'` checks remain for these tools.
- No `catch (PDOException)` wraps only a `MySQLWriteTool` call; catches around both select tools and `PGSQLWriteTool`
  are kept.
- Every `__invoke()` override of a select tool declares `: array|ToolOutput` (not `string|array` or `array`), and every
  `__invoke()` override of `MySQLWriteTool` declares `: string|ToolOutput`. Overrides return `ToolOutput::error()` for
  their own refusals and call `parent::__invoke()`.
- Search 3 finds nothing related to the SQL tools: no removed members, no `getConstraints()` calls or overrides, no
  `$structure['constraints']`.
- PostgreSQL `$allowedStatements` entries are uppercase keywords.
- No code, prompt or fixture parses `Last insert ID`; MySQL readers parse `First generated ID: N.`, PostgreSQL
  readers use `RETURNING` and `Returned rows: `.
- No prompt, example or fixture starts a query with a comment or sends several statements in one call.
- Reported to the developer: transaction and test-suite sites (including SQLite test connections and catch-all tool
  error handlers), MySQL identifiers containing `outfile`/`dumpfile`/`load_file`, the server-version check, and the
  dedicated least-privilege connection. Nothing was created or changed on the database side.
