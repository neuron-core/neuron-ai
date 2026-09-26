<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI\Responses;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

class ResponsesFunctionCallTextTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_chat_keeps_the_text_and_reasoning_that_accompany_function_calls(): void
    {
        $body = '{"status":"completed","output":['
            .'{"type":"reasoning","id":"rs_1","summary":[{"type":"summary_text","text":"Need the weather tool."}]},'
            .'{"type":"message","content":[{"type":"output_text","text":"Let me check the weather."}]},'
            .'{"type":"function_call","id":"fc_1","call_id":"call_1","name":"weather","arguments":"{}"}]}';
        $provider = new OpenAIResponses('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body)));
        $provider->setTools([new ToolStub('weather')]);

        $message = $provider->chat(new UserMessage('Weather?'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('call_1', $message->getToolCalls()[0]->getCallId());
        $blocks = $message->getContentBlocks();
        $this->assertCount(2, $blocks);
        $this->assertInstanceOf(ReasoningContent::class, $blocks[0]);
        $this->assertSame('Need the weather tool.', $blocks[0]->content);
        $this->assertInstanceOf(TextContent::class, $blocks[1]);
        $this->assertSame('Let me check the weather.', $message->getContent());
    }
}
