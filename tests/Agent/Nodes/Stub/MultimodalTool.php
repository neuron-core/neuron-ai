<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes\Stub;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;

class MultimodalTool extends Tool
{
    public static int $executions = 0;

    protected string $name = 'multimodal_tool';

    protected ?string $description = 'Returns a multimodal result';

    public function __invoke(): ToolOutput
    {
        self::$executions++;

        return new ToolOutput([
            new TextContent('the chart'),
            new ImageContent('base64data', SourceType::BASE64, 'image/png'),
        ]);
    }
}
