<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Supadata;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * @method static static make(string $key)
 */
class SupadataVideoTranscriptTool extends Tool
{
    use HttpClient;

    public function __construct(protected string $key)
    {
        parent::__construct(
            'get_transcription',
            'Retrieve the transcription of a youtube video.',
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'video_url',
                type: PropertyType::STRING,
                description: 'The URL of the YouTube video you want to retrieve the transcription for.',
                required: true
            )
        ];
    }

    public function __invoke(string $video_url): string
    {
        $response = $this->getClient($this->key)
            ->get('youtube/transcript?url=' . $video_url.'&text=true')
            ->getBody()
            ->getContents();

        $response = \json_decode($response, true);

        return $response['content'];
    }
}
