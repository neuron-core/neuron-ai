# Upgrade: SQL select tools enforce read-only access in the database

## Summary

In 3.x `MySQLSelectTool` and `PGSQLSelectTool` decided whether a query was read-only by inspecting its text with
regular expressions. The inspection could be fooled by a `'--'` literal, a `/**/` comment between two keywords, a
stacked statement, a MySQL executable comment or a function that writes. So a model steered by prompt injection could
delete or update data through a tool presented as read-only, and on a PostgreSQL superuser connection it could run
shell commands with `COPY ... TO PROGRAM`. The inspection also refused harmless queries such as
`SELECT REPLACE(name, 'a', 'b')` or `SELECT id FROM evaluations`. The database now enforces read-only access.

- **Each query runs alone in a read-only transaction that is always rolled back.** The tool sends
  `START TRANSACTION READ ONLY`, runs the query and rolls back. The database refuses any write, whatever the SQL looks
  like, and the rollback undoes session changes such as `set_config()`. MySQL 5.6.5+, MariaDB 10.0+ and PostgreSQL
  support it without configuration.
- **Three text rules remain, and each one refuses rather than interprets.** The query must start with an allowed
  keyword (`SELECT`, `WITH`, `SHOW`, `DESCRIBE`, `EXPLAIN` on MySQL; `SELECT`, `WITH`, `EXPLAIN`, `SHOW` on
  PostgreSQL) with nothing before it, not even a comment. It may contain a `;` only as its last character. On MySQL
  it may not contain `OUTFILE`, `DUMPFILE` or `LOAD_FILE` anywhere.
- **Refusals are `ToolOutput::error()`.** Each rule has its own message telling the model what to change. When the
  database refuses a write (SQLSTATE `25006`), the tool returns a refusal quoting the database's message.
  `PGSQLSelectTool` lets any other database error escape, as before. `MySQLSelectTool` returns every database error,
  such as a missing table or a syntax error, as `ToolOutput::error()` with the exception's message, so the model can
  correct its query.
- **The tools refuse to run inside an open transaction.** They throw a `ToolException` when the connection is already
  in a transaction, because their rollback would discard the application's work.
- **`MySQLSchemaTool` applies its table allow-list to indexes.** The "Available Indexes" section listed the indexes of
  every table in the database.

| Before (3.x) | After |
|---|---|
| `MySQLSelectTool` refusal: the string `"The query was rejected for security reasons. …"` | `ToolOutput::error()` with the rule's message |
| `PGSQLSelectTool` refusal: `['error' => "The query was rejected for security reasons. …"]` | `ToolOutput::error()` with the rule's message |
| `__invoke()` returning `string\|array` (MySQL) or `array` (PostgreSQL) | `__invoke(): array\|ToolOutput` on both |
| `/* report */ SELECT …` and `-- report` + newline + `SELECT …` accepted | Refused: nothing may come before the keyword |
| `SELECT 1; SELECT 2` accepted by `PGSQLSelectTool` | Refused: one statement per call |
| `DESCRIBE` and `DESC` accepted by `PGSQLSelectTool`, then rejected by PostgreSQL | Refused by the tool |
| A `SELECT` calling a function that writes ran the write | The database refuses it and the tool returns a refusal |
| `MySQLSelectTool`: a database error such as a missing table escaped as a `PDOException` | `ToolOutput::error()` with the exception's message |
| A call inside an open transaction on the same connection ran | `ToolException` |
| MySQL: protected `$forbiddenStatements`, `validateReadOnly()`, `sanitizeQuery()`, `containsKeyword()` | Removed; `refusal()`, `beginReadOnlyTransaction()`, `fetchRows()`, `$fileAccessKeywords` |
| PostgreSQL: protected `$forbiddenPatterns`, `$allowedPatterns`, `validateReadOnlyQuery()`, `removeComments()`, `performAdditionalSecurityChecks()`, `splitStatements()`, `validateSingleStatement()` | Removed; `$allowedStatements`, `getFirstKeyword()`, `refusal()`, `beginReadOnlyTransaction()`, `fetchRows()` |

