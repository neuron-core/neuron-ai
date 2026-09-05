<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use LogicException;
use NeuronAI\Exceptions\PersistenceException;

use function function_exists;
use function igbinary_serialize;
use function igbinary_unserialize;

/**
 * Binary igbinary serialization; requires ext-igbinary. Keep the configured
 * serializer stable for in-flight runs. Transport encoding belongs to persistence.
 */
class IgbinarySerializer implements Serializer
{
    public function __construct()
    {
        if (!function_exists('igbinary_serialize')) {
            throw new LogicException(
                'IgbinarySerializer requires the igbinary PHP extension.',
            );
        }
    }

    public function serialize(mixed $value): string
    {
        return (string) igbinary_serialize($value);
    }

    public function unserialize(string $data): mixed
    {
        $value = @igbinary_unserialize($data);
        if (
            ($value === null && $data !== igbinary_serialize(null))
            || ($value === false && $data !== igbinary_serialize(false))
        ) {
            throw new PersistenceException('Unable to unserialize persisted Workflow value.');
        }

        return $value;
    }
}
