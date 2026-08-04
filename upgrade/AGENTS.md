# Upgrade Guides — How to Apply Them

This directory contains the step-by-step guides for upgrading an application from Neuron AI 3.x to 4.x. Each numbered file (`1-*.md`, `2-*.md`, ...) documents one breaking change: what changed, how to find affected code, and how to refactor it.

Your job is to upgrade the **application codebase** you are working in (the project that depends on `inspector-apm/neuron-ai`), not the framework itself.

## Process

Work through the guides **one at a time, in numeric order**. Do not read all the guides upfront and apply them in a single pass — complete each step fully before opening the next one.

For each guide:

1. **Read the guide** from top to bottom before touching any code.

2. **Explore the application codebase** to find affected code. Every guide has a "What to Search For" section with grep patterns — run them from the application root, excluding `vendor/`. Don't stop at the literal patterns: also follow the code you find (imports, subclasses, call sites) to catch usages the patterns miss. If a search returns no matches, note that the step does not apply and move on.

3. **Apply the refactoring** to every affected file, following the guide's Before/After examples. Preserve the application's existing behavior, namespaces, and code style — you are translating old API usage to new API usage, not redesigning the code.

4. **Verify** before moving to the next guide:
   - Run the guide's checklist against each modified file, if the guide has one.
   - Run the application's test suite and/or static analysis if available (`composer test`, `vendor/bin/phpunit`, `vendor/bin/phpstan`).
   - Re-run the guide's search patterns to confirm no old-API usage remains.

5. **Report** what you changed for this step (files touched, patterns found, anything skipped) before starting the next one.

## Rules

- **One step at a time.** A later guide may build on the API established by an earlier one — applying them out of order or interleaved produces broken intermediate states.
- **Search exhaustively.** A pattern that "should only exist in one place" often exists in several. Grep the whole application source, including tests and config.
- **Don't touch `vendor/`.** The framework code is already upgraded; only the application code needs changes.
- **Don't fix unrelated issues.** If you notice pre-existing problems outside the scope of the current guide, mention them in your report — don't change them.
- **When a guide mentions a database migration** (e.g., renamed columns), generate the migration in the application's own migration system; don't run raw DDL against a database unless asked.
- **If something is ambiguous** — a usage the guide doesn't cover, or two plausible refactorings — surface it and ask rather than guessing silently.
