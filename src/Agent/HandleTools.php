<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Exceptions\AgentException;
use NeuronAI\Observability\Events\ToolsBootstrapped;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

trait HandleTools
{
    /**
     * Registered tools.
     *
     * @var ToolInterface[]|ToolkitInterface[]|ProviderToolInterface[]
     */
    protected array $tools = [];

    /**
     * @var ToolInterface[]
     */
    protected array $toolsBootstrapCache = [];

    /**
     * Global max tries for all tools.
     */
    protected int $toolMaxTries = 5;

    /**
     * @var array<string, int>
     */
    protected array $toolAttempts = [];

    public function toolMaxTries(int $tries): Agent
    {
        $this->toolMaxTries = $tries;
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
        return \array_merge($this->tools, $this->tools());
    }

    /**
     * If toolkits have already bootstrapped, this function
     * just traverses the array of tools without any action.
     *
     * @return ToolInterface[]
     */
    public function bootstrapTools(): array
    {
        $guidelines = [];

        if (!empty($this->toolsBootstrapCache)) {
            return $this->toolsBootstrapCache;
        }

        $this->notify('tools-bootstrapping');

        foreach ($this->getTools() as $tool) {
            if ($tool instanceof ToolkitInterface) {
                $kitGuidelines = $tool->guidelines();
                if ($kitGuidelines !== null && $kitGuidelines !== '') {
                    $name = (new \ReflectionClass($tool))->getShortName();
                    $kitGuidelines = '# '.$name.\PHP_EOL.$kitGuidelines;
                }

                // Merge the tools
                $innerTools = $tool->tools();
                $this->toolsBootstrapCache = \array_merge($this->toolsBootstrapCache, $innerTools);

                // Add guidelines to the system prompt
                if (!in_array($kitGuidelines, [null, '', '0'], true)) {
                    $kitGuidelines .= \PHP_EOL.\implode(
                        \PHP_EOL.'- ',
                        \array_map(
                            fn (ToolInterface $tool): string => "{$tool->getName()}: {$tool->getDescription()}",
                            $innerTools
                        )
                    );

                    $guidelines[] = $kitGuidelines;
                }
            } else {
                // If the item is a simple tool, add to the list as it is
                $this->toolsBootstrapCache[] = $tool;
            }
        }

        $instructions = $this->removeDelimitedContent($this->resolveInstructions(), '<TOOLS-GUIDELINES>', '</TOOLS-GUIDELINES>');
        if ($guidelines !== []) {
            $this->setInstructions(
                $instructions.\PHP_EOL.'<TOOLS-GUIDELINES>'.\PHP_EOL.\implode(\PHP_EOL.\PHP_EOL, $guidelines).\PHP_EOL.'</TOOLS-GUIDELINES>'
            );
        }

        $this->notify('tools-bootstrapped', new ToolsBootstrapped($this->toolsBootstrapCache, $guidelines));

        return $this->toolsBootstrapCache;
    }

    /**
     * Add tools.
     *
     * @param  ToolInterface|ToolkitInterface|ProviderToolInterface|array<ToolInterface|ToolkitInterface|ProviderToolInterface>  $tools
     * @throws AgentException
     */
    public function addTool(ToolInterface|ToolkitInterface|ProviderToolInterface|array $tools): AgentInterface
    {
        $tools = \is_array($tools) ? $tools : [$tools];

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
