<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Concurrency;

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Tools\ToolInterface;
use Spatie\Fork\Fork;

/**
 * Implemented using spatie/fork
 *
 * For more information you can check the GitHub repository of the package:
 * https://github.com/spatie/fork
 *
 * It requires the pcntl extension which is installed in many Unix and Mac systems by default.
 *
 * ❗️ pcntl only works in CLI processes, not in a web context.
 *
 * @phpstan-ignore trait.unused
 */
trait ConcurrentToolCalls
{
    protected function executeTools(ToolCallMessage $toolCallMessage): ToolCallResultMessage
    {
        // Fallback to the original implementation if pcntl is not available (e.g. Windows).
        if (!\extension_loaded('pcntl')) {
            return parent::executeTools($toolCallMessage);
        }

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
