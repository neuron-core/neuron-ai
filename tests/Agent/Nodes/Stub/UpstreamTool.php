<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes\Stub;

use NeuronAI\Tools\Tool;

class UpstreamTool extends Tool
{
    protected string $name = 'upstream';

    protected ?string $description = 'Calls an upstream service';

    public function __invoke(): string
    {
        throw new UpstreamException(['status' => 503]);
    }
}
