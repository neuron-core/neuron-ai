<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * @method static static make()
 */
class FileSystemToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return 'Explore and read files and directories. Start with describe_directory_content to understand structure, then use read_file, grep_file_content, or glob_path as needed. For documents (PDF, HTML), use preview_file before parse_file to confirm relevance.';
    }

    public function provide(): array
    {
        return [
            DescribeDirectoryContentTool::make(),
            ReadFileTool::make(),
            GrepFileContentTool::make(),
            GlobPathTool::make(),
            PreviewFileTool::make(),
            ParseFileTool::make(),
        ];
    }
}
