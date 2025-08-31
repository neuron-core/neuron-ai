<?php

declare(strict_types=1);

namespace NeuronAI\Console;

use NeuronAI\Console\Evaluation\EvaluationCommand;
use NeuronAI\Console\Make\MakeAgentCommand;
use NeuronAI\Console\Make\MakeNodeCommand;
use NeuronAI\Console\Make\MakeRagCommand;
use NeuronAI\Console\Make\MakeToolCommand;
use NeuronAI\Console\Make\MakeWorkflowCommand;

class NeuronCli
{
    private const AVAILABLE_COMMANDS = [
        'evaluation' => EvaluationCommand::class,
        'make:agent' => MakeAgentCommand::class,
        'make:node' => MakeNodeCommand::class,
        'make:tool' => MakeToolCommand::class,
        'make:rag' => MakeRagCommand::class,
        'make:workflow' => MakeWorkflowCommand::class,
    ];

    /**
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        // Skip script name
        \array_shift($args);

        if ($args === []) {
            $this->printUsage();
            return 1;
        }

        $commandName = \array_shift($args);

        if ($commandName === '--help' || $commandName === '-h') {
            $this->printUsage();
            return 0;
        }

        if (!isset(self::AVAILABLE_COMMANDS[$commandName])) {
            $this->printError("Unknown command: {$commandName}");
            $this->printUsage();
            return 1;
        }

        $commandClass = self::AVAILABLE_COMMANDS[$commandName];

        try {
            $command = new $commandClass();

            // Prepare arguments for the sub-command (restore script name simulation)
            $subCommandArgs = ['neuron', ...$args];

            return $command->run($subCommandArgs);
        } catch (\Throwable $e) {
            $this->printError("Error executing command '{$commandName}': " . $e->getMessage());
            return 1;
        }
    }

    private function printUsage(): void
    {
        $usage = <<<'USAGE'
Neuron AI CLI Tool

Usage: neuron <command> [options]

Available Commands:
  evaluation   Run AI evaluation tests on a directory of evaluators
  make:agent   Create a new Agent class
  make:node    Create a new Node class
  make:tool    Create a new Tool class
  make:rag     Create a new RAG class
  make:workflow Create a new Workflow class

Options:
  --help, -h   Show this help message

Examples:
  neuron evaluation --path=/path/to/evaluators
  neuron evaluation /path/to/evaluators --verbose
  neuron make:agent MyAgent
  neuron make:tool MyApp\Tools\MyTool
  neuron --help

For command-specific help, use:
  neuron <command> --help

USAGE;

        echo $usage . \PHP_EOL;
    }

    private function printError(string $message): void
    {
        \fwrite(\STDERR, "Error: {$message}" . \PHP_EOL);
    }
}
