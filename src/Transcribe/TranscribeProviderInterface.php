<?php

declare(strict_types=1);

namespace NeuronAI\Transcribe;

interface TranscribeProviderInterface
{
    public function trascribe(string $filePath): string;
}
