<?php

declare(strict_types=1);

namespace NeuronAI\Console;

use function fwrite;

use const PHP_EOL;
use const STDERR;

abstract class Command
{
    /**
     * Stream errors are written to. Injectable so tests (and other callers)
     * can capture error output instead of letting it leak to the real STDERR.
     *
     * @var resource
     */
    protected mixed $errorStream = STDERR;

    /**
     * @param array<string> $args
     */
    abstract public function run(array $args): int;

    /**
     * Redirect where this command writes error output.
     *
     * @param resource $stream
     */
    public function setErrorStream(mixed $stream): static
    {
        $this->errorStream = $stream;
        return $this;
    }

    protected function printError(string $message): void
    {
        fwrite($this->errorStream, "Error: {$message}" . PHP_EOL);
    }

    protected function printWarning(string $message): void
    {
        echo "Warning: {$message}" . PHP_EOL;
    }

    protected function printSuccess(string $message): void
    {
        echo "Success: {$message}" . PHP_EOL;
    }
}
