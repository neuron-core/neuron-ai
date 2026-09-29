# Upgrade: `FileDataLoader` names files by path and skips hidden entries and symlinks

## Summary

In 3.x, loading a directory with `FileDataLoader` named each document after its bare file name, read hidden files and
followed every symlink it found. Loading a single file turned any error into an empty result.

- **A file loaded from a directory is named after its path.** `sourceName` is the directory you passed joined with the
  file's path inside it (`/docs/tenant-a/readme.txt`), the same name the file gets when loaded alone. In 3.x it was the
  bare file name (`readme.txt`), so same-named files in different folders shared a source: `reindexBySource()` for one
  folder deleted the other's chunks, and a file reindexed alone never replaced the chunks of a directory load. A
  trailing `/` on the directory makes no difference.
- **Hidden entries are skipped while walking a directory.** Files and directories whose name starts with a dot, such
  as `.env`, `.git` or `.DS_Store`, are no longer read. A hidden file or directory passed as the loader path is still
  loaded.
- **Symlinks are skipped while walking a directory.** In 3.x a link back to an ancestor directory loaded every file
  about 40 times, a link could pull in content from outside the directory, and a broken link failed the load. The
  loader path itself is still followed when it is a symlink.
- **Errors on a single file reach the caller.** In 3.x `getDocuments()` on a single file returned an empty array
  whatever failed: a missing `pdftotext`, an unreadable file, a reader exception. A directory load already threw.

The source name is also what the model reads as `Source Name` in the retrieved context, so documents loaded from a
directory now show it the file's path, as single-file loads already did.

| Before (3.x) | After |
|---|---|
| `FileDataLoader::for('/docs')` names `/docs/a/readme.txt` as `readme.txt` | `/docs/a/readme.txt` |
| `/docs/.env` and `/docs/.git/config` become documents | Skipped |
| `/docs/shared -> /srv/manuals` is loaded, `/docs/loop -> /docs` loads every file about 40 times | Both skipped |
| `FileDataLoader::for('/docs/manual.pdf')` returns `[]` when extraction fails | Throws the reader's exception |

## How to Refactor

### Case 1: Filters or code expecting bare file names

A filter on `sourceName` written with the bare name of a file loaded from a directory no longer matches. Use the path:

Before:

```php
$store->delete(Filter::where('sourceType', 'files')->where('sourceName', 'guide.txt'));
```

After:

```php
$store->delete(Filter::where('sourceType', 'files')->where('sourceName', '/path/to/docs/guide.txt'));
```

Code that shows the source to users, such as a list of cited files, can keep showing the file name with
`basename($document->getSourceName())`.

### Case 2: An index built by 3.x from a directory

The chunks 3.x stored carry bare names, so `reindexBySource()` no longer finds them: reindexing a file adds its new
chunks next to the old ones. Chunks of hidden files that 3.x indexed, `.env` included, stay in the store, because no load
reads those files any more. Rebuild the file-based index once: delete the `files` chunks and ingest every file source
again.

```php
$store->delete(Filter::eq('sourceType', 'files'));

$rag->addDocuments(FileDataLoader::for('/path/to/docs')->getDocuments());
```

This deletes data from the developer's vector store: report the stores concerned and the rebuild, and run nothing
without their approval. If 3.x indexed a directory holding secrets such as `.env`, they were sent to the embeddings
provider and stored where retrieval could quote them: tell the developer, since rotating those credentials is their
decision.

### Case 3: Content reached through symlinks or kept in hidden directories

Content that must stay indexed gets its own loader, pointed at the real location:

Before:

```php
// /path/to/docs/manuals is a symlink to /srv/manuals
$documents = FileDataLoader::for('/path/to/docs')->getDocuments();
```

After:

```php
$documents = [
    ...FileDataLoader::for('/path/to/docs')->getDocuments(),
    ...FileDataLoader::for('/srv/manuals')->getDocuments(),
];
```

### Case 4: Code relying on an empty result for a failing file

Where a failure should stop the job, nothing changes: the exception now says what went wrong. Where the application
deliberately skips files it cannot read, catch the exception around that file:

```php
try {
    $rag->reindexBySource(FileDataLoader::for($path)->getDocuments());
} catch (Throwable $e) {
    $logger->warning("Could not index {$path}: {$e->getMessage()}");
}
```

## What to Search For

```
grep -rn "FileDataLoader" --include="*.php" .
grep -rnE "sourceName|getSourceName\(" --include="*.php" .
grep -rn "reindexBySource" --include="*.php" .
```

For each directory the application loads, when it is available locally:

```
find /path/to/docs -type l
find /path/to/docs -name '.*'
```

## Checklist

- No filter or comparison on `sourceName` expects the bare name of a file loaded from a directory.
- Content reached through a symlink or kept in a hidden directory that must stay indexed has its own loader.
- The developer knows that indexes built by 3.x from directories need a one-time rebuild, and whether secrets such as
  `.env` may have been indexed; nothing was deleted or re-ingested without their approval.
- Where the application skips files it cannot read, the exception is caught around that file.
