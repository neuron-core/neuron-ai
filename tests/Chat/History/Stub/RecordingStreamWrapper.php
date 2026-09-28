<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History\Stub;

/**
 * A stream wrapper that records every path PHP tries to open through it and opens nothing.
 */
class RecordingStreamWrapper
{
    /** @var string[] */
    public static array $opened = [];

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$opened[] = $path;

        return false;
    }
}
