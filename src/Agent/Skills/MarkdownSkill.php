<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills;

use NeuronAI\Agent\AgentInterface;
use NeuronAI\Exceptions\AgentException;

use function basename;
use function explode;
use function file_exists;
use function file_get_contents;
use function is_string;
use function preg_match;
use function rtrim;
use function trim;

/**
 * Parses a SKILL.md file into a SkillInterface implementation.
 *
 * SKILL.md format: YAML frontmatter (name, description, metadata) + markdown body.
 * Body sections: ## Trigger, ## Reasoning, ## Plan, ## Policy, ## Fallback, ## Tools.
 * - ## Tools are parsed into Tool objects via DeclarativeToolBuilder.
 * - ## Trigger is exposed via trigger() for Tier 1 disclosure.
 * - All other sections are included in instructions() for Tier 2 (post-activation).
 */
class MarkdownSkill implements SkillInterface
{
    private string $skillName;
    private string $skillDescription;
    private ?string $skillInstructions = null;
    private ?string $skillTrigger = null;
    private int $skillPriority = 0;
    private array $frontmatter = [];
    private readonly string $directory;

    private ?string $skillToolsSection = null;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
        $this->parse();
    }

    public static function make(string $directory): self
    {
        return new self($directory);
    }

    private function parse(): void
    {
        $skillFile = $this->directory.'/SKILL.md';

        if (!file_exists($skillFile)) {
            throw new AgentException("SKILL.md not found in {$this->directory}");
        }

        $content = file_get_contents($skillFile);

        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n?(.*)$/s', $content, $matches)) {
            throw new AgentException("Invalid SKILL.md format in {$this->directory}: missing YAML frontmatter");
        }

        $frontmatter = $matches[1];
        $body = trim($matches[2]);

        $this->frontmatter = $this->parseFrontmatter($frontmatter);

        if (!isset($this->frontmatter['name']) || !is_string($this->frontmatter['name']) || $this->frontmatter['name'] === '') {
            throw new AgentException("SKILL.md in {$this->directory}: missing required 'name' field");
        }

        if (!isset($this->frontmatter['description']) || !is_string($this->frontmatter['description']) || $this->frontmatter['description'] === '') {
            throw new AgentException("SKILL.md in {$this->directory}: missing required 'description' field");
        }

        $dirName = basename($this->directory);
        if ($this->frontmatter['name'] !== $dirName) {
            throw new AgentException(
                "Skill name '{$this->frontmatter['name']}' must match directory name '{$dirName}'"
            );
        }

        if (!preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $this->frontmatter['name'])) {
            throw new AgentException(
                "Skill name '{$this->frontmatter['name']}' must contain only lowercase letters, numbers, and hyphens"
            );
        }

        $this->skillName = $this->frontmatter['name'];
        $this->skillDescription = $this->frontmatter['description'];
        $this->skillInstructions = $body !== '' ? $body : null;

        if (isset($this->frontmatter['metadata']['priority'])) {
            $this->skillPriority = (int) $this->frontmatter['metadata']['priority'];
        }

        // Parse declarative tools from ## Tools section
        $this->skillToolsSection = self::extractSection($body, 'Tools');

        // Parse trigger section for Tier 1 disclosure
        $this->skillTrigger = self::extractSection($body, 'Trigger');
    }

    private function parseFrontmatter(string $frontmatter): array
    {
        $data = [];
        $lines = explode("\n", $frontmatter);
        $currentSection = null;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^([a-z][a-z0-9-]*):\s*(.*)$/', $line, $matches)) {
                $key = $matches[1];
                $value = self::stripQuotes($matches[2]);

                if ($key === 'metadata') {
                    $data['metadata'] = [];
                    $currentSection = 'metadata';
                } else {
                    $data[$key] = $value;
                    $currentSection = null;
                }
            } elseif ($currentSection === 'metadata' && preg_match('/^\s+([a-zA-Z_][a-zA-Z0-9_-]*):\s*(.*)$/', $line, $matches)) {
                $data['metadata'][$matches[1]] = self::stripQuotes($matches[2]);
            }
        }

        return $data;
    }

    private static function stripQuotes(string $value): string
    {
        if (preg_match('/^"(.*)"$/', $value, $matches)) {
            return $matches[1];
        }

        if (preg_match("/^'(.*)'$/", $value, $matches)) {
            return $matches[1];
        }

        return $value;
    }

    /**
     * Extract the content of a ## Section from markdown body.
     */
    private static function extractSection(string $body, string $sectionName): ?string
    {
        $pattern = '/^## ' . preg_quote($sectionName, '/') . '\s*\n(.*?)(?=^## |\z)/msi';

        if (!preg_match($pattern, $body, $matches)) {
            return null;
        }

        $content = trim($matches[1]);
        return $content !== '' ? $content : null;
    }

    public function name(): string
    {
        return $this->skillName;
    }

    public function description(): string
    {
        return $this->skillDescription;
    }

    public function priority(): int
    {
        return $this->skillPriority;
    }

    /**
     * Body instructions with ## Tools and ## Trigger sections stripped.
     * Tools are loaded as Tool objects; Trigger is exposed via trigger().
     * Remaining sections (Reasoning, Plan, Policy, Fallback) are kept as LLM guidance.
     */
    public function instructions(): ?string
    {
        if ($this->skillInstructions === null) {
            return null;
        }

        $filtered = $this->skillInstructions;

        // Remove ## Tools — loaded as Tool objects.
        // Remove ## Trigger — shown separately in Tier 1 via trigger().
        // Keep all other sections (Reasoning, Plan, Policy, Fallback).
        $filtered = preg_replace('/^## Tools\s*\n(.*?)(?=^## |\z)/msi', '', $filtered);
        $filtered = preg_replace('/^## Trigger\s*\n(.*?)(?=^## |\z)/msi', '', $filtered);

        $filtered = trim($filtered);

        return $filtered !== '' ? $filtered : null;
    }

    public function trigger(): ?string
    {
        return $this->skillTrigger;
    }

    public function tools(): array
    {
        if ($this->skillToolsSection === null || $this->skillToolsSection === '') {
            return [];
        }

        return DeclarativeToolBuilder::build(
            $this->directory,
            $this->skillName,
            $this->skillDescription,
            $this->skillToolsSection,
        );
    }

    public function configure(AgentInterface $agent): void
    {
    }

    // --- Accessors ---

    public function getLicense(): ?string
    {
        return $this->frontmatter['license'] ?? null;
    }

    public function getCompatibility(): ?string
    {
        return $this->frontmatter['compatibility'] ?? null;
    }

    public function getMetadata(): array
    {
        return $this->frontmatter['metadata'] ?? [];
    }

    /**
     * @return string[]
     */
    public function getAllowedTools(): array
    {
        if (!isset($this->frontmatter['allowed-tools'])) {
            return [];
        }

        return explode(' ', $this->frontmatter['allowed-tools']);
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * List bundled resource files (everything except SKILL.md) in the skill directory.
     *
     * @return string[]
     */
    public function listResources(): array
    {
        $resources = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== 'SKILL.md') {
                $resources[] = $iterator->getSubPathname();
            }
        }

        return $resources;
    }
}
