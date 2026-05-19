<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function is_dir;
use function natsort;
use function str_replace;
use function str_starts_with;
use function array_unique;
use function array_values;
use function count;
use function glob;
use function scandir;

use const DIRECTORY_SEPARATOR;

class GlobPathTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'glob_path',
            description: 'Find files matching a glob pattern in a directory.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'directory',
                type: PropertyType::STRING,
                description: 'Path to the directory to search in.',
            ),
            ToolProperty::make(
                name: 'pattern',
                type: PropertyType::STRING,
                description: 'Glob pattern to match (e.g., "*.txt", "**/*.pdf").',
            ),
        ];
    }

    public function __invoke(string $directory, string $pattern): string
    {
        if (!is_dir($directory)) {
            return "Error: Directory '{$directory}' does not exist.";
        }

        $useRecursive = str_starts_with($pattern, '**/');
        if ($useRecursive) {
            $pattern = str_replace('**/', '', $pattern);
        }

        $matches = $this->globRecursive($directory, $pattern, $useRecursive);

        if ($matches === []) {
            return "No matches found for pattern '{$pattern}' in directory '{$directory}'.";
        }

        natsort($matches);
        $matches = array_values($matches);

        $output = "Found " . count($matches) . " match(es) for pattern '{$pattern}' in directory '{$directory}':\n\n";
        foreach ($matches as $match) {
            $relativePath = str_replace($directory . DIRECTORY_SEPARATOR, '', $match);
            $output .= "  - {$relativePath}\n";
        }

        return $output;
    }

    private function globRecursive(string $directory, string $pattern, bool $recursive): array
    {
        $separator = DIRECTORY_SEPARATOR;

        $files = [];

        if ($recursive) {
            $items = scandir($directory);
            if ($items === false) {
                return [];
            }

            foreach ($items as $item) {
                if ($item === '.') {
                    continue;
                }
                if ($item === '..') {
                    continue;
                }
                $path = $directory . $separator . $item;

                if (is_dir($path)) {
                    $files = [...$files, ...$this->globRecursive($path, $pattern, $recursive)];
                }
            }
        }

        $globPattern = $directory . $separator . $pattern;
        $results = glob($globPattern);

        if ($results !== false) {
            $files = [...$files, ...$results];
        }

        return array_unique($files);
    }
}
