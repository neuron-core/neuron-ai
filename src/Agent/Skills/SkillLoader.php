<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills;

use NeuronAI\Exceptions\AgentException;

use function array_values;
use function dirname;
use function glob;
use function is_dir;
use function rtrim;

/**
 * Discovers and loads MarkdownSkill instances from the filesystem.
 * Supports two modes: directory scanning (discover) and explicit paths (loadPaths).
 * Later entries override earlier ones with the same name.
 */
class SkillLoader
{
    /**
     * Load a single skill from a directory containing SKILL.md.
     */
    public static function load(string $directory): MarkdownSkill
    {
        return new MarkdownSkill($directory);
    }

    /**
     * Discover skills from parent directories containing skill subdirectories.
     * Each path is a parent directory whose subdirectories contain SKILL.md files.
     * When skill names collide across directories, later directories take precedence.
     *
     * @param string[] $paths Parent directories to scan.
     * @return MarkdownSkill[]
     */
    public static function discover(array $paths): array
    {
        $skillsByName = [];

        foreach ($paths as $path) {
            $path = rtrim($path, '/\\');

            if (!is_dir($path)) {
                throw new AgentException("Directory not found: {$path}");
            }

            foreach (glob($path.'/*/SKILL.md') as $skillFile) {
                $skill = new MarkdownSkill(dirname($skillFile));
                $skillsByName[$skill->name()] = $skill;
            }
        }

        return array_values($skillsByName);
    }

    /**
     * Load skills from individual skill directories, each containing a SKILL.md.
     * When skill names collide, later paths take precedence.
     *
     * @param string[] $paths Individual skill directories.
     * @return MarkdownSkill[]
     */
    public static function loadPaths(array $paths): array
    {
        $skillsByName = [];

        foreach ($paths as $path) {
            $path = rtrim($path, '/\\');

            if (!is_dir($path)) {
                throw new AgentException("Directory not found: {$path}");
            }

            $skill = new MarkdownSkill($path);
            $skillsByName[$skill->name()] = $skill;
        }

        return array_values($skillsByName);
    }
}
