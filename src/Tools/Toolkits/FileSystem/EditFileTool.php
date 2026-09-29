<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_readable;
use function is_writable;
use function str_replace;
use function substr_count;

/**
 * Edit a file by applying a search-and-replace operation.
 */
class EditFileTool extends FileSystemTool
{
    protected string $name = 'edit_file';
    protected ?string $description = 'Edit a file by replacing an exact string or block of text with new content. The search string must match exactly once (including whitespace and indentation). Use write_file if you need to replace the entire file.';

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
                description: 'The exact text to search for in the file. Must match the file content precisely and appear only once.',
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

    public function __invoke(string $file_path, string $search, string $replace): array|ToolOutput
    {
        if ($search === '') {
            return ToolOutput::error('The search string cannot be empty. Use write_file to replace the entire file.');
        }

        $path = $this->resolve($file_path);
        if ($path instanceof ToolOutput) {
            return $path;
        }

        if (!is_file($path)) {
            return ToolOutput::error("File '{$file_path}' does not exist.");
        }

        if (!is_readable($path)) {
            return ToolOutput::error("File '{$file_path}' is not readable.");
        }

        $current = file_get_contents($path);
        if ($current === false) {
            return ToolOutput::error("Failed to read file '{$file_path}'.");
        }

        $occurrences = substr_count($current, $search);

        if ($occurrences === 0) {
            return ToolOutput::error("Search string not found in '{$file_path}'. Ensure the text matches exactly.");
        }

        if ($occurrences > 1) {
            return ToolOutput::error("The search string appears {$occurrences} times in '{$file_path}'. Include more surrounding lines so it matches exactly once.");
        }

        if (!is_writable($path)) {
            return ToolOutput::error("File '{$file_path}' is not writable.");
        }

        $updated = str_replace($search, $replace, $current);
        $result = file_put_contents($path, $updated);

        if ($result === false) {
            return ToolOutput::error("Failed to write changes to '{$file_path}'.");
        }

        return [
            'status' => 'success',
            'operation' => 'edit_file',
            'file_path' => $file_path,
            'message' => "File '{$file_path}' edited successfully.",
        ];
    }
}
