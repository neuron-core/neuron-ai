# Console Module

CLI entry point (`php vendor/bin/neuron <command>`) for code generation and for running evaluations.

## Design

- `NeuronCli::commands()` is the single registry: command name => [description, factory closure]. The help output is generated from it, so there is nowhere else to register a command.
- Installed packages add commands through a `CommandProvider`, declared under `extra.neuron.command-provider` in their composer.json. `InstalledCommandProviders` finds them in `vendor/composer/installed.json` and `bin/neuron` passes them to `NeuronCli`. Built-in commands win name collisions, so a package can never change what an existing command does.
- `Command` is the abstract base: `run(array $args): int` plus print helpers (errors go to STDERR).
- `MakeCommand` is one concrete class for every `make:*` command, configured with (command name, resource type, stub file). Adding a generator means adding a stub in `Make/Stubs/` and one registry entry, never a new command class.
- `EvaluationCommand` is a thin CLI over `src/Evaluation/`: flag parsing (`--verbose`, `--concurrency`, `--cache`, `--fresh`) lives here, the semantics live in the Evaluation module. A framework console command wraps it, passing a resolver and a runner that replace the `resolver` and `runner` entries of `evaluation.php`.
