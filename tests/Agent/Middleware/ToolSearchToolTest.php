<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware;

use NeuronAI\Agent\Middleware\ToolSearchTool;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * The search must stay deterministic for a given pool: the middleware
 * re-derives past discoveries by repeating it.
 */
class ToolSearchToolTest extends TestCase
{
    protected function tool(string $name, ?string $description): Tool
    {
        $tool = new class () extends Tool {
            public function __invoke(): string
            {
                return 'executed';
            }
        };

        $tool->setName($name)->setDescription($description);

        return $tool;
    }

    /**
     * @param ToolInterface[] $tools
     * @return string[]
     */
    protected function names(array $tools): array
    {
        return array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools);
    }

    public function test_a_name_match_outranks_a_description_match(): void
    {
        $search = new ToolSearchTool([
            $this->tool('notify', 'Send an email to the user'),
            $this->tool('send_email', 'Deliver messages'),
        ]);

        $this->assertSame(['send_email', 'notify'], $this->names($search->search('email')));
    }

    public function test_camel_case_names_are_split_into_words(): void
    {
        // 'user' is a whole word of getUserProfile but only a fragment of superuser_tool.
        $search = new ToolSearchTool([
            $this->tool('superuser_tool', 'Admin utilities'),
            $this->tool('getUserProfile', 'Read a profile'),
        ]);

        $this->assertSame(['getUserProfile', 'superuser_tool'], $this->names($search->search('user')));
    }

    public function test_every_query_word_contributes_to_the_rank(): void
    {
        $search = new ToolSearchTool([
            $this->tool('read_file', 'Read a file from disk'),
            $this->tool('read_database', 'Read rows from the database'),
        ]);

        $this->assertSame(['read_database', 'read_file'], $this->names($search->search('read database')));
    }

    public function test_results_are_limited_to_the_top_n(): void
    {
        $search = new ToolSearchTool([
            $this->tool('file_stat', 'Inspect metadata'),
            $this->tool('read_file', 'Read a file'),
            $this->tool('file', 'The file tool'),
        ], topN: 2);

        // file_stat matches by name only, so the two name-and-description matches win.
        $this->assertSame(['read_file', 'file'], $this->names($search->search('file')));
    }

    public function test_equal_scores_keep_the_pool_order(): void
    {
        $search = new ToolSearchTool([
            $this->tool('beta_report', 'Report'),
            $this->tool('alpha_report', 'Report'),
            $this->tool('gamma_report', 'Report'),
        ]);

        $this->assertSame(['beta_report', 'alpha_report', 'gamma_report'], $this->names($search->search('report')));
    }

    public function test_small_typos_in_long_words_are_tolerated(): void
    {
        $search = new ToolSearchTool([$this->tool('get_weather', 'Current conditions'), $this->tool('get_time', 'Current time')]);

        $this->assertSame(['get_weather'], $this->names($search->search('wether')));
    }

    /** @param string[] $expected */
    #[TestWith(['weathxx', ['get_weather']])]
    #[TestWith(['wexxxer', []])]
    public function test_typo_tolerance_stops_at_two_edits(string $query, array $expected): void
    {
        $search = new ToolSearchTool([$this->tool('get_weather', 'Current conditions')]);

        $this->assertSame($expected, $this->names($search->search($query)));
    }

    public function test_short_words_require_an_exact_fragment(): void
    {
        $search = new ToolSearchTool([$this->tool('car_rental', 'Rent vehicles')]);

        $this->assertSame([], $search->search('cat'));
    }

    public function test_a_description_typo_counts_less_than_a_name_typo(): void
    {
        $search = new ToolSearchTool([
            $this->tool('lookup', 'Find the weather forecast'),
            $this->tool('weather', 'Lookup service'),
        ]);

        $this->assertSame(['weather', 'lookup'], $this->names($search->search('wether')));
    }

    public function test_tools_without_a_description_are_searchable_by_name(): void
    {
        $search = new ToolSearchTool([$this->tool('export_csv', null)]);

        $this->assertSame(['export_csv'], $this->names($search->search('csv')));
        $this->assertSame("Found 1 tool(s):\n- export_csv: No description", $search('csv'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function queriesWithoutWords(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ["  \t "];
        yield 'separators only' => ['-_,. '];
    }

    #[DataProvider('queriesWithoutWords')]
    public function test_a_query_without_words_finds_nothing(string $query): void
    {
        $search = new ToolSearchTool([$this->tool('read_file', 'Read a file'), $this->tool('_', '-')]);

        $this->assertSame([], $search->search($query));
        $this->assertSame("No tools found matching '{$query}'.", $search($query));
    }

    public function test_it_lists_the_found_tools_for_the_model(): void
    {
        $search = new ToolSearchTool([
            $this->tool('send_email', 'Deliver messages'),
            $this->tool('notify', 'Send an email to the user'),
            $this->tool('read_file', 'Read a file'),
        ]);

        $this->assertSame(
            "Found 2 tool(s):\n- send_email: Deliver messages\n- notify: Send an email to the user",
            $search('email')
        );
    }

    public function test_repeating_a_search_returns_the_same_tools(): void
    {
        $pool = [$this->tool('send_email', 'Deliver messages'), $this->tool('notify', 'Send an email')];
        $first = (new ToolSearchTool($pool))->search('email');

        $this->assertSame($first, (new ToolSearchTool($pool))->search('email'));
        $this->assertSame($pool[0], $first[0], 'The search returns the pool instances themselves');
    }
}
