<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Workflow\Executor\StepResult;

use function base64_decode;
use function base64_encode;
use function serialize;
use function unserialize;

/**
 * Default Serializer: native PHP serialize() wrapped in base64 so the blob is
 * safe for TEXT/JSON columns. On read it tolerates a value stored without
 * base64 (falls back to the raw string) to stay compatible with pre-existing rows.
 */
class PhpSerializer implements Serializer
{
    public function serialize(StepResult $result): string
    {
        return base64_encode(serialize($result));
    }

    public function unserialize(string $data): StepResult
    {
        $decoded = base64_decode($data, true);

        if ($decoded === false) {
            $decoded = $data;
        }

        return unserialize($decoded);
    }
}
