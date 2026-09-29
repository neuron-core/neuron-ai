<?php

declare(strict_types=1);

namespace NeuronAI\RAG\DataLoader;

interface ReaderInterface
{
    public function read(string $filePath): string;
}
