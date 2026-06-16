<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills;

use NeuronAI\Exceptions\AgentException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;

use function array_map;
use function escapeshellarg;
use function explode;
use function fclose;
use function file_exists;
use function implode;
use function in_array;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function proc_close;
use function proc_open;
use function rtrim;
use function str_replace;
use function stream_get_contents;
use function strtolower;
use function trim;

class ScriptToolBuilder
{
    /**
     * Build a Tool from the SKILL.md body sections.
     * Returns null if the body doesn't contain the required ## Input and ## Script sections.
     *
     * @throws AgentException If the script file is not found or template references undeclared parameters.
     */
    public static function build(
        string $directory,
        string $skillName,
        string $skillDescription,
        string $body,
    ): ?ToolInterface {
        $inputBlock = self::extractSection($body, 'Input');
        $scriptBlock = self::extractSection($body, 'Script');

        if ($inputBlock === null || $scriptBlock === null) {
            return null;
        }

        $parameters = self::parseInputSection($inputBlock);
        $commandTemplate = self::parseScriptSection($scriptBlock);

        if ($commandTemplate === null) {
            return null;
        }

        self::resolveScriptFile($directory, $commandTemplate);
        self::validatePlaceholders($commandTemplate, $parameters);

        $toolName = str_replace('-', '_', $skillName);

        // Merge ## Output into the tool description so the LLM knows what to expect
        $outputBlock = self::extractSection($body, 'Output');
        $description = self::buildToolDescription($skillDescription, $outputBlock);

        $tool = Tool::make($toolName, $description);

        foreach ($parameters as $param) {
            $tool->addProperty(ToolProperty::make(
                name: $param['name'],
                type: $param['type'],
                description: $param['description'],
                required: $param['required'],
                enum: $param['enum'],
            ));
        }

        $tool->setCallable(
            self::createExecutableCallable($directory, $commandTemplate, $parameters)
        );

        return $tool;
    }

    private static function extractSection(string $body, string $sectionName): ?string
    {
        if (preg_match("/^## {$sectionName}\s*\n(.*?)(?=^## |\z)/ms", $body, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * @return array<int, array{name: string, type: PropertyType, required: bool, enum: array<int, string>, default: ?string, description: string}>
     */
    private static function parseInputSection(string $inputBlock): array
    {
        $parameters = [];
        $lines = explode("\n", $inputBlock);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || !preg_match('/^-\s+(\w+):\s*(.+)$/', $line, $matches)) {
                continue;
            }

            $name = $matches[1];
            $rest = trim($matches[2]);

            $default = null;
            if (preg_match('/\(default\s+(.+?)\)/', $rest, $defaultMatch)) {
                $default = $defaultMatch[1];
                $rest = trim((string) preg_replace('/\s*\(default\s+.+?\)/', '', $rest));
            }

            $enum = [];
            if (preg_match('/\b(\w+)\s*\|\s*(\w+(?:\s*\|\s*\w+)*)\b/', $rest, $enumMatch)) {
                $enum = array_map('trim', explode('|', $enumMatch[0]));
                $rest = trim((string) preg_replace('/\s*' . preg_quote($enumMatch[0], '/') . '\s*/', '', $rest));
            }

            // Whatever remains after stripping default and enum is the type token
            $type = self::mapType($rest);
            $required = ($default === null);
            $description = self::buildPropertyDescription($name, $enum, $default);

            $parameters[] = [
                'name' => $name,
                'type' => $type,
                'required' => $required,
                'enum' => $enum,
                'default' => $default,
                'description' => $description,
            ];
        }

        return $parameters;
    }

    private static function mapType(string $typeToken): PropertyType
    {
        return match (strtolower(trim($typeToken))) {
            'integer', 'int' => PropertyType::INTEGER,
            'number', 'float' => PropertyType::NUMBER,
            'boolean', 'bool' => PropertyType::BOOLEAN,
            'array' => PropertyType::ARRAY,
            default => PropertyType::STRING,
        };
    }

    private static function parseScriptSection(string $scriptBlock): ?string
    {
        $lines = explode("\n", $scriptBlock);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }

        return null;
    }

