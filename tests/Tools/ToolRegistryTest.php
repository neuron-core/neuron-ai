<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\ProviderTool;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;

class ToolRegistryTest extends TestCase
{
    public function test_lists_the_tools_in_registration_order(): void
    {
        $registry = new ToolRegistry([new ToolStub('search'), new ProviderTool('web_search', 'web')]);
        $registry->add(new ToolStub('read'));

        $this->assertSame(['search', 'web', 'read'], $this->names($registry));
    }

    public function test_finds_a_tool_by_exact_name(): void
    {
        $search = new ToolStub('search');
        $registry = new ToolRegistry([new ToolStub('search_web'), $search]);

        $this->assertSame($search, $registry->find('search'));
        $this->assertNull($registry->find('Search'));
        $this->assertNull($registry->find('search '));
        $this->assertNull($registry->find(''));
    }

    public function test_never_resolves_a_provider_tool_for_execution(): void
    {
        $registry = new ToolRegistry([new ProviderTool('web_search', 'web_search')]);

        $this->assertNull($registry->find('web_search'));
    }

    public function test_the_first_registration_of_a_name_wins(): void
    {
        $original = new ToolStub('delete_file', 'Deletes one file inside the sandbox');
        $registry = new ToolRegistry([$original]);

        $registry->add(new ToolStub('delete_file', 'Deletes anything'));
        $registry->add(new ProviderTool('delete_file', 'delete_file'));

        $this->assertCount(1, $registry->all());
        $this->assertSame($original, $registry->find('delete_file'));
    }

    public function test_add_is_idempotent_for_the_same_instance(): void
    {
        $tool = new ToolStub('search');
        $registry = new ToolRegistry();

        $registry->add($tool);
        $registry->add($tool);

        $this->assertSame([$tool], $registry->all());
    }

    public function test_remove_drops_every_tool_with_the_name_and_reindexes(): void
    {
        $registry = new ToolRegistry([new ToolStub('a'), new ToolStub('b'), new ProviderTool('web', 'b'), new ToolStub('c')]);

        $registry->remove('b');

        $this->assertSame(['a', 'c'], $this->names($registry));
        $this->assertSame([0, 1], array_keys($registry->all()));
        $this->assertNull($registry->find('b'));
    }

    public function test_removing_an_unknown_name_changes_nothing(): void
    {
        $registry = new ToolRegistry([new ToolStub('a')]);

        $registry->remove('z');

        $this->assertSame(['a'], $this->names($registry));
    }

    public function test_a_removed_name_can_be_registered_again(): void
    {
        $registry = new ToolRegistry([new ToolStub('a')]);
        $replacement = new ToolStub('a', 'replacement');

        $registry->remove('a');
        $registry->add($replacement);

        $this->assertSame($replacement, $registry->find('a'));
    }

    /**
     * @return array<string|null>
     */
    protected function names(ToolRegistry $registry): array
    {
        return array_map(fn (ToolInterface|ProviderToolInterface $tool): ?string => $tool->getName(), $registry->all());
    }
}
