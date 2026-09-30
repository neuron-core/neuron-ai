# Upgrade: Agent skills are reshaped and must be reinstalled

## Summary

The package's `skills/` folder ships the skills that coding agents (Claude Code, Codex, Cursor, ...)
load to write Neuron code. 3.x shipped eight skills and 4.x ships thirteen. Seven were renamed,
`neuron-structured-output` kept its name but has new content, five skills are new, and some skills
carry reference files next to `SKILL.md`.

| 3.x | 4.x |
|---|---|
| `neuron-agent-builder` | `neuron-agent` |
| `neuron-workflow-architect` | `neuron-workflow` |
| `neuron-tool-creator` | `neuron-tool` |
| `neuron-rag-specialist` | `neuron-rag` |
| `neuron-structured-output` | `neuron-structured-output` (same name, new content) |
| `neuron-test-engineer` | `neuron-test` |
| `neuron-evaluation-engineer` | `neuron-evaluation` |
| `neuron-debugger` | `neuron-monitoring` |
| | `neuron-streaming`, `neuron-tool-approval`, `neuron-frontend-integration`, `neuron-laravel-integration`, `neuron-symfony-integration` (new) |

Why a reinstall is needed:

- `npx skills add` copied each skill into `.agents/skills/<name>` (`~/.agents/skills/<name>` for a
  global install) and linked each agent's own directory (`.claude/skills/<name>`,
  `.windsurf/skills/<name>`, ...) to that copy. When only one agent directory was targeted, it copied
  the skill straight into that directory.
- Nothing points into `vendor/`, so `composer update` leaves the 3.x text in place.
- The names changed, so installing 4.x on top leaves the 3.x set next to it. Both sets then trigger
  on the same keywords.

This guide changes no PHP code. Guides 1 onward migrate the application code.

## What to Search For

Run these four searches from the application root.

1. Project skill directories and links, at any depth. This also lists dangling links:

   ```
   find . \( -name vendor -o -name node_modules -o -name .git \) -prune -o -path '*/skills/neuron-*' -prune -print
   ```

2. The skills CLI lock file:

   ```
   grep -nE '"neuron-(agent-builder|workflow-architect|tool-creator|rag-specialist|structured-output|test-engineer|evaluation-engineer|debugger)"' skills-lock.json 2>/dev/null
   ```

3. Global installs. These cover `~/.agents/skills`, `~/.claude/skills`, `~/.codeium/windsurf/skills`,
   `~/.config/opencode/skills` and similar paths:

   ```
   find -H ~/.[a-z]* -maxdepth 3 -path '*/skills/neuron-*' -prune -print 2>/dev/null
   ```

4. App instruction files that name the 3.x skills (for example `CLAUDE.md`, `AGENTS.md`,
   `.cursor/rules/*`, `.github/copilot-instructions.md`, docs, and app-authored skills):

   ```
   grep -rnE 'neuron-(agent-builder|workflow-architect|tool-creator|rag-specialist|test-engineer|evaluation-engineer|debugger)' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git .
   ```

   Ignore hits in `skills-lock.json` and inside the 3.x skill directories that search 1 lists.
   Step 1 removes those.

Decision rules:

- Remove only the eight 3.x names in the table. Any other `neuron-*` entry is either app-authored or
  already 4.x, so leave it alone. A `neuron-structured-output` path counts as 3.x only when
  `diff -rq <path> vendor/neuron-core/neuron-ai/skills/neuron-structured-output` prints something.
- If searches 1 and 3 list no path whose last segment is one of the eight 3.x names, and search 2
  prints nothing, the 3.x skills were never installed. Skip Step 1. Steps 2 to 4 still apply,
  because the 4.x skills teach the coding agent the APIs that the following guides introduce.
- If search 3 lists a path whose last segment is one of the eight 3.x names, ask the developer
  before touching it. Global skills are shared by every project on the machine, including projects
  still on 3.x. Ask: "Global Neuron 3.x skills are installed at <paths>. Other projects on this
  machine use them too. Should I replace them with the 4.x set globally, or keep them and install
  4.x only in this project?"

## How to Reinstall

