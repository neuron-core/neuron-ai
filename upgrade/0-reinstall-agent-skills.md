# Upgrade: Agent skills are reshaped and must be reinstalled

## Summary

The `skills/` folder of the package ships the skills coding agents (Claude Code, Codex, Cursor,
...) load to write Neuron code. 3.x shipped eight of them. 4.x replaces the whole set: the names
changed, the content describes the 4.x APIs the following guides introduce, three skills are new,
and some skills carry reference files next to `SKILL.md`.

| 3.x | 4.x |
|---|---|
| `neuron-agent-builder` | `neuron-agent` |
| `neuron-workflow-architect` | `neuron-workflow` |
| `neuron-tool-creator` | `neuron-tool` |
| `neuron-rag-specialist` | `neuron-rag` |
| `neuron-structured-output` | `neuron-structured-output` |
| `neuron-test-engineer` | `neuron-test` |
| `neuron-evaluation-engineer` | `neuron-evaluation` |
| `neuron-debugger` | `neuron-monitoring` |
| | `neuron-streaming`, `neuron-tool-approval`, `neuron-frontend-integration` (new) |

The skills installed in an application are a copy taken from the package when
`npx skills add` ran, placed under the agent directories of the project (`.agents/skills/`,
`.claude/skills/`, `.codex/skills/`, ...). `composer update` does not touch them, so after the
upgrade the coding agent keeps reading 3.x guidance. And since almost every name changed,
installing the 4.x set on top does not replace the old one: both sets coexist and the 3.x skills
keep triggering on the same keywords. Remove the old set first, then install the new one.

## What to Search For

From the application root, list the installed Neuron skills, whatever agent they were installed
for:

```
ls -d .[a-z]*/skills/neuron-*
```

Skills installed globally live in the same layout under the home directory:

```
ls -d ~/.[a-z]*/skills/neuron-*
```

If nothing is listed, the skills were never installed. Skip step 1; step 2 still applies, since
the 4.x skills are how the coding agent learns the APIs the following guides introduce.

## How to Reinstall

### Step 1: Remove the 3.x skills

Delete every directory the search listed, symlinks included:

```
rm -rf .agents/skills/neuron-* .claude/skills/neuron-* .codex/skills/neuron-*
```

`npx skills remove` followed by the skill names does the same through the CLI.

### Step 2: Install the 4.x skills

```
npx skills add ./vendor/neuron-core/neuron-ai/skills
```

The command asks which agents and which skills to install; add `-y` to skip the prompts when it
runs without a terminal. For a global installation add `-g`.

### Step 3: Start a new session

Coding agents discover skills when a session starts. A 3.x skill still loaded in the current
session describes APIs the following guides remove, so start a new session before opening
guide 1.

## Checklist

- The search lists only names that exist in `vendor/neuron-core/neuron-ai/skills`; none of the
  3.x names in the table above remains.
- The coding agent session was restarted after the install.
