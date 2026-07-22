<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Workflow\Executor\StepResult;

use function base64_decode;
use function base64_encode;
use function function_exists;
use function igbinary_serialize;
use function igbinary_unserialize;

/**
 * Serializer backed by the igbinary extension: smaller and faster than native
 * PHP serialize for repetitive object graphs (chat history, nested state), at
 * the cost of requiring ext-igbinary. Output is binary, so it is still base64-
 * wrapped to stay safe in TEXT/JSON columns — same transport layer as PhpSerializer.
 *
 * Not interchangeable with PhpSerializer: a blob written by one codec cannot be
 * read by the other, so swapping serializers on a live store is a one-way
 * migration (drain in-flight runs or ship a double-read cutover).
 */
class IgbinarySerializer implements Serializer
{
    public function __construct()
    {
        if (!function_exists('igbinary_serialize')) {
            throw new \LogicException(
                'IgbinarySerializer requires the igbinary PHP extension.',
            );
        }
    }

    public function serialize(StepResult $result): string
    {
        return base64_encode(igbinary_serialize($result));
    }

    public function unserialize(string $data): StepResult
    {
        $decoded = base64_decode($data, true);

        if ($decoded === false) {
            $decoded = $data;
        }

        return igbinary_unserialize($decoded);
    }
}
