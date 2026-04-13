<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

class CallableMcpTool
{
    public function __construct(
        protected McpConnector $connector,
        protected array $item
    ) {
    }

    public function __invoke(...$arguments): mixed
    {
        return $this->connector->invokeTool(
            item: $this->item,
            arguments: $arguments
        );
    }
}
