<?php

declare(strict_types=1);

namespace NeuronAI\Transcribe;

trait ResolveTranscribeProvider
{
    protected TranscribeProviderInterface $transcribeProvider;

    protected function transcribe(): TranscribeProviderInterface
    {
        return $this->transcribeProvider;
    }

    public function resolveTranscribeProvider(): TranscribeProviderInterface
    {
        if (!isset($this->embeddingsProvider)) {
            $this->transcribeProvider = $this->transcribe();
        }
        return $this->transcribeProvider;
    }
}
