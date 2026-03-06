<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_readable;
use function is_writable;
use function str_contains;
use function str_replace;

/**
 * Edit a file by applying a search-and-replace operation.
 */
class EditFileTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'edit_file',
            description: 'Edit a file by replacing an exact string or block of text with new content. The search string must match exactly (including whitespace and indentation). Use write_file if you need to replace the entire file.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'file_path',
                type: PropertyType::STRING,
                description: 'Absolute or relative path to the file to edit.',
                required: true,
            ),
            ToolProperty::make(
                name: 'search',
                type: PropertyType::STRING,
                description: 'The exact text to search for in the file. Must match the file content precisely.',
                required: true,
            ),
            ToolProperty::make(
                name: 'replace',
                type: PropertyType::STRING,
                description: 'The text to replace the matched content with.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $file_path, string $search, string $replace): array
    {
        if (!is_file($file_path)) {
            return [
                'status' => 'error',
                'operation' => 'edit_file',
                'file_path' => $file_path,
                'message' => "File '{$file_path}' does not exist.",
            ];
        }

        if (!is_readable($file_path)) {
            return [
                'status' => 'error',
                'operation' => 'edit_file',
                'file_path' => $file_path,
                'message' => "File '{$file_path}' is not readable.",
            ];
        }

        $current = file_get_contents($file_path);
        if ($current === false) {
            return [
                'status' => 'error',
                'operation' => 'edit_file',
                'file_path' => $file_path,
                'message' => "Failed to read file '{$file_path}'.",
            ];
        }

        if (!str_contains($current, $search)) {
            return [
                'status' => 'error',
                'operation' => 'edit_file',
                'file_path' => $file_path,
                'message' => "Search string not found in '{$file_path}'. Ensure the text matches exactly.",
            ];
        }

        if (!is_writable($file_path)) {
            return [
                'status' => 'error',
                'operation' => 'edit_file',
                'file_path' => $file_path,
                'message' => "File '{$file_path}' is not writable.",
            ];
        }

        $updated = str_replace($search, $replace, $current);
        $result = file_put_contents($file_path, $updated);

        if ($result === false) {
            return [
                'status' => 'error',
                'operation' => 'edit_file',
                'file_path' => $file_path,
                'message' => "Failed to write changes to '{$file_path}'.",
            ];
        }

        return [
            'status' => 'success',
            'operation' => 'edit_file',
            'file_path' => $file_path,
            'message' => "File '{$file_path}' edited successfully.",
        ];
    }
}