## How to Refactor

### Case 1: Reading a refusal from the tool result

Code that recognizes a refusal, such as a test, a decorator around the tool or a tool error handler, checks for an
error `ToolOutput`.

Before:

```php
$result = $tool($query);

if (is_array($result) && isset($result['error'])) {
    $this->logger->warning($result['error']);
}
```

After:

```php
use NeuronAI\Tools\ToolOutput;

$result = $tool($query);

if ($result instanceof ToolOutput && $result->isError()) {
    $this->logger->warning($result->getText());
}
```

### Case 2: A subclass that customized the text checks

The regex hooks are gone. A PostgreSQL subclass that changed `$allowedPatterns` lists keywords in `$allowedStatements`
instead.

Before:

```php
class AnalyticsSelectTool extends PGSQLSelectTool
{
    protected array $allowedPatterns = ['/^\s*SELECT\s+/i', '/^\s*WITH\s+/i', '/^\s*TABLE\s+/i'];
}
```

After:

```php
class AnalyticsSelectTool extends PGSQLSelectTool
{
    protected array $allowedStatements = ['SELECT', 'WITH', 'TABLE'];
}
```

A subclass that added forbidden keywords or overrode a removed method moves its rule into an override of `refusal()`,
which returns the message for the model or `null`, and keeps the parent's rules. Keep only rules that refuse without
interpreting SQL, such as a plain substring match:

```php
class ReportingSelectTool extends MySQLSelectTool
{
    protected function refusal(string $query): ?string
    {
        if (stripos($query, 'BENCHMARK') !== false) {
            return 'BENCHMARK is not allowed.';
        }

        return parent::refusal($query);
    }
}
```

### Case 3: Prompts that shape the model's queries

Instructions that make the model tag its queries with a leading comment, or send several statements in one call, now
produce refusals. A comment may follow the keyword, and a value containing `;` goes in a parameter.

Before:

```sql
/* agent: weekly-report */ SELECT * FROM orders WHERE note = 'a;b'
```

After:

```sql
SELECT /* agent: weekly-report */ * FROM orders WHERE note = :note
```

with the parameter `note` set to `a;b`.

### Case 4: An agent called inside a transaction on the same connection

A select tool that shares its connection with an application transaction now throws, for example when the agent runs
inside `DB::transaction()` and the toolkit uses `DB::connection()->getPdo()`. Resolving it means either moving the
agent call out of the transaction, or giving the SQL tools their own connection. Both are the developer's decision:
report the affected call sites and ask which they want. Don't create database connections or users on your own.

## What the tools don't cover

A read-only transaction doesn't limit what the connection's user can read, and it doesn't stop functions that don't
write: sleeps, file reads for privileged users, or ending other sessions of the same user. A dedicated connection with
a user that can only `SELECT` the tables the agent needs, and a statement timeout, closes those gaps; the schema
tools' `$tables` allow-list doesn't, because it only limits what the model sees. Recommend this to the developer as
their decision, and don't create database users, change grants or edit connection settings without their approval.

## What to Search For

```
grep -rnE "MySQLSelectTool|PGSQLSelectTool|MySQLToolkit|PGSQLToolkit" --include="*.php" .
grep -rn "rejected for security reasons" .
grep -rnE "forbiddenStatements|forbiddenPatterns|allowedPatterns|sanitizeQuery|removeComments|validateReadOnly|performAdditionalSecurityChecks|splitStatements|validateSingleStatement|containsKeyword" --include="*.php" .
```

A toolkit match matters when the application reads the select tool's results, subclasses a select tool, or calls the
agent inside a transaction on the same connection.

## Checklist

- Code that reads select-tool results recognizes refusals as an error `ToolOutput`.
- Subclasses use `$allowedStatements` and `refusal()` instead of the removed members.
- No prompt asks the model to start a query with a comment or to send several statements.
- Agent calls inside a transaction on the toolkit's connection were reported to the developer.
- The dedicated connection and least-privilege user were recommended to the developer, and nothing was created or
  changed without their approval.
