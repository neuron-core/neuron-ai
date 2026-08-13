<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Supadata;

use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * @method static static make(string $key)
 */
class SupadataYoutubePlaylistTool extends Tool
{
    use HttpClient;

    protected string $name = 'get_youtube_playlist_metadata';

    protected ?string $description = 'Retrieve metadata from a YouTube playlist including title, description, video count, and more.';

    public function __construct(protected string $key)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'playlist',
                type: PropertyType::STRING,
                description: 'YouTube playlist URL or ID',
                required: true
            ),
        ];
    }

    public function __invoke(string $playlist): array
    {
        $response = $this->getClient($this->key)
            ->request(HttpRequest::get('youtube/playlist?id='.$playlist));

        return $response->json();
    }
}
