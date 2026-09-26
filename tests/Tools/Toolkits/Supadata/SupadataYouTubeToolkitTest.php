<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Supadata;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Tests\Support\AssertsApiKeyConfinement;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Supadata\SupadataVideoMetadataTool;
use NeuronAI\Tools\Toolkits\Supadata\SupadataVideoTranscriptTool;
use NeuronAI\Tools\Toolkits\Supadata\SupadataYoutubeChannelTool;
use NeuronAI\Tools\Toolkits\Supadata\SupadataYouTubeToolkit;
use NeuronAI\Tools\Toolkits\Supadata\SupadataYoutubePlaylistTool;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_combine;
use function array_map;
use function json_encode;

class SupadataYouTubeToolkitTest extends TestCase
{
    use AssertsApiKeyConfinement;
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
            $this->assertApiKeyTravelsOnlyIn('x-api-key', 'supadata-key', $entry['request']);
        }
        $this->assertSame('Transcript', (string) $transcript->getResult());
    }

    public function test_the_toolkit_exposes_each_tool_with_its_required_input(): void
    {
        $tools = SupadataYouTubeToolkit::make('supadata-key', $this->recordingClient())->tools();

        $this->assertSame(
            [
                'get_video_metadata' => ['video'],
                'get_transcription' => ['video_url'],
                'get_youtube_channel_metadata' => ['channel'],
                'get_youtube_playlist_metadata' => ['playlist'],
            ],
            array_combine(
                array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools),
                array_map(static fn (ToolInterface $tool): array => $tool->getRequiredProperties(), $tools),
            )
        );
    }

    /**
     * @return array<string, array{class-string<SupadataVideoMetadataTool|SupadataYoutubeChannelTool|SupadataYoutubePlaylistTool>, string}>
     */
    public static function metadataTools(): array
    {
        return [
            'video' => [SupadataVideoMetadataTool::class, 'youtube/video?id=dQw4w9WgXcQ'],
            'channel' => [SupadataYoutubeChannelTool::class, 'youtube/channel?id=dQw4w9WgXcQ'],
            'playlist' => [SupadataYoutubePlaylistTool::class, 'youtube/playlist?id=dQw4w9WgXcQ'],
        ];
    }

    /**
     * @param class-string<SupadataVideoMetadataTool|SupadataYoutubeChannelTool|SupadataYoutubePlaylistTool> $toolClass
     */
    #[DataProvider('metadataTools')]
    public function test_metadata_tools_return_the_decoded_payload_unchanged(string $toolClass, string $endpoint): void
    {
        $payload = ['id' => 'dQw4w9WgXcQ', 'title' => 'Ünïcode ☕', 'stats' => ['views' => 42], 'tags' => ['a', 'b']];
        $tool = new $toolClass('supadata-key', $this->recordingClient(new Response(200, [], json_encode($payload))));

        $this->assertSame($payload, $tool('dQw4w9WgXcQ'));
        $this->assertSame(['GET https://api.supadata.ai/v1/'.$endpoint], $this->sentTargets());
        $this->assertSame('application/json', $this->sentRequests[0]['request']->getHeaderLine('Content-Type'));
    }

    public function test_the_transcript_tool_returns_only_the_text_content(): void
    {
        $tool = new SupadataVideoTranscriptTool('supadata-key', $this->recordingClient(
            new Response(200, [], json_encode(['content' => "Line one\nLine two", 'lang' => 'en', 'availableLangs' => ['en']])),
        ));

        $tool->setInputs(['video_url' => 'dQw4w9WgXcQ'])->execute();

        $this->assertSame("Line one\nLine two", $tool->getResult());
    }

    public function test_an_error_response_raises_an_http_exception_without_the_api_key(): void
    {
        $tool = new SupadataVideoMetadataTool('secret-supadata-key', $this->recordingClient(new Response(404, [], '{"error":"video-not-found"}')));

        try {
            $tool('missing');
            $this->fail('Expected an HttpException for a 404 response.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->response?->statusCode);
            $this->assertStringContainsString('video-not-found', $exception->getMessage());
            $this->assertStringNotContainsString('secret-supadata-key', $exception->getMessage());
        }
    }
}
