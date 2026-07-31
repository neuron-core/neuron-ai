<?php

declare(strict_types=1);

namespace NeuronAI\Console\Make;

use NeuronAI\Console\Command;
use RuntimeException;
use Throwable;

use function array_key_first;
use function array_keys;
use function array_pop;
use function array_shift;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function implode;
use function is_array;
use function is_dir;
use function json_decode;
use function ltrim;
use function mkdir;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use const PHP_EOL;

class MakeCommand extends Command
{
    public function __construct(
        protected string $commandName,
        protected string $resourceType,
        protected string $stubFile,
    ) {
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
        } catch (Throwable $e) {
            $this->printError($e->getMessage());
            return 1;
        }
    }

    /**
     * @param array<string> $args
     * @return array{name: string, help: bool}
     */
    protected function parseArguments(array $args): array
    {
        $options = [
            'name' => '',
            'help' => false,
        ];

        // Skip script name
        array_shift($args);

        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;
            } elseif (empty($options['name']) && !str_starts_with($arg, '-')) {
                $options['name'] = $arg;
            }
        }

        return $options;
    }

    protected function generateClass(string $name): int
    {
        [$namespace, $className] = $this->parseNamespaceAndClass($name);

        // Check if namespace matches PSR-4 configuration
        if (!$this->namespaceBelongsToPsr4($namespace)) {
            $this->printWarning("Namespace '{$namespace}' doesn't match any PSR-4 configuration in composer.json");
            $this->printAvailableNamespaces();
        }

        $filePath = $this->getFilePath($namespace, $className);

        if (file_exists($filePath)) {
            $this->printError("File already exists: {$filePath}");
            return 1;
        }

        $directory = dirname($filePath);
        if (!is_dir($directory) && !mkdir($directory, 0o755, true)) {
            $this->printError("Failed to create directory: {$directory}");
            return 1;
        }

        if (file_put_contents($filePath, $this->getStubContent($namespace, $className)) === false) {
            $this->printError("Failed to create file: {$filePath}");
            return 1;
        }

        $this->printSuccess("Created {$this->resourceType}: {$filePath}");
        return 0;
    }

    protected function getStubContent(string $namespace, string $className): string
    {
        $stubPath = __DIR__ . '/Stubs/' . $this->stubFile;
        $stub = file_get_contents($stubPath);

        if ($stub === false) {
            throw new RuntimeException("Failed to read stub file: {$stubPath}");
        }

        return str_replace(
            ['[namespace]', '[classname]'],
            [$namespace, $className],
            $stub
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function parseNamespaceAndClass(string $name): array
    {
        $parts = explode('\\', $name);
        $className = array_pop($parts);

        $namespace = $parts === [] ? $this->getDefaultNamespace() : implode('\\', $parts);

        return [$namespace, $className];
    }

    protected function getDefaultNamespace(): string
    {
        $psr4Config = $this->loadPsr4Config();

        if ($psr4Config === []) {
            return 'App'; // Fallback if no PSR-4 config found
        }

        // Get the first PSR-4 namespace and remove trailing backslash
        $firstNamespace = array_key_first($psr4Config);
        return rtrim($firstNamespace, '\\');
    }

    protected function getFilePath(string $namespace, string $className): string
    {
        $psr4Config = $this->loadPsr4Config();

        foreach ($psr4Config as $namespacePrefix => $directory) {
            if (str_starts_with($namespace . '\\', $namespacePrefix)) {
                // Remove the namespace prefix and convert to file path
                $relativePath = substr($namespace, strlen(rtrim($namespacePrefix, '\\')));
                $relativePath = str_replace('\\', '/', ltrim($relativePath, '\\'));

                $basePath = getcwd() . '/' . rtrim($directory, '/');

                return $basePath . ($relativePath !== '' ? '/' . $relativePath : '') . '/' . $className . '.php';
            }
        }

        // Fallback: create in current directory if no PSR-4 match found
        $namespacePath = str_replace('\\', '/', $namespace);
        return getcwd() . '/' . $namespacePath . '/' . $className . '.php';
    }

    /**
     * @return array<string, string>
     */
    protected function loadPsr4Config(): array
    {
        $composerPath = getcwd() . '/composer.json';

        if (!file_exists($composerPath)) {
            return [];
        }

        $composerContent = file_get_contents($composerPath);
        if ($composerContent === false) {
            return [];
        }

        $composerData = json_decode($composerContent, true);
        if (!is_array($composerData) || !isset($composerData['autoload']['psr-4'])) {
            return [];
        }

        return $composerData['autoload']['psr-4'];
    }

    protected function namespaceBelongsToPsr4(string $namespace): bool
    {
        $psr4Config = $this->loadPsr4Config();

        foreach (array_keys($psr4Config) as $namespacePrefix) {
            if (str_starts_with($namespace . '\\', $namespacePrefix)) {
                return true;
            }
        }

        return false;
    }

    protected function printAvailableNamespaces(): void
    {
        $psr4Config = $this->loadPsr4Config();

        if ($psr4Config === []) {
            return;
        }

        echo "Available PSR-4 namespaces:" . PHP_EOL;
        foreach ($psr4Config as $namespace => $directory) {
            echo "  {$namespace} -> {$directory}" . PHP_EOL;
        }
        echo PHP_EOL;
    }

    protected function printUsage(): void
    {
        $usage = <<<USAGE
            Create a new {$this->resourceType}

            Usage: neuron {$this->commandName} [namespace\\]ClassName

            Arguments:
              name    The name of the {$this->resourceType} class (with optional namespace)

            Options:
              --help, -h   Show this help message

            Examples:
              neuron {$this->commandName} MyClass
              neuron {$this->commandName} MyApp\\Services\\MyClass

            If no namespace is provided, the default PSR-4 namespace from composer.json will be used.

            USAGE;

        echo $usage . PHP_EOL;
    }
}
