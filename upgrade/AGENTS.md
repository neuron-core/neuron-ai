# Upgrade Guides — Instructions for Coding Agents

This directory contains the step-by-step guides to upgrade an application from the
previous major version of Neuron AI to this one. Each numbered file describes a single
breaking change: what changed in the framework, how to find the affected code in the
application, and how to adjust it.

## How to Run the Upgrade

Process the guides **one at a time, in numeric order** (`1-*.md`, `2-*.md`, ...).
Do not open them all at once — each guide is self-contained, and working on one
change at a time keeps the diff reviewable and the verification meaningful.

For each guide:

1. **Read the guide in full.** Start with the summary to understand what feature
   or API changed and why. Do not start editing before finishing the guide — later
   sections often narrow the scope (e.g. "only affects standalone usage") or list
   exceptions you must not touch.

2. **Search the application code for usage of that feature.** Every guide has a
   "What to Search For" section with concrete patterns (grep commands, class names,
   method signatures, imports). Run those searches against the application codebase.
   - If a search yields **no matches**, the application doesn't use that feature:
     record the guide as "not applicable" and move to the next one.
   - If there are matches, list every affected file before changing anything.

3. **Apply the required adjustment.** Follow the guide's refactoring instructions
   for each affected file. Apply the change exactly as described — the guides show
   before/after code for each pattern. Touch only the code the guide covers; do not
   refactor or "improve" surrounding code.

4. **Verify before moving on.** Run the application's test suite (and static
   analysis, if configured) after each guide. Fix any failures caused by the change
   before opening the next guide. Re-run the guide's searches to confirm no
   occurrence of the old pattern remains.

## Rules

- **Order matters.** Later guides may assume earlier ones are already applied.
  Never skip ahead.
- **One guide, one commit.** If you are committing your work, commit after each
  guide so every breaking change is traceable to a single, reviewable diff.
- **Respect the scope notes.** Several guides explicitly exclude code paths
  (e.g. Agent handler usage, providers used inside an Agent). Excluded code must
  be left untouched even if it superficially matches a search pattern.
- **When a match is ambiguous, stop and report.** If application code matches a
  search pattern but doesn't fit any before/after case in the guide, don't guess:
  surface the file and the mismatch to the user and ask how to proceed.

## Final Check

After the last guide, run the full test suite and static analysis one more time,
then re-run the searches from every guide to confirm the application no longer
references any removed or changed API.
