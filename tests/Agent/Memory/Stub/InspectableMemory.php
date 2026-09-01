<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Memory\Stub;

use NeuronAI\Agent\Memory\MemoryInterface;

class InspectableMemory implements MemoryInterface
{
    /** @var string[] */
    public array $recalls = [];

    /** @var array<int, array{string, string, string}> */
    public array $remembered = [];

    /** @param string[] $recalled */
    public function __construct(protected array $recalled = [])
    {
    }

    public function recall(string $query): array
    {
        $this->recalls[] = $query;

        return $this->recalled;
    }

    public function remember(string $threadId, string $user, string $assistant): void
    {
        $this->remembered[] = [$threadId, $user, $assistant];
    }

    public function forget(string $threadId): void
    {
    }
}
