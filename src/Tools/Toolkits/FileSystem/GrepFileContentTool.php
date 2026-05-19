<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function file_get_contents;
use function is_file;
use function is_readable;
use function preg_match_all;
use function count;
use function explode;
use function mb_strlen;
use function mb_substr;
use function preg_last_error_msg;

use const PREG_OFFSET_CAPTURE;

class GrepFileContentTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'grep_file_content',
            description: 'Search for a regex pattern in a file.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'file_path',
                type: PropertyType::STRING,
                description: 'Path to the file to search.',
            ),
            ToolProperty::make(
                name: 'pattern',
                type: PropertyType::STRING,
                description: 'Regular expression pattern to search for.',
            ),
        ];
    }

    public function __invoke(string $file_path, string $pattern): string
    {
        if (!is_file($file_path)) {
            return "Error: File '{$file_path}' does not exist.";
        }

        if (!is_readable($file_path)) {
            return "Error: File '{$file_path}' is not readable.";
        }

        $content = file_get_contents($file_path);
        if ($content === false) {
            return "Error: Unable to read file '{$file_path}'.";
        }

        $matches = [];
        $result = @preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        if ($result === false) {
            $error = preg_last_error_msg();
            return "Error: Invalid regex pattern '{$pattern}'. {$error}";
        }

        if ($result === 0) {
            return "No matches found for pattern '{$pattern}' in file '{$file_path}'.";
        }

        $output = "Found {$result} match(es) for pattern '{$pattern}' in file '{$file_path}':\n\n";

        $lines = explode("\n", $content);
        $lineCount = count($lines);

        foreach ($matches[0] as $index => $match) {
            $matchText = $match[0];
            $offset = $match[1];

            $linesBefore = 0;
            $lineIndex = 0;
            for ($i = 0; $i < $lineCount; $i++) {
                $lineLength = mb_strlen($lines[$i]) + 1; // +1 for newline
                if ($linesBefore + $lineLength > $offset) {
                    $lineIndex = $i + 1;
                    break;
                }
                $linesBefore += $lineLength;
            }

            $truncatedMatch = mb_strlen($matchText) > 100 ? mb_substr($matchText, 0, 97) . '...' : $matchText;

            $output .= "  Match " . ($index + 1) . " (line {$lineIndex}): {$truncatedMatch}\n";
        }

        return $output;
    }
}
