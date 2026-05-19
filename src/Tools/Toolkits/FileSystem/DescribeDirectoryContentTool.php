<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function is_dir;
use function scandir;
use function sort;
use function array_filter;
use function array_values;
use function count;

use const DIRECTORY_SEPARATOR;

class DescribeDirectoryContentTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'describe_directory_content',
            description: 'Describe the contents of a directory. Lists all files and subdirectories in the given directory path.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'directory',
                type: PropertyType::STRING,
                description: 'Path to the directory to describe.',
            ),
        ];
    }

    public function __invoke(string $directory): string
    {
        if (!is_dir($directory)) {
            return "Error: Directory '{$directory}' does not exist.";
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return "Error: Unable to read directory '{$directory}'.";
        }

        $entries = array_values(array_filter($entries, fn (string $entry): bool => $entry !== '.' && $entry !== '..'));

        if ($entries === []) {
            return "Directory '{$directory}' is empty.";
        }

        $directories = [];
        $files = [];

        foreach ($entries as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $directories[] = $entry;
            } else {
                $files[] = $entry;
            }
        }

        sort($directories);
        sort($files);

        $output = "Directory: {$directory}\n\n";

        if ($directories !== []) {
            $output .= "Directories (" . count($directories) . "):\n";
            foreach ($directories as $dir) {
                $output .= "  - {$dir}\n";
            }
        }

        if ($files !== []) {
            if ($directories !== []) {
                $output .= "\n";
            }
            $output .= "Files (" . count($files) . "):\n";
            foreach ($files as $file) {
                $output .= "  - {$file}\n";
            }
        }

        return $output;
    }
}
