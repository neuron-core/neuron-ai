<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * @method static static make(?string $scope = null)
 */
class FileSystemToolkit extends AbstractToolkit
{
    /**
     * @param string|null $scope The directory the file tools are confined to; null leaves them
     *                           unrestricted. The shell runs from it but is not confined by it.
     */
    public function __construct(protected ?string $scope = null)
    {
    }

    public function guidelines(): ?string
    {
        $guidelines = 'Explore and read files and directories. Use glob_path to discover files, then read_file or grep_file_content as needed. For documents (PDF, HTML), use parse_file.';

        if ($this->scope === null) {
            return $guidelines;
        }

        return $guidelines . " Every file operation is confined to '{$this->scope}': relative paths resolve from there and anything outside is refused. Prefer the file tools over bash; shell commands need a working directory inside it and must stay inside it too.";
    }

    public function provide(): array
    {
        return [
            ReadFileTool::make($this->scope),
            GrepFileContentTool::make($this->scope),
            GlobPathTool::make($this->scope),
            ParseFileTool::make($this->scope),
            WriteFileTool::make($this->scope),
            DeleteFileTool::make($this->scope),
            EditFileTool::make($this->scope),
            BashTool::make($this->scope),
        ];
    }
}
