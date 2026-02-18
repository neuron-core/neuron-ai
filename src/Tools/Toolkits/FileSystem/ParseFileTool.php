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
use function pathinfo;
use function strtolower;

use const PATHINFO_EXTENSION;

class ParseFileTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'parse_file',
            description: 'Parse and return the complete content of a document file. Use this after preview_file confirms the document is relevant, or when you need to find cross-references to other documents. Supported formats: PDF, HTML.',
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
        ];
    }

    public function __invoke(string $file_path): string
    {
        if (!is_file($file_path)) {
            return "Error: File '{$file_path}' does not exist.";
        }

        if (!is_readable($file_path)) {
            return "Error: File '{$file_path}' is not readable.";
        }

        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => $this->parsePdf($file_path),
            'htm', 'html' => $this->parseHtml($file_path),
            default => "Error: Unsupported file format '{$extension}'. Supported formats: PDF, HTML. If the file is already in plain text you can access its content directly with other tools like grep_file_content, preview_file, or read_file.",
        };
    }

    private function parsePdf(string $file_path): string
    {
        try {
            $content = PdfReader::getText($file_path);
            $length = mb_strlen($content);
            return $content . "\n\n[PDF parsed successfully: {$length} characters]";
        } catch (Exception $e) {
            return "Error: Unable to parse PDF file '{$file_path}'. {$e->getMessage()}";
        }
    }

    private function parseHtml(string $file_path): string
    {
        try {
            $content = HtmlReader::getText($file_path);
            $length = mb_strlen($content);
            return $content . "\n\n[HTML parsed successfully: {$length} characters]";
        } catch (Exception $e) {
            return "Error: Unable to parse HTML file '{$file_path}'. {$e->getMessage()}";
        }
    }
}
