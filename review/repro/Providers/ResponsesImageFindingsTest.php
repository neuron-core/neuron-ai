<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI\Responses;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

class ResponsesImageFindingsTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    public function test_chat_keeps_the_generated_image(): void
    {
        $body = '{"status":"completed","output":[{"type":"image_generation_call","id":"ig_1","status":"completed","result":"RklOQUw="}]}';
        $provider = new OpenAIResponses('sk-test', 'gpt-test', httpClient: $this->recordingClient(new Response(200, body: $body)));

        $message = $provider->chat(new UserMessage('Draw a fox'))->message();

        $this->assertSame('RklOQUw=', $message->getImage()?->content);
    }

    public function test_stream_keeps_the_final_generated_image_instead_of_concatenating_partials(): void
    {
        $body = self::sseBody([
            ['type' => 'response.image_generation_call.generating', 'item_id' => 'ig_1'],
            ['type' => 'response.image_generation_call.partial_image', 'item_id' => 'ig_1', 'partial_image_index' => 0, 'partial_image_b64' => 'UEFSVDE='],
            ['type' => 'response.image_generation_call.partial_image', 'item_id' => 'ig_1', 'partial_image_index' => 1, 'partial_image_b64' => 'UEFSVDI='],
            ['type' => 'response.completed', 'response' => ['output' => [['type' => 'image_generation_call', 'id' => 'ig_1', 'status' => 'completed', 'result' => 'RklOQUw=']]]],
        ]);
        $provider = new OpenAIResponses('sk-test', 'gpt-test', httpClient: $this->recordingClient(new Response(200, body: $body)));

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Draw a fox')));

        $this->assertSame('RklOQUw=', $message->getImage()?->content);
    }

    public function test_stream_does_not_concatenate_partial_images_into_one_block(): void
    {
        $body = self::sseBody([
            ['type' => 'response.image_generation_call.generating', 'item_id' => 'ig_1'],
            ['type' => 'response.image_generation_call.partial_image', 'item_id' => 'ig_1', 'partial_image_index' => 0, 'partial_image_b64' => 'UEFSVDE='],
            ['type' => 'response.image_generation_call.partial_image', 'item_id' => 'ig_1', 'partial_image_index' => 1, 'partial_image_b64' => 'UEFSVDI='],
            ['type' => 'response.output_item.added', 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'name' => 'save', 'call_id' => 'call_1', 'arguments' => '']],
            ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_1', 'arguments' => '{}'],
            ['type' => 'response.completed', 'response' => ['output' => []]],
        ]);
        $provider = new OpenAIResponses('sk-test', 'gpt-test', httpClient: $this->recordingClient(new Response(200, body: $body)));
        $provider->setTools([new ToolStub('save')]);

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Draw a fox and save it')));

        $this->assertSame('UEFSVDI=', $message->getImage()?->content);
    }
}
