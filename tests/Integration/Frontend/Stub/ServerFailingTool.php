<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Integration\Frontend\Stub;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;

/** A backend tool whose execution reports a failure, to observe error results on the wire. */
class ServerFailingTool extends Tool
{
    protected string $name = 'server_fail';

    protected ?string $description = 'A backend operation that fails.';

    public function __invoke(): ToolOutput
    {
        return ToolOutput::error('clock unavailable');
    }
}
