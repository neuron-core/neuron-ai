# Console Module

CLI entry point (`php vendor/bin/neuron <command>`) for code generation and for running evaluations.

## Design

- `NeuronCli::commands()` is the single registry: command name => [description, factory closure]. The help output is generated from it, so there is nowhere else to register a command.
- `Command` is the abstract base: `run(array $args): int` plus print helpers (errors go to STDERR).
- `MakeCommand` is one concrete class for every `make:*` command, configured with (command name, resource type, stub file). Adding a generator means adding a stub in `Make/Stubs/` and one registry entry, never a new command class.
- `EvaluationCommand` is a thin CLI over `src/Evaluation/`: flag parsing (`--verbose`, `--concurrency`, `--cache`, `--fresh`) lives here, the semantics live in the Evaluation module.
