<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Supadata;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function json_decode;

/**
 * @method static static make(string $key)
 */
class SupadataYoutubeChannelTool extends Tool
{
    use HttpClient;

    protected string $name = 'get_youtube_channel_metadata';

    protected ?string $description = 'Retrieve metadata from a YouTube channel including name, description, subscriber count, and more.';

    public function __construct(protected string $key)
    {
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
        $response = $this->getClient($this->key)
            ->get('youtube/channel?id='.$channel);

        return json_decode((string) $response->getBody(), true);
    }
}
