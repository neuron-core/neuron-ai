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

        // Check if namespace matches PSR-4 configuration
        if (!$this->namespaceBelongsToPsr4($namespace)) {
            $this->printWarning("Namespace '{$namespace}' doesn't match any PSR-4 configuration in composer.json");
            $this->printAvailableNamespaces();
        }

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

        $namespace = $parts === [] ? $this->getDefaultNamespace() : \implode('\\', $parts);

        return [$namespace, $className];
    }

    private function getDefaultNamespace(): string
    {
        $psr4Config = $this->loadPsr4Config();

        if ($psr4Config === []) {
            return 'App'; // Fallback if no PSR-4 config found
        }

        // Get the first PSR-4 namespace and remove trailing backslash
        $firstNamespace = \array_key_first($psr4Config);
        return \rtrim($firstNamespace, '\\');
    }

    private function getFilePath(string $namespace, string $className): string
    {
        $psr4Config = $this->loadPsr4Config();

        foreach ($psr4Config as $namespacePrefix => $directory) {
            if (\str_starts_with($namespace . '\\', $namespacePrefix)) {
                // Remove the namespace prefix and convert to file path
                $relativePath = \substr($namespace, \strlen(\rtrim($namespacePrefix, '\\')));
                $relativePath = \str_replace('\\', '/', \ltrim($relativePath, '\\'));

                $basePath = \getcwd() . '/' . \rtrim($directory, '/');

                return $basePath . ($relativePath !== '' && $relativePath !== '0' ? '/' . $relativePath : '') . '/' . $className . '.php';
            }
        }

        // Fallback: create in current directory if no PSR-4 match found
        $namespacePath = \str_replace('\\', '/', $namespace);
        return \getcwd() . '/' . $namespacePath . '/' . $className . '.php';
    }

    /**
     * @return array<string, string>
     */
    private function loadPsr4Config(): array
    {
        $composerPath = \getcwd() . '/composer.json';

        if (!\file_exists($composerPath)) {
            return [];
        }

        $composerContent = \file_get_contents($composerPath);
        if ($composerContent === false) {
            return [];
        }

        $composerData = \json_decode($composerContent, true);
        if (!\is_array($composerData) || !isset($composerData['autoload']['psr-4'])) {
            return [];
        }

        return $composerData['autoload']['psr-4'];
    }

    private function namespaceBelongsToPsr4(string $namespace): bool
    {
        $psr4Config = $this->loadPsr4Config();

        foreach (\array_keys($psr4Config) as $namespacePrefix) {
            if (\str_starts_with($namespace . '\\', $namespacePrefix)) {
                return true;
            }
        }

        return false;
    }

    private function printAvailableNamespaces(): void
    {
        $psr4Config = $this->loadPsr4Config();

        if ($psr4Config === []) {
            return;
        }

        echo "Available PSR-4 namespaces:" . \PHP_EOL;
        foreach ($psr4Config as $namespace => $directory) {
            echo "  {$namespace} -> {$directory}" . \PHP_EOL;
        }
        echo \PHP_EOL;
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

If no namespace is provided, the default PSR-4 namespace from composer.json will be used.

USAGE;

        echo $usage . \PHP_EOL;
    }

    protected function printError(string $message): void
    {
        \fwrite(\STDERR, "Error: {$message}" . \PHP_EOL);
    }

    protected function printWarning(string $message): void
    {
        echo "Warning: {$message}" . \PHP_EOL;
    }

    protected function printSuccess(string $message): void
    {
        echo "Success: {$message}" . \PHP_EOL;
    }
}
