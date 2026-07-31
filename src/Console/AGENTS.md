# Console Module

CLI commands for Neuron framework.

## Structure

- `Command.php` - Abstract base: `run(array $args): int` plus print helpers (errors go to STDERR).
- `NeuronCli.php` - Entry point. `commands()` is the single registry: command name => [description, factory closure]. The usage/help command list is generated from it.
- `Make/MakeCommand.php` - One concrete class for all `make:*` commands, configured with (command name, resource type, stub file). To add a make command: add a stub in `Make/Stubs/` and a registry entry in `NeuronCli::commands()`.

Run: `php vendor/bin/neuron <command>`

## Make Commands (`Make/`)

Code generation using stubs:

| Command | Creates |
|---------|---------|
| `make:agent` | Agent class |
| `make:tool` | Tool class |
| `make:node` | Workflow Node |
| `make:rag` | RAG class |
| `make:workflow` | Workflow class |
| `make:middleware` | Middleware class |
| `make:event` | Event class |
| `make:evaluators` | Evaluator class |

```bash
php vendor/bin/neuron make:agent MyAgent
php vendor/bin/neuron make:tool MyTool
```

## Stubs (`Make/Stubs/`)

Templates for code generation. Customize by publishing to application.

## Evaluation (`Evaluation/`)

`EvaluationCommand.php` - Run AI evaluations:

```bash
php vendor/bin/neuron evaluation path/to/evaluators
php vendor/bin/neuron evaluation --verbose path/to/evaluators
```

**See**: `src/Evaluation/AGENTS.md` for evaluation framework details.

## Dependencies

- `Evaluation` module for evaluation command
- File system access for make commands
