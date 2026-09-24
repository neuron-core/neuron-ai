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
class SupadataVideoMetadataTool extends Tool
{
    use HttpClient;

    protected string $name = 'get_video_metadata';

    protected ?string $description = 'Retrieve the metadata of a youtube video.';

    public function __construct(protected string $key, ?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'video',
                type: PropertyType::STRING,
                description: 'The URL or the ID of the YouTube video you want to retrieve the metadata.',
                required: true
            ),
        ];
    }

    public function __invoke(string $video): array
    {
        $response = $this->get('youtube/video?id=' . $video);

        return $response->json();
    }
}
