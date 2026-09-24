<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Supadata;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * @method static static make(string $key, ?HttpClientInterface $httpClient = null)
 * @deprecated The Supadata toolkit will be removed in the next major version.
 */
class SupadataYouTubeToolkit extends AbstractToolkit
{
    public function __construct(
        protected string $key,
        protected ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function guidelines(): ?string
    {
        return <<<GUIDELINES
            This toolkit provides access to YouTube video transcriptions, metadata, channel information,
            and playlist data through Supadata.ai for content analysis and research purposes. When users request
            YouTube content analysis, always begin by retrieving video metadata to understand context and availability
            before requesting more resource-intensive contents like transcriptions. Use transcriptions selectively only when detailed
            content analysis is required, and cross-reference multiple data points including titles, descriptions,
            and channel information for comprehensive insights. Be aware that automated transcriptions may contain
            errors and not all videos have transcripts available, so acknowledge these limitations when summarizing content.
            GUIDELINES;
    }

    public function provide(): array
    {
        return [
            SupadataVideoMetadataTool::make($this->key, $this->httpClient),
            SupadataVideoTranscriptTool::make($this->key, $this->httpClient),
            SupadataYoutubeChannelTool::make($this->key, $this->httpClient),
            SupadataYoutubePlaylistTool::make($this->key, $this->httpClient),
        ];
    }
}
