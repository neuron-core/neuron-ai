<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\ExecutionContext;
use NeuronAI\Workflow\WorkflowExecution;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use ReflectionClass;

use function array_map;
use function array_merge;
use function implode;
use function in_array;
use function serialize;
use function unserialize;

use const PHP_EOL;

/** Agent-specific resources for one execution segment. @internal */
class AgentExecution extends WorkflowExecution
{
    /** @var ToolInterface[] */
    protected array $resolvedTools = [];

    public function __construct(
        ExecutionContext $context,
        Agent $definition,
        WorkflowState $state,
        array $middleware,
        array $globalMiddleware,
        protected AIProviderInterface $provider,
        protected ChatHistory $history,
        protected SystemMessage $instructions,
        protected array $configuredTools,
    ) {
        parent::__construct($context, $definition, $state, $middleware, $globalMiddleware);
        $this->instructions = unserialize(serialize($instructions));
        $this->resolvedTools = $this->resolveTools();
    }

    public function getProvider(): AIProviderInterface
    {
        return $this->provider;
    }

    public function getChatHistory(): ChatHistory
    {
        return $this->history;
    }

    public function getInstructions(): SystemMessage
    {
        return $this->instructions;
    }

    public function restoreState(WorkflowState $state): WorkflowState
    {
        $state = parent::restoreState($state);
        if ($state instanceof AgentState && isset($state->request)) {
            $state->request->tools = $this->getTools();
        }
        return $state;
    }

    /** @return array<ToolInterface|ProviderToolInterface> */
    public function getTools(): array
    {
        return $this->resolvedTools;
    }

    /** @return array<ToolInterface|ProviderToolInterface> */
    protected function resolveTools(): array
    {

        $guidelines = [];

        foreach ($this->configuredTools as $tool) {
            if ($tool instanceof ToolkitInterface) {
                $kitGuidelines = $tool->guidelines();
                if ($kitGuidelines !== null && $kitGuidelines !== '') {
                    $name = (new ReflectionClass($tool))->getShortName();
                    $kitGuidelines = '# '.$name.PHP_EOL.$kitGuidelines;
                }
                $innerTools = $tool->tools();
                $this->resolvedTools = array_merge($this->resolvedTools, $innerTools);

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
                $this->resolvedTools[] = $tool;
            }
        }

        $blocks = $this->instructions->getContentBlocks();

        if ($guidelines !== []) {
            $blocks[] = new SystemContent(
                '<TOOLS-GUIDELINES>'.PHP_EOL.implode(PHP_EOL.PHP_EOL, $guidelines).PHP_EOL.'</TOOLS-GUIDELINES>'
            );
        }

        $this->instructions = new SystemMessage($blocks);

        return $this->resolvedTools;
    }
}
