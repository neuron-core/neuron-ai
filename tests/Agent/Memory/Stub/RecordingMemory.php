<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Memory\Stub;

use NeuronAI\Agent\Memory\MemoryInterface;

class RecordingMemory implements MemoryInterface
{
    /** @var string[] */
    public array $forgottenThreadIds = [];

    public function recall(string $query): array
    {
        return [];
    }

    public function remember(string $threadId, string $user, string $assistant): void
    {
    }

    public function forget(string $threadId): void
    {
        $this->forgottenThreadIds[] = $threadId;
    }
}
