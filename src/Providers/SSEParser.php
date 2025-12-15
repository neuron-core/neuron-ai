<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\StreamInterface;
use Throwable;

use function json_decode;
use function mb_strlen;
use function str_contains;
use function str_starts_with;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

class SSEParser
{
    public static function parseNextSSEEvent(StreamInterface $stream): ?array
    {
        $line = $stream->readLine();

        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $line = trim(substr($line, mb_strlen('data: ')));

        if (str_contains($line, 'DONE')) {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new ProviderException('Streaming error - '.$exception->getMessage());
        }
    }
}
