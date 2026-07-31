<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Console;

use NeuronAI\Console\NeuronCli;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function ob_get_clean;
use function ob_start;
use function ob_end_clean;

class NeuronCliTest extends TestCase
{
    public function testNoArgumentsPrintsUsageAndFails(): void
    {
        ob_start();
        $exitCode = (new NeuronCli())->run(['neuron']);
        $output = (string) ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Usage:', $output);
    }

    public function testHelpReturnsZero(): void
    {
        ob_start();
        $exitCode = (new NeuronCli())->run(['neuron', '--help']);
        ob_end_clean();

        $this->assertSame(0, $exitCode);
    }

    public function testUnknownCommandFails(): void
    {
        ob_start();
        $exitCode = (new NeuronCli())->run(['neuron', 'does-not-exist']);
        ob_end_clean();

        $this->assertSame(1, $exitCode);
    }

    public function testUsageListsEveryRegisteredCommand(): void
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
