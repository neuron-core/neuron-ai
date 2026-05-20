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
use function is_callable;
use function sprintf;
use function stripos;

class ToolSearchTool extends Tool
{
    protected string $name = 'tool_search';

    protected ?string $description = 'Search for available tools by name or description. Returns matching tools that will become available for you to use.';

    /**
     * @var ToolInterface[]
     */
    protected array $discovered = [];

    /**
     * @var callable(string, ToolInterface): bool|null
     */
    protected $searchCallback;

    /**
     * @param ToolInterface[] $toolPool
     * @param callable(string, ToolInterface): bool|null $searchCallback
     */
    public function __construct(
        protected array     $toolPool,
        ?callable $searchCallback = null,
    ) {
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
        return array_values(array_filter(
            $this->toolPool,
            function (ToolInterface $tool) use ($query): bool {
                $haystack = $tool->getName() . ' ' . ($tool->getDescription() ?? '');
                return stripos($haystack, $query) !== false;
            }
        ));
    }
}
