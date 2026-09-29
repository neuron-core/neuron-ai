<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

use function array_filter;
use function array_pop;
use function array_search;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function glob;
use function implode;
use function is_dir;
use function is_string;
use function natsort;
use function realpath;
use function scandir;
use function str_replace;

use const DIRECTORY_SEPARATOR;
use const GLOB_ONLYDIR;

class GlobPathTool extends FileSystemTool
{
    protected string $name = 'glob_path';
    protected ?string $description = 'Find files matching a glob pattern in a directory.';

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'directory',
                type: PropertyType::STRING,
                description: 'Path to the directory to search in.',
            ),
            ToolProperty::make(
                name: 'pattern',
                type: PropertyType::STRING,
                description: 'Glob pattern to match (e.g., "*.php", "**/*.pdf", "**/*.md").',
            ),
        ];
    }

    public function __invoke(string $directory, string $pattern): string|ToolOutput
    {
        $root = $this->resolve($directory);
        if ($root instanceof ToolOutput) {
            return $root;
        }

        if (!is_dir($root)) {
            return ToolOutput::error("Directory '{$directory}' does not exist.");
        }

        // A pattern can spell `..` as `[.][.]` and the walk follows symlinked
        // directories, so the scope is enforced on the matches themselves.
        $matches = array_filter(
            array_unique($this->globstar($root, explode('/', $pattern))),
            fn (string $match): bool => is_string($this->resolve($match))
        );

        if ($matches === []) {
            return "No matches found for pattern '{$pattern}' in directory '{$directory}'.";
        }

        natsort($matches);
        $matches = array_values($matches);

        $output = "Found " . count($matches) . " match(es) for pattern '{$pattern}' in directory '{$directory}':\n\n";
        foreach ($matches as $match) {
            $relativePath = str_replace($root . DIRECTORY_SEPARATOR, '', $match);
            $output .= "  - {$relativePath}\n";
        }

        return $output;
    }

    /**
     * Matches the pattern segments with glob(), where a `**` segment stands
     * for the directory reached so far and every directory below it.
     *
     * @param string[] $segments
     * @return string[]
     */
    protected function globstar(string $directory, array $segments): array
    {
        $globstar = array_search('**', $segments, true);
        if ($globstar === false) {
            return glob($directory . DIRECTORY_SEPARATOR . implode('/', $segments)) ?: [];
        }

        $before = array_slice($segments, 0, $globstar);
        $after = array_slice($segments, $globstar + 1) ?: ['*'];

        $bases = $before === []
            ? [$directory]
            : (glob($directory . DIRECTORY_SEPARATOR . implode('/', $before), GLOB_ONLYDIR) ?: []);

        $matches = [];
        foreach ($bases as $base) {
            foreach ($this->tree($base) as $subdirectory) {
                $matches = [...$matches, ...$this->globstar($subdirectory, $after)];
            }
        }

        return $matches;
    }

    /**
     * The directory and every directory below it, following symlinks but
     * walking each real directory once, so a link back to an ancestor ends.
     *
     * @return string[]
     */
    protected function tree(string $root): array
    {
        $tree = [];
        $walked = [];
        $pending = [$root];

        while (($directory = array_pop($pending)) !== null) {
            $real = realpath($directory);
            if ($real === false || isset($walked[$real])) {
                continue;
            }

            $walked[$real] = true;
            $tree[] = $directory;

            foreach (scandir($directory) ?: [] as $item) {
                if ($item !== '.' && $item !== '..' && is_dir($directory . DIRECTORY_SEPARATOR . $item)) {
                    $pending[] = $directory . DIRECTORY_SEPARATOR . $item;
                }
            }
        }

        return $tree;
    }
}
