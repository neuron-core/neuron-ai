<?php

declare(strict_types=1);

namespace NeuronAI\Console;

use Closure;
use NeuronAI\Console\Evaluation\EvaluationCommand;
use NeuronAI\Console\Make\MakeCommand;
use Throwable;

use function array_shift;
use function str_pad;

use const PHP_EOL;

class NeuronCli extends Command
{
    /**
     * @return array<string, array{string, Closure(): Command}>
     */
    public function commands(): array
    {
        return [
            'evaluation' => ['Run AI evaluation tests on a directory of evaluators', fn (): Command => new EvaluationCommand()],
            'make:agent' => ['Create a new Agent class', fn (): Command => new MakeCommand('make:agent', 'Agent', 'agent.stub')],
            'make:middleware' => ['Create a new Workflow Middleware class', fn (): Command => new MakeCommand('make:middleware', 'Middleware', 'middleware.stub')],
            'make:node' => ['Create a new Node class', fn (): Command => new MakeCommand('make:node', 'Node', 'node.stub')],
            'make:tool' => ['Create a new Tool class', fn (): Command => new MakeCommand('make:tool', 'Tool', 'tool.stub')],
            'make:rag' => ['Create a new RAG class', fn (): Command => new MakeCommand('make:rag', 'RAG', 'rag.stub')],
            'make:workflow' => ['Create a new Workflow class', fn (): Command => new MakeCommand('make:workflow', 'Workflow', 'workflow.stub')],
            'make:event' => ['Create a new Event class', fn (): Command => new MakeCommand('make:event', 'Event', 'event.stub')],
            'make:evaluators' => ['Create a new Evaluator class', fn (): Command => new MakeCommand('make:evaluators', 'Evaluator', 'evaluator.stub')],
        ];
    }

    /**
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        // Skip script name
        array_shift($args);

        if ($args === []) {
            $this->printUsage();
            return 1;
        }

        $commandName = array_shift($args);

        if ($commandName === '--help' || $commandName === '-h') {
            $this->printUsage();
            return 0;
        }

        $commands = $this->commands();

        if (!isset($commands[$commandName])) {
            $this->printError("Unknown command: {$commandName}");
            $this->printUsage();
            return 1;
        }

        [, $factory] = $commands[$commandName];

        try {
            // Prepare arguments for the sub-command (restore script name simulation)
            $command = $factory();
            // Inherit the error stream so redirected output propagates to sub-commands
            $command->setErrorStream($this->errorStream);
            return $command->run(['neuron', ...$args]);
        } catch (Throwable $e) {
            $this->printError("Error executing command '{$commandName}': " . $e->getMessage());
            return 1;
        }
    }

    protected function printUsage(): void
    {
        echo 'Neuron AI CLI Tool' . PHP_EOL . PHP_EOL;
        echo 'Usage: neuron <command> [options]' . PHP_EOL . PHP_EOL;

        echo 'Available Commands:' . PHP_EOL;
        foreach ($this->commands() as $name => [$description]) {
            echo '  ' . str_pad($name, 16) . $description . PHP_EOL;
        }
        echo PHP_EOL;

        $usage = <<<'USAGE'
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

        echo $usage . PHP_EOL;
    }
}
