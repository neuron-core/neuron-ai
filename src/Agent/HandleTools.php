<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\ContentHelper;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use ReflectionClass;

use function array_map;
use function array_merge;
use function implode;
use function in_array;
use function is_array;

use const PHP_EOL;

trait HandleTools
{
    /**
     * @var ToolInterface[]|ToolkitInterface[]|ProviderToolInterface[]
     */
    protected array $tools = [];

    /**
     * @var ToolInterface[]
     */
    protected array $toolsBootstrapCache = [];

    /**
     * Global max runs for all tools.
     */
    protected int $toolMaxRuns = 10;

    /**
     * @var callable|null fn(Throwable $e, ToolCall $call): string|ToolOutput|null
     */
    protected $toolErrorHandler;

    /**
     * Handle exceptions that escape tool execution: a returned string or
     * ToolOutput becomes the tool result visible to the LLM and the loop
     * continues; returning null declines — the exception propagates.
     *
     * @param callable|null $handler fn(Throwable $e, ToolCall $call): string|ToolOutput|null
     */
    public function toolErrorHandler(?callable $handler): Agent
    {
        $this->toolErrorHandler = $handler;
        return $this;
    }

    /**
     * Override to provide a default error handler in your agent.
     *
     * @return callable|null fn(Throwable $e, ToolCall $call): string|ToolOutput|null
     */
    protected function resolveToolErrorHandler(): ?callable
    {
        return $this->toolErrorHandler;
    }

    public function toolMaxRuns(int $num): Agent
    {
        $this->toolMaxRuns = $num;
        return $this;
    }

    /**
     * Override to provide tools to the agent.
     *
     * @return array<ToolInterface|ToolkitInterface|ProviderToolInterface>
     */
    protected function tools(): array
    {
        return [];
    }

    /**
     * @return array<ToolInterface|ToolkitInterface|ProviderToolInterface>
     */
    public function getTools(): array
    {
        return array_merge($this->tools, $this->tools());
    }

    /**
     * Expand toolkits into their tools and inject toolkit guidelines into the
     * instructions. Cached until the tool set changes.
     *
     * @return ToolInterface[]
     */
    public function bootstrapTools(): array
    {
        if (!empty($this->toolsBootstrapCache)) {
            return $this->toolsBootstrapCache;
        }

        $guidelines = [];

        foreach ($this->getTools() as $tool) {
            if ($tool instanceof ToolkitInterface) {
                $kitGuidelines = $tool->guidelines();
                if ($kitGuidelines !== null && $kitGuidelines !== '') {
                    $name = (new ReflectionClass($tool))->getShortName();
                    $kitGuidelines = '# '.$name.PHP_EOL.$kitGuidelines;
                }
                $innerTools = $tool->tools();
                $this->toolsBootstrapCache = array_merge($this->toolsBootstrapCache, $innerTools);

                if (!in_array($kitGuidelines, [null, '', '0'], true)) {
                    $kitGuidelines .= PHP_EOL.implode(
                        PHP_EOL.'- ',
                        array_map(
                            fn (ToolInterface $tool): string => $tool->getName(),
                            $innerTools
                        )
                    );

                    $guidelines[] = $kitGuidelines;
                }
            } elseif ($tool->isVisible()) {
                $this->toolsBootstrapCache[] = $tool;
            }
        }

        // Remove guidelines injected by a previous bootstrap, dropping blocks left empty.
        $blocks = [];
        foreach ($this->getInstructions()->getContentBlocks() as $block) {
            if ($block instanceof TextContent) {
                $block->content = ContentHelper::removeDelimitedContent($block->content, '<TOOLS-GUIDELINES>', '</TOOLS-GUIDELINES>');
                if ($block->content === '') {
                    continue;
                }
            }
            $blocks[] = $block;
        }

        if ($guidelines !== []) {
            $blocks[] = new SystemContent(
                '<TOOLS-GUIDELINES>'.PHP_EOL.implode(PHP_EOL.PHP_EOL, $guidelines).PHP_EOL.'</TOOLS-GUIDELINES>'
            );
        }

        $this->setInstructions(new SystemMessage($blocks));

        return $this->toolsBootstrapCache;
    }

    /**
     * @param  ToolInterface|ToolkitInterface|ProviderToolInterface|array<ToolInterface|ToolkitInterface|ProviderToolInterface>  $tools
     * @throws AgentException
     */
    public function addTool(ToolInterface|ToolkitInterface|ProviderToolInterface|array $tools): AgentInterface
    {
        $tools = is_array($tools) ? $tools : [$tools];

        foreach ($tools as $t) {
            if (! $t instanceof ToolInterface && ! $t instanceof ToolkitInterface && ! $t instanceof ProviderToolInterface) {
                throw new AgentException('Tools must be an instance of ToolInterface, ToolkitInterface, or ProviderToolInterface');
            }
            $this->tools[] = $t;
        }

        // Empty the cache for the next turn.
        $this->toolsBootstrapCache = [];

        return $this;
    }
}