### Step 1: Remove the 3.x set

1. Remove the project set:

   ```
   npx skills remove neuron-agent-builder neuron-workflow-architect neuron-tool-creator neuron-rag-specialist neuron-structured-output neuron-test-engineer neuron-evaluation-engineer neuron-debugger -y
   ```

2. Re-run search 1. Run `rm -rf <path>` on each remaining path whose last segment is one of the
   eight 3.x names. These are links in agent directories that the CLI does not manage. Never pass a
   `neuron-*` glob to `rm`.
3. Re-run search 2. If it still prints any of the eight keys (older skills CLI versions do not
   update `skills-lock.json` on a project remove), delete them under `"skills"`. A stale entry makes
   `npx skills experimental_install` try to restore a skill that no longer exists. With jq:

   ```
   jq 'del(.skills["neuron-agent-builder","neuron-workflow-architect","neuron-tool-creator","neuron-rag-specialist","neuron-structured-output","neuron-test-engineer","neuron-evaluation-engineer","neuron-debugger"])' skills-lock.json > skills-lock.json.tmp && mv skills-lock.json.tmp skills-lock.json
   ```

   Without jq, edit the JSON by hand.
4. Global set: do this only if the developer agreed to replace it. Run the command from item 1 with
   `-g` added. Then re-run search 3 and run `rm -rf` on each remaining path whose last segment is one
   of the eight names. If the developer wants to keep the global set, do not touch it.

If `npx skills` cannot run in this environment, do the removal with the `rm -rf` pass from item 2.
Then ask the developer to run the Step 2 command.

### Step 2: Install the 4.x set

Find each situation in the table that applies to you, and run its command:

| 3.x set found | Command |
|---|---|
| In the project, or nowhere | `npx skills add ./vendor/neuron-core/neuron-ai/skills -y` |
| Globally, and the developer agreed to replace it | `npx skills add ./vendor/neuron-core/neuron-ai/skills -g -y` |
| Globally, and the developer kept it | The project command. Report that the global 3.x skills remain and will keep suggesting 3.x APIs. |

To limit a project install to the agents that held the 3.x set, append `-a` followed by their agent
names. For example, `-a claude-code windsurf` covers `.claude/skills` and `.windsurf/skills`.
`.agents/skills` serves `codex`, `cursor`, `gemini-cli`, `github-copilot` and `opencode`. Keep `-a`
and its names at the end of the command.

Laravel and Symfony apps get `neuron-laravel-integration` and `neuron-symfony-integration` from the
same set.

### Step 3: Update instruction files

In every file from search 4, replace each 3.x skill name with its 4.x name from the table.

### Step 4: Stop for a new session

Stop here. Report to the developer what was removed and installed, and ask them to start a new
coding-agent session. Continue with guide 1 in that new session. Agents discover skills when a
session starts. Any 3.x skill text already loaded describes APIs that the following guides remove.

Also tell the developer to re-run the Step 2 command, with the same `-g` or `-a` flags, after every
future Neuron update. Composer does not refresh the installed copies.

## Checklist

- Search 1 lists none of `neuron-agent-builder`, `neuron-workflow-architect`, `neuron-tool-creator`,
  `neuron-rag-specialist`, `neuron-test-engineer`, `neuron-evaluation-engineer`, `neuron-debugger`.
  The same holds for search 3 if the global set was replaced.
- Every other `neuron-*` entry that search 1 lists is either a skill in
  `vendor/neuron-core/neuron-ai/skills` or an app-authored skill.
- For every `neuron-structured-output` path that search 1 lists (and search 3, if the global set was
  replaced), `diff -r <path> vendor/neuron-core/neuron-ai/skills/neuron-structured-output` prints
  nothing.
- `grep -nE '"neuron-(agent-builder|workflow-architect|tool-creator|rag-specialist|test-engineer|evaluation-engineer|debugger)"' skills-lock.json 2>/dev/null`
  prints nothing.
- Search 4 prints nothing.
- Global skills were removed only with the developer's approval. If they were kept, the report says so.
- The developer was asked to start a new session, and guide 1 runs in that session.
