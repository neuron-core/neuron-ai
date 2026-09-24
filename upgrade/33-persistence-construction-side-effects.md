# Upgrade: Database and file persistence construct without side effects

## Summary

- **`DatabasePersistence` no longer changes the PDO it receives.** It used to switch the connection to
  `PDO::ERRMODE_EXCEPTION`, changing how every other query of the application reports errors. It now requires
  that mode, the default since PHP 8, and throws `PersistenceException` at construction for a PDO in
  `ERRMODE_SILENT` or `ERRMODE_WARNING`: in those modes a failed write would pass unnoticed.
- **The MySQL strict-mode check runs at the first write.** Construction runs no query. A connection outside strict
  SQL mode raises the same `PersistenceException` when the persistence first writes, not when it is constructed.
- **`DatabasePersistence` joins a transaction the application opened on the same PDO.** The first write used to fail
  with `There is already an active transaction`, so every workflow run inside an application transaction failed,
  including tests that Laravel's `RefreshDatabase` or Symfony's DAMA bundle wrap in a transaction. Each operation
  now runs in a savepoint: its writes commit or roll back with the enclosing transaction, and a failed operation
  undoes only its own writes.
- **`FilePersistence` creates its directory on the first write.** Construction no longer touches the file system,
  and reads from a directory that does not exist return no records.

## How to Refactor

### Case 1: A PDO in silent or warning error mode

Set exception mode yourself, which is what construction used to do to your connection. When the rest of the
application depends on silent errors, give the persistence its own connection instead.

Before:

```php
$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
$persistence = new DatabasePersistence($pdo);
```

After:

```php
$persistence = new DatabasePersistence(new PDO($dsn, $user, $password));
```

### Case 2: Code expecting the strict-mode error from the constructor

A test expecting the constructor to reject a connection outside strict SQL mode must expect the error from the first
write instead.

Before:

```php
$this->expectException(PersistenceException::class);
new DatabasePersistence($pdo);
```

After:

```php
$persistence = new DatabasePersistence($pdo);
$this->expectException(PersistenceException::class);
$persistence->initializeIfAbsent('health-check', '__control', 'probe');
```

### Case 3: Workflows run inside the application's transaction

Code that committed before running a workflow, or gave the persistence a separate connection to avoid the old error,
keeps working unchanged. A workflow run inside the application's transaction now belongs to it:

- its records become durable only when the application commits, and roll back with it;
- the rows it locks stay locked until the transaction ends, so another worker continuing the same workflow waits.

Keep such transactions short, or run the workflow outside them when other workers must see its progress at once.

### Case 4: Code relying on the directory existing after construction

Before:

```php
$persistence = new FilePersistence($directory);
file_put_contents($directory . '/workflow.store', $contents);
```

After:

```php
mkdir($directory, 0o700, true);
file_put_contents($directory . '/workflow.store', $contents);
$persistence = new FilePersistence($directory);
```

## What to Search For

```
grep -rn "new DatabasePersistence(" --include="*.php" .
grep -rn "ERRMODE_SILENT\|ERRMODE_WARNING" --include="*.php" .
grep -rn "strict SQL mode" --include="*.php" .
grep -rn "new FilePersistence(" --include="*.php" .
```

## Checklist

- Every PDO given to `DatabasePersistence` uses `PDO::ERRMODE_EXCEPTION`.
- No code expects the MySQL strict-mode error from the constructor.
- Code running workflows inside its own transaction expects their records to commit or roll back with it.
- No code relies on `FilePersistence` creating its directory before the first write.
