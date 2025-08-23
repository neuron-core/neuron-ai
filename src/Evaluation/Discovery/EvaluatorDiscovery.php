<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Discovery;

use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;

class EvaluatorDiscovery
{
    /**
     * Discover evaluator classes in a given directory
     * @return array<string> Array of fully qualified class names
     */
    public function discover(string $path): array
    {
        if (!\is_dir($path)) {
            throw new \InvalidArgumentException("Directory not found: {$path}");
        }

        $evaluators = [];
        $files = $this->getPhpFiles($path);

        foreach ($files as $file) {
            $classes = $this->getClassesFromFile($file);

            foreach ($classes as $class) {
                if ($this->isEvaluatorClass($class)) {
                    $evaluators[] = $class;
                }
            }
        }

        return $evaluators;
    }

    /**
     * @return array<string>
     */
    private function getPhpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @return array<string>
     */
    private function getClassesFromFile(string $filePath): array
    {
        $content = \file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        $classes = [];
        $namespace = '';

        // Extract namespace
        if (\preg_match('/^namespace\s+([^;]+);/m', $content, $matches)) {
            $namespace = $matches[1];
        }

        // Extract class names
        if (\preg_match_all('/^class\s+(\w+)/m', $content, $matches)) {
            foreach ($matches[1] as $className) {
                $fullClassName = $namespace !== '' && $namespace !== '0' ? "{$namespace}\\{$className}" : $className;
                $classes[] = $fullClassName;
            }
        }

        return $classes;
    }

    private function isEvaluatorClass(string $className): bool
    {
        try {
            // Check if class exists (autoload it)
            if (!\class_exists($className)) {
                return false;
            }

            $reflection = new ReflectionClass($className);

            // Must implement EvaluatorInterface
            if (!$reflection->implementsInterface(EvaluatorInterface::class)) {
                return false;
            }

            // Must not be abstract or interface
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                return false;
            }
            // Must be instantiable
            return $reflection->isInstantiable();
        } catch (ReflectionException) {
            return false;
        }
    }
}
