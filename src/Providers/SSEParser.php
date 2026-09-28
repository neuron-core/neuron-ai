<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\StreamInterface;
use Throwable;

use function is_array;
use function json_decode;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

class SSEParser
{
    public static function parseNextSSEEvent(StreamInterface $stream): ?array
    {
        $line = $stream->readLine();

        if (! str_starts_with($line, 'data:')) {
            $event = json_decode($line, true);

            return is_array($event) && $event !== [] ? $event : null;
        }

        // The space after the colon is optional
        $line = trim(substr($line, strlen('data:')));

        // Only the exact sentinel ends the stream: a payload may well contain the word DONE
        if ($line === '[DONE]') {
            return null;
        }

        try {
            $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new ProviderException('Streaming error - '.$exception->getMessage(), $exception->getCode(), $exception);
        }

        // A bare keep-alive such as 1 or "ping" carries no event
        return is_array($event) ? $event : null;
    }
}
