<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills;

use NeuronAI\Agent\Skills\Tools\ToolDefinition;
use NeuronAI\Agent\Skills\Tools\ToolExecutorRegistry;
use NeuronAI\Agent\Skills\Tools\ToolPolicy;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;

use function array_keys;
use function explode;
use function implode;
use function in_array;
use function preg_match_all;
use function str_replace;
use function trim;

class DeclarativeToolBuilder
{
    private const SUPPORTED_TYPES = ['shell', 'http', 'php', 'queue', 'mcp'];

    /**
     * Parse the ## Tools YAML section and build Tool objects.
     *
     * @return ToolInterface[]
     */
    public static function build(
        string $directory,
        string $skillName,
        string $skillDescription,
        string $toolsSection,
        ?ToolExecutorRegistry $registry = null,
    ): array {
        $definitions = static::parseToolsSection($toolsSection);
        $registry = $registry ?? new ToolExecutorRegistry();

        $tools = [];
        foreach ($definitions as $definition) {
            if (!$registry->hasExecutor($definition->type)) {
                throw new AgentException(
                    "Unsupported tool type '{$definition->type}' for tool '{$definition->name}' in skill '{$skillName}'. "
                    . 'Supported types: ' . implode(', ', self::SUPPORTED_TYPES)
                );
            }

            $tools[] = static::buildTool($definition, $directory, $registry);
        }

        return $tools;
    }

    /**
     * Parse the YAML-formatted ## Tools section into ToolDefinition objects.
     *
     * @return ToolDefinition[]
     */
    public static function parseToolsSection(string $section): array
    {
        $definitions = [];
        $current = null;

        $lines = explode("\n", $section);

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Start of a new tool definition (top-level list item with 'name:')
            if (preg_match('/^-\s+name:\s+(.+)$/', $trimmed, $matches)) {
                if ($current !== null) {
                    $definitions[] = static::buildDefinition($current);
                }
                $current = [
                    'name' => trim($matches[1]),
                    'type' => '',
                    'description' => '',
                    'input_schema' => [],
                    'execution' => [],
                    'output_schema' => null,
                    'policy' => null,
                ];
                continue;
            }

            if ($current === null) {
                continue;
            }

            // Parse key: value pairs
            if (preg_match('/^(\w+):\s*(.*)$/', $trimmed, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);

                // Section headers switch parsing context
                if ($key === 'input_schema') {
                    $current['parsing_schema'] = 'input';
                    $current['input_schema'] = [];
                } elseif ($key === 'execution') {
                    $current['parsing_schema'] = 'execution';
                    $current['execution'] = [];
                } elseif ($key === 'output_schema') {
                    $current['parsing_schema'] = 'output';
                    $current['output_schema'] = [];
                } elseif ($key === 'policy') {
                    $current['parsing_schema'] = 'policy';
                    $current['policy'] = [];
                } elseif ($key === 'type' || $key === 'description') {
                    // Top-level tool fields
                    $current[$key] = $value;
                    $current['parsing_schema'] = null;
                } elseif (isset($current['parsing_schema'])) {
                    // Nested key-value pair inside a schema section
                    $schema = $current['parsing_schema'];
                    if ($schema === 'policy') {
                        $current['policy'][$key] = static::parsePolicyValue($value);
                    } elseif ($schema === 'execution') {
                        $current['execution'][$key] = $value;
                    } elseif ($schema === 'input') {
                        $current['input_schema'][$key] = $value;
                    } elseif ($schema === 'output') {
                        $current['output_schema'][$key] = $value;
                    }
                }
                continue;
            }
        }

