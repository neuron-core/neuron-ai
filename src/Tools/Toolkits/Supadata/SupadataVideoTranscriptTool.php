<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Supadata;

use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * @method static static make(string $key, ?HttpClientInterface $httpClient = null)
 * @deprecated The Supadata toolkit will be removed in the next major version.
 */
class SupadataVideoTranscriptTool extends Tool
{
    use HttpClient;

    protected string $name = 'get_transcription';

    protected ?string $description = 'Retrieve the transcription of a youtube video.';

    public function __construct(protected string $key, ?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'video_url',
                type: PropertyType::STRING,
                description: 'The URL of the YouTube video you want to retrieve the transcription for.',
                required: true
            ),
        ];
    }

    public function __invoke(string $video_url): string
    {
        $response = $this->get('youtube/transcript?url=' . $video_url.'&text=true');

        $response = $response->json();

        return $response['content'];
    }
}
