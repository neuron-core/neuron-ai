<?php

namespace NeuronAI\Tests\Providers;

use NeuronAI\Chat\Messages\Image;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Ollama\MessageMapper;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

class OllamaMessageMapperTest extends TestCase
{
    public function test_tool_call_message_mapping()
    {
        $message = new ToolCallMessage('', [Tool::make('test', 'tool with no properties')]);
        $message->addMetadata('tool_calls', [['function' => ['name' => 'test', 'arguments' => []]]]);

        $mapper = new MessageMapper();

        $this->assertEquals([[
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [
                ['function' => ['name' => 'test', 'arguments' => new \stdClass()]],
            ]
        ]], $mapper->map([$message]));
    }
}
