<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Supadata;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * @method static static make(string $key)
 */
class SupadataVideoMetadataTool extends Tool
{
    use HttpClient;

    public function __construct(protected string $key)
    {
        parent::__construct(
            'get_video_metadata',
            'Retrieve the metadata of a youtube video.',
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'video',
                type: PropertyType::STRING,
                description: 'The URL or the ID of the YouTube video you want to retrieve the metadata.',
                required: true
            )
        ];
    }

    public function __invoke(string $video): array
    {
        $response = $this->getClient($this->key)
            ->get('youtube/video?id=' . $video)
            ->getBody()
            ->getContents();

        return \json_decode($response, true);
    }
}
