<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence\Stub;

use NeuronAI\Workflow\Persistence\InMemoryPersistence;

/** In-memory backend that counts the reads the engine asks it for. */
class CountingPersistence extends InMemoryPersistence
{
    public int $reads = 0;

    public function get(string $partition, string $key): ?string
    {
        $this->reads++;

        return parent::get($partition, $key);
    }
}