    private static function resolveScriptFile(string $directory, string $commandTemplate): void
    {
        $tokens = explode(' ', $commandTemplate);
        $scriptFile = null;

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (preg_match('/\.\w+$/', $token) && !str_contains($token, '{{')) {
                $scriptFile = $token;
                break;
            }
        }

        if ($scriptFile === null) {
            return;
        }

        $fullPath = rtrim($directory, '/\\') . '/' . $scriptFile;

        if (!file_exists($fullPath)) {
            throw new AgentException("Script file '{$scriptFile}' not found in directory '{$directory}'");
        }
    }

    /**
     * @param array<int, array{name: string, ...}> $parameters
     */
    private static function validatePlaceholders(string $commandTemplate, array $parameters): void
    {
        preg_match_all('/\{\{(\w+)\}\}/', $commandTemplate, $matches);

        $declaredNames = array_map(fn (array $p): string => $p['name'], $parameters);

        foreach ($matches[1] as $placeholder) {
            if (!in_array($placeholder, $declaredNames, true)) {
                throw new AgentException("Script template references '{{{$placeholder}}}' which is not declared in ## Input");
            }
        }
    }

    /**
     * @param array<int, array{name: string, type: PropertyType, required: bool, enum: array<int, string>, default: ?string}> $parameters
     */
    private static function createExecutableCallable(
        string $directory,
        string $commandTemplate,
        array $parameters,
    ): \Closure {
        $paramNames = array_map(fn (array $p): string => $p['name'], $parameters);

        return function (...$args) use ($directory, $commandTemplate, $parameters, $paramNames): array {
            // Tool::execute() spreads an associative array as named arguments,
            // so $args arrives as a positional array. Combine with param names.
            $named = count($paramNames) > 0 && count($args) > 0
                ? array_combine($paramNames, $args)
                : [];

            $command = $commandTemplate;

            foreach ($parameters as $param) {
                $value = $named[$param['name']] ?? $param['default'] ?? '';
                $command = str_replace('{{' . $param['name'] . '}}', escapeshellarg((string) $value), $command);
            }

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open($command, $descriptors, $pipes, $directory);

            if ($process === false) {
                return [
                    'status' => 'error',
                    'output' => 'Failed to start process.',
                    'exit_code' => 1,
                ];
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            $output = ($stdout !== false ? $stdout : '');
            if ($stderr !== false && $stderr !== '') {
                $output .= ($output !== '' ? "\n" : '') . $stderr;
            }

            return [
                'status' => $exitCode === 0 ? 'success' : 'error',
                'output' => $output,
                'exit_code' => $exitCode,
            ];
        };
    }

    /**
     * Build a semantic description for a tool parameter.
     */
    private static function buildPropertyDescription(string $name, array $enum, ?string $default): string
    {
        // Use the parameter name as a human-readable description
        $description = ucfirst(str_replace('_', ' ', $name));

        $suffix = '';
        if ($enum !== []) {
            $suffix = ' (allowed: ' . implode(', ', $enum) . ')';
        }
        if ($default !== null) {
            $suffix .= " (default: {$default})";
        }

        return $description . $suffix;
    }

    /**
     * Build the tool description by merging the skill description with ## Output info.
     */
    private static function buildToolDescription(string $skillDescription, ?string $outputBlock): string
    {
        if ($outputBlock === null || $outputBlock === '') {
            return $skillDescription;
        }

        // Convert output bullets into a compact summary
        $items = array_filter(array_map('trim', explode("\n", $outputBlock)));
        $items = array_map(fn (string $item): string => ltrim($item, '- '), $items);

        return $skillDescription . ' Returns: ' . implode(', ', $items) . '.';
    }
}
