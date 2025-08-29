<?php

declare(strict_types=1);

namespace NeuronAI\Console;

use NeuronAI\Evaluation\Console\EvaluationCommand;

class NeuronCommand
{
    private const AVAILABLE_COMMANDS = [
        'evaluation' => EvaluationCommand::class,
    ];

    /**
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        // Skip script name
        array_shift($args);

        if (empty($args)) {
            $this->printUsage();
            return 1;
        }

        $commandName = array_shift($args);

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

Options:
  --help, -h   Show this help message

Examples:
  neuron evaluation --path=/path/to/evaluators
  neuron evaluation /path/to/evaluators --verbose
  neuron --help

For command-specific help, use:
  neuron <command> --help

USAGE;

        echo $usage . PHP_EOL;
    }

    private function printError(string $message): void
    {
        fwrite(STDERR, "Error: {$message}" . PHP_EOL);
    }
}