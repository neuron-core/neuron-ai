<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function file_exists;
use function is_file;
use function unlink;

/**
 * Delete a file from the filesystem.
 */
class DeleteFileTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'delete_file',
            description: 'Delete a file from the filesystem. This action is irreversible.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'file_path',
                type: PropertyType::STRING,
                description: 'Absolute or relative path to the file to delete.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $file_path): array
    {
        if (!file_exists($file_path)) {
            return [
                'status' => 'error',
                'operation' => 'delete_file',
                'file_path' => $file_path,
                'message' => "File '{$file_path}' does not exist.",
            ];
        }

        if (!is_file($file_path)) {
            return [
                'status' => 'error',
                'operation' => 'delete_file',
                'file_path' => $file_path,
                'message' => "'{$file_path}' is not a file. Directories cannot be deleted with this tool.",
            ];
        }

        if (!unlink($file_path)) {
            return [
                'status' => 'error',
                'operation' => 'delete_file',
                'file_path' => $file_path,
                'message' => "Failed to delete file '{$file_path}'.",
            ];
        }

        return [
            'status' => 'success',
            'operation' => 'delete_file',
            'file_path' => $file_path,
            'message' => "File '{$file_path}' deleted successfully.",
        ];
    }
}
