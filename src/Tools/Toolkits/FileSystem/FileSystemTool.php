<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\FileSystem;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;

use function array_pop;
use function basename;
use function dirname;
use function file_exists;
use function implode;
use function is_dir;
use function is_link;
use function preg_match;
use function preg_quote;
use function preg_split;
use function realpath;
use function rtrim;
use function str_starts_with;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Base of the tools that touch the filesystem. An optional scope confines
 * them to one directory: relative paths resolve from its root rather than
 * from the process working directory, and every path is canonicalized
 * before use so that `..` segments and symlinks cannot reach outside it.
 */
abstract class FileSystemTool extends Tool
{
    /**
     * @param string|null $scope The directory the tool is confined to; null leaves it unrestricted.
     *
     * @throws ToolException
     */
    public function __construct(protected ?string $scope = null)
    {
        if ($scope === null) {
            return;
        }

        $root = realpath($scope);
        if ($root === false || !is_dir($root)) {
            throw new ToolException(static::class . " requires an existing directory as scope, '{$scope}' given.");
        }

        $this->scope = $root;
    }

    /**
     * The path the tool operates on: the input untouched without a scope,
     * otherwise its canonical form, or the refusal to hand back to the model
     * when it lies outside the scope.
     */
    protected function resolve(string $path): string|ToolOutput
    {
        if ($this->scope === null) {
            return $path;
        }

        $canonical = $this->canonicalize($this->isAbsolute($path) ? $path : $this->scope . DIRECTORY_SEPARATOR . $path);

        if ($canonical === null || !$this->contains($canonical)) {
            return ToolOutput::error("Access denied: '{$path}' is outside the working scope '{$this->scope}'.");
        }

        return $canonical;
    }

    protected function isAbsolute(string $path): bool
    {
        [, $rest] = $this->splitDrive($path);

        return $rest !== '' && ($rest[0] === '/' || $rest[0] === DIRECTORY_SEPARATOR);
    }

    /**
     * Resolves `.` and `..` lexically, then the existing part of the path
     * through realpath() so symlinks are followed; the trailing segments that
     * do not exist yet are appended literally. Null when the existing part
     * cannot be resolved, as for a dangling symlink.
     */
    protected function canonicalize(string $path): ?string
    {
        [$drive, $rest] = $this->splitDrive($path);
        $segments = [];

        foreach ($this->segments($rest) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        $existing = $drive . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        $missing = '';

        while (!file_exists($existing) && !is_link($existing)) {
            $parent = dirname($existing);
            if ($parent === $existing) {
                return null;
            }

            $missing = DIRECTORY_SEPARATOR . basename($existing) . $missing;
            $existing = $parent;
        }

        $real = realpath($existing);
        if ($real === false) {
            return null;
        }

        return $missing === '' ? $real : rtrim($real, DIRECTORY_SEPARATOR) . $missing;
    }

    protected function contains(string $path): bool
    {
        return $path === $this->scope
            || str_starts_with($path, rtrim($this->scope, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }

    /**
     * @return array{string, string} The Windows drive prefix, empty elsewhere, and the rest of the path.
     */
    protected function splitDrive(string $path): array
    {
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('~^[A-Za-z]:~', $path) === 1) {
            return [substr($path, 0, 2), substr($path, 2)];
        }

        return ['', $path];
    }

    /**
     * The path split on the platform separator and on the forward slash, which every platform accepts.
     *
     * @return string[]
     */
    protected function segments(string $path): array
    {
        return preg_split('~[/' . preg_quote(DIRECTORY_SEPARATOR, '~') . ']+~', $path) ?: [];
    }
}
