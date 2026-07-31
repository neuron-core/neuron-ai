<?php

declare(strict_types=1);

namespace NeuronAI\Console;

use function fwrite;

use const PHP_EOL;
use const STDERR;

abstract class Command
{
    /**
     * @param array<string> $args
     */
    abstract public function run(array $args): int;

    protected function printError(string $message): void
    {
        fwrite(STDERR, "Error: {$message}" . PHP_EOL);
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
