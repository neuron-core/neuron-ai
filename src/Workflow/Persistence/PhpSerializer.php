<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Exceptions\PersistenceException;

use function serialize;
use function unserialize;

/** Native PHP object serialization; transport encoding belongs to persistence. */
class PhpSerializer implements Serializer
{
    public function serialize(mixed $value): string
    {
        return serialize($value);
    }

    public function unserialize(string $data): mixed
    {
        $value = @unserialize($data);
        if ($value === false && $data !== serialize(false)) {
            throw new PersistenceException('Unable to unserialize persisted Workflow value.');
        }

        return $value;
    }
}
