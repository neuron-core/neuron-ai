<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Skills;

use NeuronAI\Agent\Skills\MarkdownSkill;
use NeuronAI\Agent\Skills\SkillLoader;
use NeuronAI\Exceptions\AgentException;
use PHPUnit\Framework\TestCase;

class MarkdownSkillTest extends TestCase
{
    private string $fixturesPath;

    private string $overrideFixturesPath;

    private string $toolsFixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = __DIR__.'/../fixtures/skills';
        $this->overrideFixturesPath = __DIR__.'/../fixtures/skills-override';
        $this->toolsFixturesPath = __DIR__.'/../fixtures/skills-tools';
    }

    // --- Parsing ---

    public function test_parse_valid_skill(): void
    {
        $skill = new MarkdownSkill($this->fixturesPath.'/web-search');

        $this->assertSame('web-search', $skill->name());
        $this->assertSame('Search the web for information and return results to the user', $skill->description());
        $this->assertNotNull($skill->instructions());
        $this->assertStringContainsString('Use the search tool', $skill->instructions());
        $this->assertSame('MIT', $skill->getLicense());
        $this->assertSame([], $skill->tools());
        $this->assertSame(0, $skill->priority());
    }

    public function test_parse_minimal_skill(): void
    {
        $skill = new MarkdownSkill($this->fixturesPath.'/minimal-skill');

        $this->assertSame('minimal-skill', $skill->name());
        $this->assertSame('A bare minimum skill with just the required fields', $skill->description());
        $this->assertSame('Follow these instructions carefully.', $skill->instructions());
    }

    public function test_skill_with_metadata(): void
    {
        $skill = new MarkdownSkill($this->fixturesPath.'/with-metadata');

        $this->assertSame('with-metadata', $skill->name());
        $this->assertSame(-5, $skill->priority());
        $this->assertSame('Apache-2.0', $skill->getLicense());
        $this->assertSame('PHP 8.1+', $skill->getCompatibility());
        $this->assertSame(
            ['priority' => '-5', 'author' => 'test-author', 'version' => '1.0'],
            $skill->getMetadata()
        );
        $this->assertSame(['search', 'calculate'], $skill->getAllowedTools());
    }

    public function test_skill_no_body_returns_null_instructions(): void
    {
        $skill = new MarkdownSkill($this->fixturesPath.'/no-body');

        $this->assertSame('no-body', $skill->name());
        $this->assertNull($skill->instructions());
    }

    public function test_tools_skill_instructions_filters_implementation_sections(): void
    {
        $skill = new MarkdownSkill($this->fixturesPath.'/tools-only');

        // Skills with only ## Tools have no instructions (Tools section is filtered)
        $this->assertNull($skill->instructions());
    }

    public function test_static_constructor(): void
    {
        $skill = MarkdownSkill::make($this->fixturesPath.'/web-search');

        $this->assertInstanceOf(MarkdownSkill::class, $skill);
        $this->assertSame('web-search', $skill->name());
    }

    // --- Validation ---

    public function test_missing_skill_file_throws_exception(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('SKILL.md not found');

        new MarkdownSkill($this->fixturesPath.'/nonexistent');
    }

    public function test_name_directory_mismatch_throws_exception(): void
    {
        $dir = sys_get_temp_dir().'/neuron-test-mismatch';
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/SKILL.md', "---\nname: wrong-name\ndescription: test\n---\nBody");

        try {
            $this->expectException(AgentException::class);
            $this->expectExceptionMessage('must match directory name');

            new MarkdownSkill($dir);
        } finally {
            @unlink($dir.'/SKILL.md');
            @rmdir($dir);
        }
    }

    public function test_invalid_name_format_throws_exception(): void
    {
        $dir = sys_get_temp_dir().'/INVALID';
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/SKILL.md', "---\nname: INVALID\ndescription: test\n---\nBody");

        try {
            $this->expectException(AgentException::class);
            $this->expectExceptionMessage('lowercase');

            new MarkdownSkill($dir);
        } finally {
            @unlink($dir.'/SKILL.md');
            @rmdir($dir);
        }
    }

    public function test_missing_required_name_throws_exception(): void
    {
        $dir = sys_get_temp_dir().'/neuron-test-noname';
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/SKILL.md', "---\ndescription: test\n---\nBody");

        try {
            $this->expectException(AgentException::class);
            $this->expectExceptionMessage("missing required 'name'");

            new MarkdownSkill($dir);
        } finally {
            @unlink($dir.'/SKILL.md');
            @rmdir($dir);
        }
    }

    public function test_missing_required_description_throws_exception(): void
    {
        $dir = sys_get_temp_dir().'/neuron-test-nodesc';
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/SKILL.md', "---\nname: neuron-test-nodesc\n---\nBody");

        try {
            $this->expectException(AgentException::class);
            $this->expectExceptionMessage("missing required 'description'");

            new MarkdownSkill($dir);
        } finally {
            @unlink($dir.'/SKILL.md');
            @rmdir($dir);
        }
    }

    public function test_missing_frontmatter_throws_exception(): void
    {
        $dir = sys_get_temp_dir().'/neuron-test-nofm';
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/SKILL.md', "Just some markdown without frontmatter");

        try {
            $this->expectException(AgentException::class);
            $this->expectExceptionMessage('missing YAML frontmatter');

            new MarkdownSkill($dir);
        } finally {
            @unlink($dir.'/SKILL.md');
            @rmdir($dir);
        }
    }

    // --- SkillLoader: discover ---

    public function test_skill_loader_discover_from_single_directory(): void
    {
        $skills = SkillLoader::discover([$this->fixturesPath]);

        $names = array_map(fn (MarkdownSkill $skill): string => $skill->name(), $skills);

        $this->assertContains('web-search', $names);
        $this->assertContains('french-translator', $names);
        $this->assertContains('minimal-skill', $names);
        $this->assertContains('with-metadata', $names);
        $this->assertContains('no-body', $names);
    }

    public function test_skill_loader_discover_from_multiple_directories(): void
    {
        $skills = SkillLoader::discover([$this->fixturesPath, $this->overrideFixturesPath]);

        $names = array_map(fn (MarkdownSkill $skill): string => $skill->name(), $skills);

        $this->assertContains('web-search', $names);
        $this->assertContains('french-translator', $names);
    }

    public function test_skill_loader_discover_later_directory_overrides_same_name(): void
    {
        $skills = SkillLoader::discover([$this->fixturesPath, $this->overrideFixturesPath]);

        $webSearch = array_values(array_filter(
            $skills,
            fn (MarkdownSkill $skill): bool => $skill->name() === 'web-search'
        ))[0];

        $this->assertStringContainsString('overridden web search skill', $webSearch->instructions());
        $this->assertSame('Override web search from skills-extra directory', $webSearch->description());
    }

    public function test_skill_loader_discover_deduplicates_by_name(): void
    {
        $skills = SkillLoader::discover([$this->fixturesPath, $this->overrideFixturesPath]);

        $webSearchSkills = array_filter(
            $skills,
            fn (MarkdownSkill $skill): bool => $skill->name() === 'web-search'
        );

        $this->assertCount(1, $webSearchSkills);
    }

    public function test_skill_loader_load_single(): void
    {
        $skill = SkillLoader::load($this->fixturesPath.'/web-search');

        $this->assertSame('web-search', $skill->name());
    }

    public function test_skill_loader_nonexistent_directory(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Directory not found');

        SkillLoader::discover(['/nonexistent/path']);
    }

    public function test_skill_loader_empty_directory(): void
    {
        $dir = sys_get_temp_dir().'/neuron-test-empty';
        @mkdir($dir, 0777, true);

        try {
            $skills = SkillLoader::discover([$dir]);
            $this->assertSame([], $skills);
        } finally {
            @rmdir($dir);
        }
    }

    // --- SkillLoader: loadPaths ---

    public function test_skill_loader_load_paths_single(): void
    {
        $skills = SkillLoader::loadPaths([$this->fixturesPath.'/web-search']);

        $this->assertCount(1, $skills);
        $this->assertSame('web-search', $skills[0]->name());
    }

    public function test_skill_loader_load_paths_multiple(): void
    {
        $skills = SkillLoader::loadPaths([
            $this->fixturesPath.'/web-search',
            $this->fixturesPath.'/french-translator',
        ]);

        $this->assertCount(2, $skills);
        $names = array_map(fn (MarkdownSkill $skill): string => $skill->name(), $skills);
        $this->assertContains('web-search', $names);
        $this->assertContains('french-translator', $names);
    }

    public function test_skill_loader_load_paths_later_overrides_same_name(): void
    {
        $skills = SkillLoader::loadPaths([
            $this->fixturesPath.'/web-search',
            $this->overrideFixturesPath.'/web-search',
        ]);

        $this->assertCount(1, $skills);
        $this->assertStringContainsString('overridden web search skill', $skills[0]->instructions());
    }

    public function test_skill_loader_load_paths_nonexistent_directory(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Directory not found');

        SkillLoader::loadPaths(['/nonexistent/path']);
    }

    // --- Section filtering ---

    public function test_trigger_section_exposed_via_trigger_method(): void
    {
        $skill = new MarkdownSkill($this->toolsFixturesPath.'/shipping-calculator');
        $this->assertNotNull($skill->trigger());
        $this->assertStringContainsString('shipping', $skill->trigger());
    }

    public function test_trigger_section_excluded_from_instructions(): void
    {
        $skill = new MarkdownSkill($this->toolsFixturesPath.'/shipping-calculator');
        $this->assertStringNotContainsString('## Trigger', $skill->instructions());
    }

    public function test_reasoning_section_included_in_instructions(): void
    {
        $skill = new MarkdownSkill($this->toolsFixturesPath.'/shipping-calculator');
        $this->assertStringContainsString('## Reasoning', $skill->instructions());
    }

    public function test_fallback_section_included_in_instructions(): void
    {
        $skill = new MarkdownSkill($this->fixturesPath.'/with-fallback');
        $this->assertStringContainsString('## Fallback', $skill->instructions());
    }

    public function test_tools_section_excluded_from_instructions(): void
    {
        $skill = new MarkdownSkill($this->fixturesPath.'/tools-only');
        $this->assertNull($skill->instructions());
    }

    public function test_skill_without_capability_sections_returns_body(): void
    {
        $skill = new MarkdownSkill($this->fixturesPath.'/no-capability');
        $this->assertNotNull($skill->instructions());
        $this->assertStringContainsString('Basic Skill', $skill->instructions());
    }
}
