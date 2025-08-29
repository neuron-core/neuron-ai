<?php

declare(strict_types=1);

namespace NeuronAI;

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Observability\Events\ToolsBootstrapped;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

trait HandleTools
{
    /**
     * Registered tools.
     *
     * @var ToolInterface[]|ToolkitInterface[]
     */
    protected array $tools = [];

    /**
     * @var ToolInterface[]
     */
    protected array $toolsBootstrapCache = [];

    protected int $tollMaxTries = 5;

    /**
     * @var array<string, int>
     */
    protected array $toolAttempts = [];

    public function toolMaxTries(int $tries): Agent
    {
        $this->tollMaxTries = $tries;
        return $this;
    }

    /**
     * Get the list of tools.
     *
     * @return ToolInterface[]|ToolkitInterface[]
     */
    protected function tools(): array
    {
        return [];
    }

    /**
     * @return ToolInterface[]|ToolkitInterface[]
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
                if ($kitGuidelines !== null && $kitGuidelines !== '' && $kitGuidelines !== '0') {
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
            $this->withInstructions(
                $instructions.\PHP_EOL.'<TOOLS-GUIDELINES>'.\PHP_EOL.\implode(\PHP_EOL.\PHP_EOL, $guidelines).\PHP_EOL.'</TOOLS-GUIDELINES>'
            );
        }

        $this->notify('tools-bootstrapped', new ToolsBootstrapped($this->toolsBootstrapCache, $guidelines));

        return $this->toolsBootstrapCache;
    }

    /**
     * Add tools.
     *
     * @throws AgentException
     */
    public function addTool(ToolInterface|ToolkitInterface|array $tools): AgentInterface
    {
        $tools = \is_array($tools) ? $tools : [$tools];

        foreach ($tools as $t) {
            if (! $t instanceof ToolInterface && ! $t instanceof ToolkitInterface) {
                throw new AgentException('Tools must be an instance of ToolInterface or ToolkitInterface');
            }
            $this->tools[] = $t;
        }

        // Empty the cache for the next turn.
        $this->toolsBootstrapCache = [];

        return $this;
    }

    protected function executeTools(ToolCallMessage $toolCallMessage): ToolCallResultMessage
    {
        $toolCallResult = new ToolCallResultMessage($toolCallMessage->getTools());

        foreach ($toolCallResult->getTools() as $tool) {
            $this->notify('tool-calling', new ToolCalling($tool));
            try {
                $this->toolAttempts[$tool->getName()] = ($this->toolAttempts[$tool->getName()] ?? 0) + 1;

                if ($this->toolAttempts[$tool->getName()] > $this->tollMaxTries) {
                    throw new AgentException("Tool {$tool->getName()} has been attempted too many times: {$this->tollMaxTries} attempts.");
                }

                $tool->execute();
            } catch (\Throwable $exception) {
                $this->notify('error', new AgentError($exception));
                throw $exception;
            }
            $this->notify('tool-called', new ToolCalled($tool));
        }

        return $toolCallResult;
    }
}
