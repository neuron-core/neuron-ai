<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Console;

use NeuronAI\Console\Command;
use NeuronAI\Console\NeuronCli;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function ob_get_clean;
use function ob_start;
use function ob_end_clean;
use function fopen;
use function rewind;
use function stream_get_contents;

class NeuronCliTest extends TestCase
{
    public function test_no_arguments_prints_usage_and_fails(): void
    {
        ob_start();
        $exitCode = (new NeuronCli())->run(['neuron']);
        $output = (string) ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Usage:', $output);
    }

    public function test_help_returns_zero(): void
    {
        ob_start();
        $exitCode = (new NeuronCli())->run(['neuron', '--help']);
        ob_end_clean();

        $this->assertSame(0, $exitCode);
    }

    public function test_unknown_command_fails(): void
    {
        $cli = new NeuronCli();
        $stream = $this->captureErrorStream($cli);

        ob_start();
        $exitCode = $cli->run(['neuron', 'does-not-exist']);
        ob_end_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown command: does-not-exist', $this->readStream($stream));
    }

    /**
     * Redirect a command's error output to an in-memory stream.
     *
     * @return resource
     */
    private function captureErrorStream(Command $command): mixed
    {
        /** @var resource $stream */
        $stream = fopen('php://memory', 'r+');
        $command->setErrorStream($stream);
        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function readStream(mixed $stream): string
    {
        rewind($stream);
        return (string) stream_get_contents($stream);
    }

    public function test_usage_lists_every_registered_command(): void
    {
        $cli = new NeuronCli();

        ob_start();
        $cli->run(['neuron', '--help']);
        $output = (string) ob_get_clean();

        foreach (array_keys($cli->commands()) as $commandName) {
            $this->assertStringContainsString($commandName, $output);
        }
    }
}
