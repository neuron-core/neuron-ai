<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function dirname;
use function file_put_contents;
use function is_dir;
use function is_writable;
use function mkdir;
use function strlen;

/**
 * Write (overwrite or create) a file with the given content.
 */
class WriteFileTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'write_file',
            description: 'Write content to a file, creating it if it does not exist or overwriting it if it does. Use for applying code changes to existing files or creating new ones.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'file_path',
                type: PropertyType::STRING,
                description: 'Absolute or relative path to the file to write.',
                required: true,
            ),
            ToolProperty::make(
                name: 'content',
                type: PropertyType::STRING,
                description: 'The full content to write to the file.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $file_path, string $content): array
    {
        $dir = dirname($file_path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [
                'status' => 'error',
                'operation' => 'write_file',
                'file_path' => $file_path,
                'message' => "Directory '{$dir}' could not be created.",
            ];
        }

        if (!is_writable($dir)) {
            return [
                'status' => 'error',
                'operation' => 'write_file',
                'file_path' => $file_path,
                'message' => "Directory '{$dir}' is not writable.",
            ];
        }

        $result = file_put_contents($file_path, $content);

        if ($result === false) {
            return [
                'status' => 'error',
                'operation' => 'write_file',
                'file_path' => $file_path,
                'message' => "Failed to write file '{$file_path}'.",
            ];
        }

        return [
            'status' => 'success',
            'operation' => 'write_file',
            'file_path' => $file_path,
            'bytes_written' => strlen($content),
            'message' => "File '{$file_path}' written successfully.",
        ];
    }
}
