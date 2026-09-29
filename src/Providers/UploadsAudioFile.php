<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Exceptions\ProviderException;

use function base64_decode;
use function basename;
use function explode;
use function fopen;
use function fwrite;
use function rewind;
use function strtolower;

/**
 * Speech-to-text APIs take the audio as a multipart file and recognise its
 * format from the filename. A path is opened as is; base64 audio is decoded
 * into a temporary stream named after its media type.
 */
trait UploadsAudioFile
{
    /**
     * @return array{contents: resource, filename: string}
     * @throws ProviderException
     */
    protected function audioFilePart(AudioContent $audio): array
    {
        return match ($audio->sourceType) {
            SourceType::URL => $this->openAudioFile($audio->content),
            SourceType::BASE64 => $this->decodeAudio($audio),
            default => throw new ProviderException("Audio must be a file path or base64, not {$audio->sourceType->value}."),
        };
    }

    /**
     * @return array{contents: resource, filename: string}
     * @throws ProviderException
     */
    protected function openAudioFile(string $path): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new ProviderException("Cannot open the audio file: {$path}");
        }

        return ['contents' => $handle, 'filename' => basename($path)];
    }

    /**
     * @return array{contents: resource, filename: string}
     * @throws ProviderException
     */
    protected function decodeAudio(AudioContent $audio): array
    {
        if ($audio->mediaType === null) {
            throw new ProviderException('Base64 audio needs a media type, such as audio/wav, to name its format.');
        }

        $bytes = base64_decode($audio->content, true);

        if ($bytes === false) {
            throw new ProviderException('The audio is not valid base64.');
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $bytes);
        rewind($handle);

        return ['contents' => $handle, 'filename' => 'audio.' . $this->audioExtension($audio->mediaType)];
    }

    protected function audioExtension(string $mediaType): string
    {
        $subtype = strtolower(explode('/', $mediaType)[1] ?? $mediaType);

        return match ($subtype) {
            'mpeg', 'mp3' => 'mp3',
            'wav', 'x-wav', 'wave', 'vnd.wave' => 'wav',
            'x-m4a', 'm4a' => 'm4a',
            'x-flac' => 'flac',
            default => $subtype,
        };
    }
}
