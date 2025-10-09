<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Concurrency;

use Illuminate\Support\Facades\Concurrency;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Tools\ToolInterface;

/** @phpstan-ignore trait.unused */
trait LaravelConcurrencyToolCalls
{
    protected function executeTools(ToolCallMessage $toolCallMessage): ToolCallResultMessage
    {
        $toolCallResult = new ToolCallResultMessage($toolCallMessage->getTools());

        Concurrency::run(
            \array_map(
                fn (ToolInterface $tool): \Closure => fn () => $this->executeSingleTool($tool),
                $toolCallResult->getTools()
            )
        );

        return $toolCallResult;
    }
}
