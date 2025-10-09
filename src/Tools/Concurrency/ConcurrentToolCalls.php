<?php

namespace NeuronAI\Tools\Concurrency;

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Tools\ToolInterface;
use Spatie\Fork\Fork;

trait ConcurrentToolCalls
{
    protected function executeTools(ToolCallMessage $toolCallMessage): ToolCallResultMessage
    {
        $toolCallResult = new ToolCallResultMessage($toolCallMessage->getTools());

        Fork::new()->run(
            ...\array_map(
                fn (ToolInterface $tool): \Closure => fn () => $this->executeSingleTool($tool),
                $toolCallResult->getTools()
            )
        );

        return $toolCallResult;
    }
}
