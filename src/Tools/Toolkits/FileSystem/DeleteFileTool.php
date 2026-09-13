<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

use function file_exists;
use function is_file;
use function unlink;

/**
 * Delete a file from the filesystem.
 */
class DeleteFileTool extends FileSystemTool
{
    protected string $name = 'delete_file';
    protected ?string $description = 'Delete a file from the filesystem. This action is irreversible.';

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

    public function __invoke(string $file_path): array|ToolOutput
    {
        $path = $this->resolve($file_path);
        if ($path instanceof ToolOutput) {
            return $path;
        }

        if (!file_exists($path)) {
            return ToolOutput::error("File '{$file_path}' does not exist.");
        }

        if (!is_file($path)) {
            return ToolOutput::error("'{$file_path}' is not a file. Directories cannot be deleted with this tool.");
        }

        if (!unlink($path)) {
            return ToolOutput::error("Failed to delete file '{$file_path}'.");
        }

        return [
            'status' => 'success',
            'operation' => 'delete_file',
            'file_path' => $file_path,
            'message' => "File '{$file_path}' deleted successfully.",
        ];
    }
}