        // Don't forget the last tool
        if ($current !== null) {
            $definitions[] = static::buildDefinition($current);
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function buildDefinition(array $data): ToolDefinition
    {
        $policy = null;
        if (is_array($data['policy']) && $data['policy'] !== []) {
            $policy = new ToolPolicy(
                idempotent: (bool) ($data['policy']['idempotent'] ?? false),
                sideEffect: (bool) ($data['policy']['side_effect'] ?? true),
                maxCalls: (int) ($data['policy']['max_calls'] ?? 0),
                retryOnFailure: (bool) ($data['policy']['retry_on_failure'] ?? false),
            );
        }

        return new ToolDefinition(
            name: $data['name'],
            type: $data['type'],
            description: $data['description'],
            inputSchema: $data['input_schema'],
            execution: $data['execution'],
            outputSchema: is_array($data['output_schema']) && $data['output_schema'] !== []
                ? $data['output_schema'] : null,
            policy: $policy,
        );
    }

    public static function parsePolicyValue(string $value): bool|int|string
    {
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if (preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }
        return $value;
    }

    /**
     * Build a Tool object from a ToolDefinition with an executor-backed callable.
     */
    public static function buildTool(
        ToolDefinition $definition,
        string $directory,
        ToolExecutorRegistry $registry,
    ): ToolInterface {
        $toolName = str_replace('-', '_', $definition->name);

        $description = $definition->description;
        if ($definition->outputSchema !== null) {
            $description .= ' Returns: ' . implode(', ', array_keys($definition->outputSchema)) . '.';
        }

        $tool = Tool::make($toolName, $description);

        foreach ($definition->inputSchema as $paramName => $paramType) {
            $parsed = static::parseInputType((string) $paramType);
            $type = static::mapType($parsed['type']);
            $tool->addProperty(ToolProperty::make(
                name: $paramName,
                type: $type,
                description: $parsed['description'] ?? ucfirst(str_replace('_', ' ', $paramName)),
                required: true,
                enum: $parsed['enum'],
            ));
        }

        if ($definition->policy !== null && $definition->policy->maxCalls > 0) {
            $tool->setMaxRuns($definition->policy->maxCalls);
        }

        $executor = $registry->getExecutor($definition->type);

        // Clone the definition with working directory injected for shell tools
        $execution = $definition->execution;
        if ($definition->type === 'shell' && !isset($execution['working_directory'])) {
            $execution['working_directory'] = $directory;
        }
        $resolvedDefinition = new ToolDefinition(
            name: $definition->name,
            type: $definition->type,
            description: $definition->description,
            inputSchema: $definition->inputSchema,
            execution: $execution,
            outputSchema: $definition->outputSchema,
            policy: $definition->policy,
        );

        $tool->setCallable(function (...$args) use ($executor, $resolvedDefinition): array {
            $paramNames = array_keys($resolvedDefinition->inputSchema);
            $inputs = count($paramNames) > 0 && count($args) > 0
                ? array_combine($paramNames, $args)
                : [];

            $result = $executor->execute($resolvedDefinition, $inputs);
            return $result->toArray();
        });

        return $tool;
    }

    /**
     * Parse an input type declaration like "string [standard, express, economy] # Description text".
     *
     * @return array{type: string, enum: string[], description: ?string}
     */
    public static function parseInputType(string $typeToken): array
    {
        // Extract inline description after #
        $description = null;
        if (preg_match('/^(.+?)\s*#\s*(.+)$/', $typeToken, $commentMatch)) {
            $typeToken = trim($commentMatch[1]);
            $description = trim($commentMatch[2]);
        }

        if (preg_match('/^(\w+)\s*\[([^\]]+)\]$/', $typeToken, $matches)) {
            $values = array_map('trim', explode(',', $matches[2]));
            return ['type' => $matches[1], 'enum' => $values, 'description' => $description];
        }

        return ['type' => $typeToken, 'enum' => [], 'description' => $description];
    }

    public static function mapType(string $typeToken): PropertyType
    {
        return match (strtolower(trim($typeToken))) {
            'integer', 'int' => PropertyType::INTEGER,
            'number', 'float' => PropertyType::NUMBER,
            'boolean', 'bool' => PropertyType::BOOLEAN,
            'array' => PropertyType::ARRAY,
            'object' => PropertyType::STRING,
            default => PropertyType::STRING,
        };
    }
}
