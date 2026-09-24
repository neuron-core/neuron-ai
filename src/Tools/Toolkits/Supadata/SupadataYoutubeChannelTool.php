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
class SupadataYoutubeChannelTool extends Tool
{
    use HttpClient;

    protected string $name = 'get_youtube_channel_metadata';

    protected ?string $description = 'Retrieve metadata from a YouTube channel including name, description, subscriber count, and more.';

    public function __construct(protected string $key, ?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'channel',
                type: PropertyType::STRING,
                description: 'YouTube channel URL or ID',
                required: true
            ),
        ];
    }

    public function __invoke(string $channel): array
    {
        $response = $this->get('youtube/channel?id='.$channel);

        return $response->json();
    }
}
