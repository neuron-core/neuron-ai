<?php

declare(strict_types=1);

namespace NeuronAI\Transcribe;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Exceptions\ContentBlocksException;

class Transcribe extends Agent
{
    use ResolveTranscribeProvider;

    /**
     * [Source audio] Speech to text.
     *
     * @param AudioContent $audio
     * @return string
     * @throws ContentBlocksException
     */
    public function transcribeAudio(AudioContent $audio): string
    {
        if ($audio->sourceType->value === SourceType::URL) {
            return $this->resolveTranscribeProvider()->trascribe($audio->source);
        }
        throw new ContentBlocksException('Unsupported audio source');
    }
}
