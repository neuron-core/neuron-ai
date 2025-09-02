<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Supadata;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * @method static static make(string $key)
 */
class SupadataYoutubePlaylistTool extends Tool
{
    use HttpClient;

    public function __construct(protected string $key)
    {
        parent::__construct(
            'get_youtube_playlist_metadata',
            'Retrieve metadata from a YouTube playlist including title, description, video count, and more.',
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'playlist',
                type: PropertyType::STRING,
                description: 'YouTube playlist URL or ID',
                required: true
            )
        ];
    }

    public function __invoke(string $playlist): array
    {
        $response = $this->getClient($this->key)
            ->get('youtube/playlist?id='.$playlist)
            ->getBody()
            ->getContents();

        return \json_decode($response, true);
    }
}
