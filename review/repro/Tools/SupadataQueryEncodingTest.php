<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Supadata;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Supadata\SupadataVideoMetadataTool;
use NeuronAI\Tools\Toolkits\Supadata\SupadataVideoTranscriptTool;
use NeuronAI\Tools\Toolkits\Supadata\SupadataYoutubeChannelTool;
use NeuronAI\Tools\Toolkits\Supadata\SupadataYoutubePlaylistTool;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function parse_str;

class SupadataQueryEncodingTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_a_youtube_url_with_its_own_query_string_is_sent_as_one_parameter(): void
    {
        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42';
        $tool = new SupadataVideoTranscriptTool('supadata-key', $this->recordingClient(new Response(200, [], json_encode(['content' => 'x']))));

        $tool($url);

        parse_str($this->sentRequests[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame(['url' => $url, 'text' => 'true'], $query);
    }

    public function test_a_model_supplied_url_cannot_override_the_text_parameter(): void
    {
        $tool = new SupadataVideoTranscriptTool('supadata-key', $this->recordingClient(new Response(200, [], json_encode(['content' => 'x']))));

        $tool('x&text=false');

        parse_str($this->sentRequests[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame(['url' => 'x&text=false', 'text' => 'true'], $query);
    }

    /**
     * @return iterable<string, array{class-string<Tool>}>
     */
    public static function idTools(): iterable
    {
        yield 'video metadata' => [SupadataVideoMetadataTool::class];
        yield 'channel' => [SupadataYoutubeChannelTool::class];
        yield 'playlist' => [SupadataYoutubePlaylistTool::class];
    }

    /**
     * @param class-string<Tool> $toolClass
     */
    #[DataProvider('idTools')]
    public function test_a_model_supplied_id_cannot_inject_extra_query_parameters(string $toolClass): void
    {
        $tool = new $toolClass('supadata-key', $this->recordingClient(new Response(200, [], json_encode([]))));

        $tool('abc&lang=fr#frag');

        parse_str($this->sentRequests[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame(['id' => 'abc&lang=fr#frag'], $query);
    }
}
