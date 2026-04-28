# Console Module

CLI commands for Neuron framework.

## Main Command

`NeuronCli.php` - Entry point, registers all commands.

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
| `make:evaluators` | Evaluation directory |

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
