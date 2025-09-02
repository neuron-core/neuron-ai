<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Supadata;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * @method static static make(string $key)
 */
class SupadataYoutubeChannelTool extends Tool
{
    use HttpClient;

    public function __construct(protected string $key)
    {
        parent::__construct(
            'get_youtube_channel_metadata',
            'Retrieve metadata from a YouTube channel including name, description, subscriber count, and more.',
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'channel',
                type: PropertyType::STRING,
                description: 'YouTube channel URL or ID',
                required: true
            )
        ];
    }

    public function __invoke(string $channel): array
    {
        $response = $this->getClient($this->key)
            ->get('youtube/channel?id='.$channel)
            ->getBody()
            ->getContents();

        return \json_decode($response, true);
    }
}
