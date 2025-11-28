<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\Exceptions\ProviderException;
use Psr\Http\Message\StreamInterface;

class SSEParser
{
    public static function parseNextSSEEvent(StreamInterface $stream): ?array
    {
        $line = static::readLine($stream);

        if (! \str_starts_with($line, 'data:')) {
            return null;
        }

        $line = \trim(\substr($line, \strlen('data: ')));

        // Handle SSE end-of-stream sentinel used by some providers (e.g., OpenAI): [DONE]
        // This is not JSON and should be ignored by the parser.
        if ($line === '[DONE]') {
            return null;
        }

        try {
            return \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            // Generic message because the parser is used by multiple providers
            throw new ProviderException('SSE streaming JSON decode error - '.$exception->getMessage());
        }
    }

    public static function readLine(StreamInterface $stream): string
    {
        $buffer = '';

        while (! $stream->eof()) {
            if ('' === ($byte = $stream->read(1))) {
                return $buffer;
            }
            $buffer .= $byte;
            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }
}
