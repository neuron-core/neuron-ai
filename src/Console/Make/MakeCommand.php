<?php

declare(strict_types=1);

namespace NeuronAI\Console\Make;

abstract class MakeCommand
{
    public function __construct(protected string $resourceType)
    {
    }

    /**
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        $options = $this->parseArguments($args);

        if ($options['help']) {
            $this->printUsage();
            return 0;
        }

        if (empty($options['name'])) {
            $this->printError("Class name argument is required");
            $this->printUsage();
            return 1;
        }

        try {
            return $this->generateClass($options['name']);
        } catch (\Throwable $e) {
            $this->printError($e->getMessage());
            return 1;
        }
    }

    /**
     * @param array<string> $args
     * @return array{name: string, help: bool}
     */
    private function parseArguments(array $args): array
    {
        $options = [
            'name' => '',
            'help' => false,
        ];

        // Skip script name
        \array_shift($args);

        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;
            } elseif (!\str_starts_with($arg, '-') && empty($options['name'])) {
                $options['name'] = $arg;
            }
        }

        return $options;
    }

    private function generateClass(string $name): int
    {
        [$namespace, $className] = $this->parseNamespaceAndClass($name);
        $filePath = $this->getFilePath($namespace, $className);

        if (\file_exists($filePath)) {
            $this->printError("File already exists: {$filePath}");
            return 1;
        }

        $directory = \dirname($filePath);
        if (!\is_dir($directory) && !\mkdir($directory, 0755, true)) {
            $this->printError("Failed to create directory: {$directory}");
            return 1;
        }

        $content = $this->getStubContent($namespace, $className);

        if (\in_array(\file_put_contents($filePath, $content), [0, false], true)) {
            $this->printError("Failed to create file: {$filePath}");
            return 1;
        }

        $this->printSuccess("Created {$this->resourceType}: {$filePath}");
        return 0;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseNamespaceAndClass(string $name): array
    {
        $parts = \explode('\\', $name);
        $className = \array_pop($parts);

        $namespace = $parts === [] ? 'App\\Neuron' : \implode('\\', $parts);

        return [$namespace, $className];
    }

    private function getFilePath(string $namespace, string $className): string
    {
        // Convert namespace to file path (assuming PSR-4 autoloading from current directory)
        $namespacePath = \str_replace('\\', '/', $namespace);
        return \getcwd() . '/' . $namespacePath . '/' . $className . '.php';
    }

    abstract protected function getStubContent(string $namespace, string $className): string;

    protected function printUsage(): void
    {
        $usage = <<<USAGE
Create a new {$this->resourceType}

Usage: neuron make:{$this->resourceType} [namespace\\]ClassName

Arguments:
  name    The name of the {$this->resourceType} class (with optional namespace)

Options:
  --help, -h   Show this help message

Examples:
  neuron make:{$this->resourceType} MyClass
  neuron make:{$this->resourceType} MyApp\\Services\\MyClass

If no namespace is provided, the default namespace 'App\\Neuron' will be used.

USAGE;

        echo $usage . \PHP_EOL;
    }

    protected function printError(string $message): void
    {
        \fwrite(\STDERR, "Error: {$message}" . \PHP_EOL);
    }

    protected function printSuccess(string $message): void
    {
        echo "Success: {$message}" . \PHP_EOL;
    }
}
