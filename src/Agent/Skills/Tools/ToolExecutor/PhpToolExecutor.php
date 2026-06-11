<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills\Tools\ToolExecutor;

use NeuronAI\Agent\Skills\Tools\ToolDefinition;
use NeuronAI\Agent\Skills\Tools\ToolExecutorInterface;
use NeuronAI\Agent\Skills\Tools\ToolResult;

use function call_user_func_array;
use function class_exists;
use function is_string;

class PhpToolExecutor implements ToolExecutorInterface
{
    public function supports(string $type): bool
    {
        return $type === 'php';
    }

    public function execute(ToolDefinition $definition, array $inputs): ToolResult
    {
        $config = $definition->execution;
        $class = $config['class'] ?? null;
        $method = $config['method'] ?? '__invoke';

        if ($class === null || !is_string($class)) {
            return new ToolResult(
                exitCode: 1,
                error: 'PHP tool requires a "class" in execution config.',
            );
        }

        if (!class_exists($class)) {
            return new ToolResult(
                exitCode: 1,
                error: "Class '{$class}' not found.",
            );
        }

        try {
            $constructorArgs = $config['constructor_args'] ?? [];
            $instance = $constructorArgs !== []
                ? new $class(...$constructorArgs)
                : new $class();

            $result = call_user_func_array([$instance, $method], [$inputs]);

            return new ToolResult(
                exitCode: 0,
                output: is_string($result) ? $result : (string) json_encode($result),
            );
        } catch (\Throwable $e) {
            return new ToolResult(
                exitCode: 1,
                error: $e->getMessage(),
            );
        }
    }
}
