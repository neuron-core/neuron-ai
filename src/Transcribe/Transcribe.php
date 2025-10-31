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

    public function transcribeAudio(AudioContent $audio): string
    {
        if ($audio->sourceType->value === SourceType::URL) {
            return $this->resolveTranscribeProvider()->trascribe($audio->source);
        }
        throw new ContentBlocksException('Unsupported audio source');
    }
}
