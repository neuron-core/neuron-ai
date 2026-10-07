# Upgrade Guides — How to Apply Them

This directory contains the step-by-step guides for upgrading an application from Neuron AI 3.x to 4.x. Each numbered file (`0-*.md`, `1-*.md`, ...) documents one breaking change: what changed, how to find affected code, and how to refactor it. Every guide migrates 3.x code straight to the final 4.x API, and each API is migrated by exactly one guide: when a guide points to another guide for a symbol, leave that symbol alone until you reach that guide.

Your job is to upgrade the **application codebase** you are working in (the project that depends on `neuron-core/neuron-ai`), not the framework itself.

## First-Party Packages

Neuron's first-party packages release a new major version for Neuron 4.x. Their 3.x-compatible versions require `neuron-core/neuron-ai` `^3.0`, so Composer cannot install Neuron 4.x until they are upgraded too. If `composer.json` requires one of them, raise its constraint in the same `composer update` that moves Neuron to `^4.0`:

| Package | Version for Neuron 3.x | Version for Neuron 4.x |
|---|---|---|
| [`neuron-core/router`](https://github.com/neuron-core/router) | `^1.0` | `^2.0` |
| [`neuron-core/raptor-retrieval`](https://github.com/neuron-core/raptor-retrieval) | `^3.0` | `^4.0` |

```
grep -nE '"neuron-core/(router|raptor-retrieval)"' composer.json
```

## Process

Work through the guides **one at a time, in numeric order**. Do not read all the guides upfront and apply them in a single pass — complete each step fully before opening the next one.

For each guide:

1. **Read the guide** from top to bottom before touching any code.

2. **Explore the application codebase** to find affected code. Every guide has a "What to Search For" section with grep patterns — run them from the application root, excluding `vendor/`. Don't stop at the literal patterns: also follow the code you find (imports, subclasses, call sites) to catch usages the patterns miss. If a search returns no matches, note that the step does not apply and move on.

3. **Apply the refactoring** to every affected file, following the guide's Before/After examples. Preserve the application's existing behavior, namespaces, and code style — you are translating old API usage to new API usage, not redesigning the code.

4. **Verify** before moving to the next guide:
   - Run the guide's checklist against each modified file.
   - Re-run the guide's search patterns to confirm no old-API usage remains.
   - If the application has static analysis (`vendor/bin/phpstan`), run it and check that no error mentions a symbol this guide migrates. Errors about symbols that later guides migrate are expected until those guides are applied.

5. **Report** what you changed for this step (files touched, patterns found, anything skipped) before starting the next one.

Guide 0 reinstalls the agent skills and ends with a stop: report to the user and continue with guide 1 in a new coding-agent session that the user starts. Do not restart or continue on your own.

After the last guide, run the application's full test suite and static analysis (`composer test`, `vendor/bin/phpunit`, `vendor/bin/phpstan`) and fix the remaining failures the upgrade caused.

## Rules

- **One step at a time.** A later guide may build on the API established by an earlier one — applying them out of order or interleaved produces broken intermediate states.
- **Search exhaustively.** A pattern that "should only exist in one place" often exists in several. Grep the whole application source, including tests and config.
- **Don't touch `vendor/`.** The framework code is already upgraded; only the application code needs changes.
- **Don't fix unrelated issues.** If you notice pre-existing problems outside the scope of the current guide, mention them in your report — don't change them.
- **When a guide mentions a database migration** (e.g., renamed columns), generate the migration in the application's own migration system; don't run raw DDL against a database unless asked.
- **If something is ambiguous** — a usage the guide doesn't cover, or two plausible refactorings — surface it and ask rather than guessing silently.

## Conclusion

After completing the update process, look at composer.json and the project structure to tell whether it is a Laravel app or a Symfony app, then
activate the matching skill: neuron-laravel-integration or neuron-symfony-integration. Go through the skill's foundations checklist item by item.
Report what differs from the recommended setup. Finish with your recommendations on how to improve the Neuron integration based on the best practices.
