<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Console;

use Closure;
use NeuronAI\Console\Command;
use NeuronAI\Console\Evaluation\EvaluationCommand;
use NeuronAI\Console\Make\MakeCommand;
use NeuronAI\Console\NeuronCli;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_keys;
use function fopen;
use function ob_get_clean;
use function ob_start;
use function preg_quote;
use function rewind;
use function stream_get_contents;

use const PHP_EOL;

class NeuronCliTest extends TestCase
{
    /** @var resource */
    protected mixed $errorStream;

    protected function setUp(): void
    {
        /** @var resource $stream */
        $stream = fopen('php://memory', 'r+');
        $this->errorStream = $stream;
    }

    public function test_no_arguments_prints_usage_and_fails(): void
    {
        [$exitCode, $output] = $this->runCli(new NeuronCli(), 'neuron');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Usage: neuron <command> [options]', $output);
        $this->assertSame('', $this->errors());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function helpFlags(): iterable
    {
        yield ['--help'];
        yield ['-h'];
    }

    #[DataProvider('helpFlags')]
    public function test_help_prints_usage_and_succeeds(string $flag): void
    {
        [$exitCode, $output] = $this->runCli(new NeuronCli(), 'neuron', $flag);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Usage: neuron <command> [options]', $output);
        $this->assertSame('', $this->errors());
    }

    public function test_unknown_command_reports_the_error_and_prints_usage(): void
    {
        [$exitCode, $output] = $this->runCli(new NeuronCli(), 'neuron', 'does-not-exist');

        $this->assertSame(1, $exitCode);
        $this->assertSame('Error: Unknown command: does-not-exist' . PHP_EOL, $this->errors());
        $this->assertStringContainsString('Available Commands:', $output);
    }

    public function test_usage_lists_every_registered_command_with_its_description(): void
    {
        $cli = new NeuronCli();

        [, $output] = $this->runCli($cli, 'neuron', '--help');

        foreach ($cli->commands() as $name => [$description]) {
            $this->assertMatchesRegularExpression('/^  ' . preg_quote($name, '/') . ' +' . preg_quote($description, '/') . '$/m', $output);
        }
    }

    public function test_registry_exposes_the_documented_commands(): void
    {
        $commands = (new NeuronCli())->commands();

        $this->assertSame([
            'evaluation',
            'make:agent',
            'make:middleware',
            'make:node',
            'make:tool',
            'make:rag',
            'make:workflow',
            'make:event',
            'make:evaluators',
        ], array_keys($commands));
        $this->assertInstanceOf(EvaluationCommand::class, $commands['evaluation'][1]());
        foreach (array_keys($commands) as $name) {
            if ($name !== 'evaluation') {
                $this->assertInstanceOf(MakeCommand::class, $commands[$name][1]());
            }
        }
    }

    public function test_dispatches_the_remaining_arguments_to_the_sub_command(): void
    {
        $received = [];
        $cli = $this->cliWith(static function (array $args) use (&$received): int {
            $received = $args;
            return 7;
        });

        [$exitCode] = $this->runCli($cli, 'bin/neuron', 'fake', 'first', '--flag=value');

        $this->assertSame(7, $exitCode);
        $this->assertSame(['neuron', 'first', '--flag=value'], $received);
    }

    public function test_sub_commands_inherit_the_error_stream(): void
    {
        [$exitCode] = $this->runCli(new NeuronCli(), 'neuron', 'make:agent');

        $this->assertSame(1, $exitCode);
        $this->assertSame('Error: Class name argument is required' . PHP_EOL, $this->errors());
    }

    public function test_a_throwing_sub_command_is_reported_and_fails(): void
    {
        $cli = $this->cliWith(static fn (array $args): int => throw new RuntimeException('exploded'));

        [$exitCode] = $this->runCli($cli, 'neuron', 'fake');

        $this->assertSame(1, $exitCode);
        $this->assertSame("Error: Error executing command 'fake': exploded" . PHP_EOL, $this->errors());
    }

    /**
     * @param Closure(array<string>): int $run
     */
    protected function cliWith(Closure $run): NeuronCli
    {
        $command = new class ($run) extends Command {
            public function __construct(protected Closure $handler)
            {
            }

            public function run(array $args): int
            {
                return ($this->handler)($args);
            }
        };

        return new class ($command) extends NeuronCli {
            public function __construct(protected Command $command)
            {
            }

            public function commands(): array
            {
                return ['fake' => ['A fake command', fn (): Command => $this->command]];
            }
        };
    }

    /**
     * @return array{int, string} The exit code and the standard output.
     */
    protected function runCli(NeuronCli $cli, string ...$argv): array
    {
        $cli->setErrorStream($this->errorStream);

        ob_start();
        $exitCode = $cli->run($argv);

        return [$exitCode, (string) ob_get_clean()];
    }

    protected function errors(): string
    {
        rewind($this->errorStream);
        return (string) stream_get_contents($this->errorStream);
    }
}
