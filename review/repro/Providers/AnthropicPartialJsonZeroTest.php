<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Anthropic;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

class AnthropicPartialJsonZeroTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    public function test_a_partial_json_fragment_of_zero_is_not_dropped(): void
    {
        $events = [['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'counter', 'input' => []]]];
        foreach (['{"count": ', '0', '}'] as $fragment) {
            $events[] = ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $fragment]];
        }
        $events[] = ['type' => 'content_block_stop', 'index' => 0];
        $provider = new Anthropic('key', 'model', httpClient: $this->recordingClient(new Response(200, body: self::sseBody($events))));
        $provider->setTools([new ToolStub('counter')]);

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Count')));

        $this->assertSame(['count' => 0], $message->getToolCalls()[0]->getInputs());
    }
}
