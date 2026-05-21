<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;

use function array_filter;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_callable;
use function levenshtein;
use function preg_replace;
use function preg_split;
use function sprintf;
use function str_contains;
use function strlen;
use function strtolower;
use function trim;
use function usort;
use function array_column;
use function array_slice;

class ToolSearchTool extends Tool
{
    /** @var ToolInterface[] */
    protected array $discovered = [];

    /**
     * @var callable(string, ToolInterface): bool|null
     */
    protected $searchCallback;

    /**
     * @var array<int, array{tool: ToolInterface, nameLower: string, descLower: string, nameWords: string[], descWords: string[]}>|null
     */
    protected ?array $toolIndex = null;

    /**
     * @param ToolInterface[] $toolPool
     * @param callable(string, ToolInterface): bool|null $searchCallback
     */
    public function __construct(
        protected array $toolPool,
        ?callable $searchCallback = null,
    ) {
        parent::__construct(
            name: 'tool_search',
            description: 'Search for available tools by name or description. Returns matching tools that will become available for you to use.',
        );

        $this->searchCallback = $searchCallback;
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'query',
                type: PropertyType::STRING,
                description: 'Search query to find relevant tools by name or description',
                required: true,
            ),
        ];
    }

    public function __invoke(string $query): string
    {
        $matches = is_callable($this->searchCallback)
            ? array_values(array_filter($this->toolPool, fn (ToolInterface $tool): bool => ($this->searchCallback)($query, $tool)))
            : $this->defaultSearch($query);

        $this->discovered = $matches;

        if ($matches === []) {
            return "No tools found matching '{$query}'.";
        }

        $descriptions = [];
        foreach ($matches as $tool) {
            $descriptions[] = sprintf(
                '- %s: %s',
                $tool->getName(),
                $tool->getDescription() ?? 'No description'
            );
        }

        return 'Found ' . count($matches) . " tool(s):\n" . implode("\n", $descriptions);
    }

    /**
     * @return ToolInterface[]
     */
    public function discoveredTools(): array
    {
        return $this->discovered;
    }

    /**
     * @return ToolInterface[]
     */
    protected function defaultSearch(string $query): array
    {
        $queryKeywords = $this->tokenize($query);
        if ($queryKeywords === []) {
            return [];
        }

        $this->toolIndex ??= $this->buildToolIndex();

        $scoredTools = [];
        $queryLower = strtolower(trim($query));

        foreach ($this->toolIndex as $entry) {
            $score = 0;
            $toolName = $entry['nameLower'];
            $toolDescription = $entry['descLower'];
            $descEmpty = $toolDescription === '';

            // 1. Full query substring match
            if (str_contains($toolName, $queryLower)) {
                $score += 50;
            }
            if (!$descEmpty && str_contains($toolDescription, $queryLower)) {
                $score += 20;
            }

            // 2. Keyword-based matching
            $toolNameWords = $entry['nameWords'];
            $toolDescriptionWords = $entry['descWords'];

            foreach ($queryKeywords as $keyword) {
                // Check name matches (tiered check)
                $nameMatched = false;
                if (in_array($keyword, $toolNameWords, true)) {
                    $score += 15;
                    $nameMatched = true;
                } elseif (str_contains($toolName, $keyword)) {
                    $score += 10;
                    $nameMatched = true;
                }

                // If not matched directly in name, check typo tolerance in name words
                if (!$nameMatched && strlen($keyword) >= 4) {
                    foreach ($toolNameWords as $nameWord) {
                        if (strlen($nameWord) >= 4 && levenshtein($keyword, $nameWord) <= 2) {
                            $score += 5;
                            break; // only match once per keyword
                        }
                    }
                }

                // Check description matches (tiered check)
                $descMatched = false;
                if (in_array($keyword, $toolDescriptionWords, true)) {
                    $score += 5;
                    $descMatched = true;
                } elseif (!$descEmpty && str_contains($toolDescription, $keyword)) {
                    $score += 2;
                    $descMatched = true;
                }

                // If not matched directly in description, check typo tolerance in description words
                if (!$descMatched && strlen($keyword) >= 4) {
                    foreach ($toolDescriptionWords as $descWord) {
                        if (strlen($descWord) >= 4 && levenshtein($keyword, $descWord) <= 2) {
                            $score += 1;
                            break; // only match once per keyword
                        }
                    }
                }
            }

            if ($score > 0) {
                $scoredTools[] = [
                    'tool' => $entry['tool'],
                    'score' => $score,
                ];
            }
        }

        // Sort by score descending
        usort($scoredTools, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_column(array_slice($scoredTools, 0, 10), 'tool');
    }

    /**
     * Precomputes lowercased names/descriptions and tokenized word arrays for every tool in the pool.
     *
     * @return array<int, array{tool: ToolInterface, nameLower: string, descLower: string, nameWords: string[], descWords: string[]}>
     */
    protected function buildToolIndex(): array
    {
        $index = [];
        foreach ($this->toolPool as $tool) {
            $descLower = strtolower($tool->getDescription() ?? '');
            $index[] = [
                'tool' => $tool,
                'nameLower' => strtolower($tool->getName()),
                'descLower' => $descLower,
                'nameWords' => $this->tokenize($tool->getName()),
                'descWords' => $descLower !== '' ? $this->tokenize($descLower) : [],
            ];
        }
        return $index;
    }

    /**
     * Tokenizes a string into lowercase words, splitting on spaces, camelCase transitions, and common separators.
     *
     * @return string[]
     */
    protected function tokenize(string $text): array
    {
        $spaced = preg_replace('/(?<!^)[A-Z]/', '_$0', $text);
        if ($spaced === null) {
            $spaced = $text;
        }

        $spaced = strtolower(trim($spaced));
        if ($spaced === '') {
            return [];
        }

        return array_values(array_filter(
            preg_split('/[\s,\.\-_]+/', $spaced),
            fn (string $word): bool => $word !== ''
        ));
    }
}
