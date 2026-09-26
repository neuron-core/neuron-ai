<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Stub;

use NeuronAI\Tools\Tool;

use function getmypid;

/**
 * Answers with the id of the process that executed it, to tell forked runs apart.
 */
class ProcessIdTool extends Tool
{
    protected string $name = 'process_id';

    public function __invoke(): string
    {
        return (string) getmypid();
    }
}
