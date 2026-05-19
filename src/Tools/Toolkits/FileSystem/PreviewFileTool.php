<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\RAG\DataLoader\HtmlReader;
use NeuronAI\RAG\DataLoader\PdfReader;
use Exception;

use function is_file;
use function is_readable;
use function mb_strlen;
use function mb_substr;
use function pathinfo;
use function strtolower;
use function file_get_contents;

use const PATHINFO_EXTENSION;

class PreviewFileTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'preview_file',
            description: 'Get a quick preview of a document file. Reads only the first portion of the document content for initial relevance assessment before doing a full parse.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'file_path',
                type: PropertyType::STRING,
                description: 'Path to the document file.',
            ),
            ToolProperty::make(
                name: 'max_chars',
                type: PropertyType::INTEGER,
                description: 'Maximum characters to return (default: 3000, ~2-3 pages).',
                required: false,
            ),
        ];
    }

    public function __invoke(string $file_path, ?int $max_chars = null): string
    {
        if (!is_file($file_path)) {
            return "Error: File '{$file_path}' does not exist.";
        }

        if (!is_readable($file_path)) {
            return "Error: File '{$file_path}' is not readable.";
        }

        $max_chars ??= 3000;

        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => $this->previewPdf($file_path, $max_chars),
            'htm', 'html' => $this->previewHtml($file_path, $max_chars),
            default => $this->previewText($file_path, $max_chars),
        };
    }

    private function previewText(string $file_path, int $max_chars): string
    {
        $content = file_get_contents($file_path);
        if ($content === false) {
            return "Error: Unable to read file '{$file_path}'.";
        }

        $totalLength = mb_strlen($content);

        if ($totalLength <= $max_chars) {
            return $content . "\n\n[Full content shown: {$totalLength} characters]";
        }

        $preview = mb_substr($content, 0, $max_chars);
        return $preview . "\n\n[Preview shown: {$max_chars} of {$totalLength} characters - use parse_file for complete content]";
    }

    private function previewPdf(string $file_path, int $max_chars): string
    {
        try {
            $content = PdfReader::getText($file_path);
            $totalLength = mb_strlen($content);

            if ($totalLength <= $max_chars) {
                return $content . "\n\n[Full PDF content shown: {$totalLength} characters]";
            }

            $preview = mb_substr($content, 0, $max_chars);
            return $preview . "\n\n[Preview shown: {$max_chars} of {$totalLength} characters - use parse_file for complete content]";
        } catch (Exception $e) {
            return "Error: Unable to parse PDF file '{$file_path}'. {$e->getMessage()}";
        }
    }

    private function previewHtml(string $file_path, int $max_chars): string
    {
        try {
            $content = HtmlReader::getText($file_path);
            $totalLength = mb_strlen($content);

            if ($totalLength <= $max_chars) {
                return $content . "\n\n[Full HTML content shown: {$totalLength} characters]";
            }

            $preview = mb_substr($content, 0, $max_chars);
            return $preview . "\n\n[Preview shown: {$max_chars} of {$totalLength} characters - use parse_file for complete content]";
        } catch (Exception $e) {
            return "Error: Unable to parse HTML file '{$file_path}'. {$e->getMessage()}";
        }
    }
}
