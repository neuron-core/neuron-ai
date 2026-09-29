<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

use InvalidArgumentException;
use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;

use function array_filter;
use function array_values;
use function class_exists;
use function file_get_contents;
use function is_dir;
use function sort;

use const SORT_STRING;
use const T_CLASS;
use const T_NAME_QUALIFIED;
use const T_NAMESPACE;
use const T_STRING;

class EvaluatorDiscovery
{
    /**
     * Discover evaluator classes in a given directory
     * @return array<string> Array of fully qualified class names
     */
    public function discover(string $path): array
    {
        if (!is_dir($path)) {
            throw new InvalidArgumentException("Directory not found: {$path}");
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

        // Filesystem iteration order differs between machines
        sort($evaluators, SORT_STRING);

        return $evaluators;
    }

    /**
     * @return array<string>
     */
    protected function getPhpFiles(string $directory): array
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
    protected function getClassesFromFile(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        // PHP's own tokenizer: modifiers, attributes and formatting before the
        // class keyword don't matter, and Foo::class or new class {} name nothing
        $tokens = array_values(array_filter(
            PhpToken::tokenize($content),
            static fn (PhpToken $token): bool => !$token->isIgnorable()
        ));

        $classes = [];
        $namespace = '';

        foreach ($tokens as $position => $token) {
            $next = $tokens[$position + 1] ?? null;

            if ($token->is(T_NAMESPACE)) {
                // A braced global block (namespace { ... }) has no name
                $namespace = $next?->is([T_STRING, T_NAME_QUALIFIED]) === true ? $next->text : '';
            } elseif ($token->is(T_CLASS) && $next?->is(T_STRING) === true) {
                $classes[] = $namespace === '' ? $next->text : "{$namespace}\\{$next->text}";
            }
        }

        return $classes;
    }

    protected function isEvaluatorClass(string $className): bool
    {
        try {
            // Check if class exists (autoload it)
            if (!class_exists($className)) {
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
