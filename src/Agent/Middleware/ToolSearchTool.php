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

class ToolSearchTool extends Tool
{
    /** @var ToolInterface[] */
    protected array $discovered = [];

    /**
     * @param ToolInterface[] $toolPool
     * @param callable(string, ToolInterface): bool|null $searchCallback
     */
    public function __construct(
        protected array $toolPool,
        protected $searchCallback = null,
    ) {
        parent::__construct(
            name: 'tool_search',
            description: 'Search for available tools by name or description. Returns matching tools that will become available for you to use.',
        );
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

        $scoredTools = [];
        $queryLower = strtolower(trim($query));

        foreach ($this->toolPool as $tool) {
            $score = 0;
            $toolName = strtolower($tool->getName());
            $toolDescription = strtolower($tool->getDescription() ?? '');

            // 1. Full query substring match
            if ($queryLower !== '') {
                if (str_contains($toolName, $queryLower)) {
                    $score += 50;
                }
                if ($toolDescription !== '' && str_contains($toolDescription, $queryLower)) {
                    $score += 20;
                }
            }

            // 2. Keyword-based matching
            $toolNameWords = $this->tokenize($tool->getName());
            $toolDescriptionWords = $toolDescription !== '' ? $this->tokenize($toolDescription) : [];

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
                } elseif ($toolDescription !== '' && str_contains($toolDescription, $keyword)) {
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
                    'tool' => $tool,
                    'score' => $score,
                ];
            }
        }

        // Sort by score descending
        usort($scoredTools, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        // Map back to ToolInterface[], limit to 10 results
        $results = [];
        $limit = 10;
        foreach ($scoredTools as $item) {
            $results[] = $item['tool'];
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
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
