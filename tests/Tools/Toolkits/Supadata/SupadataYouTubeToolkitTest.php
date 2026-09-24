<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Supadata;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Supadata\SupadataYouTubeToolkit;
use PHPUnit\Framework\TestCase;

use function json_encode;

class SupadataYouTubeToolkitTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_tools_send_their_requests_through_the_injected_client(): void
    {
        $client = $this->recordingClient(
            new Response(200, [], json_encode(['title' => 'Video'])),
            new Response(200, [], json_encode(['content' => 'Transcript'])),
            new Response(200, [], json_encode(['name' => 'Channel'])),
            new Response(200, [], json_encode(['title' => 'Playlist'])),
        );
        [$metadata, $transcript, $channel, $playlist] = SupadataYouTubeToolkit::make('supadata-key', httpClient: $client)->tools();

        $metadata->setInputs(['video' => 'abc'])->execute();
        $transcript->setInputs(['video_url' => 'abc'])->execute();
        $channel->setInputs(['channel' => 'neuron'])->execute();
        $playlist->setInputs(['playlist' => 'list'])->execute();

        $this->assertSame([
            'GET https://api.supadata.ai/v1/youtube/video?id=abc',
            'GET https://api.supadata.ai/v1/youtube/transcript?url=abc&text=true',
            'GET https://api.supadata.ai/v1/youtube/channel?id=neuron',
            'GET https://api.supadata.ai/v1/youtube/playlist?id=list',
        ], $this->sentTargets());
        foreach ($this->sentRequests as $entry) {
            $this->assertSame('supadata-key', $entry['request']->getHeaderLine('x-api-key'));
        }
        $this->assertSame('Transcript', (string) $transcript->getResult());
    }
}
