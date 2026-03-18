<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Config;

use NeuronAI\Evaluation\Config\EvaluationOutputFactory;
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ReflectionProperty;

use function bin2hex;
use function random_bytes;

class OutputDriverFactoryTest extends TestCase
{
    public function testCreateConsoleDriverWithoutOptions(): void
    {
        $factory = new EvaluationOutputFactory();
        $driver = $factory->create(ConsoleOutput::class);

        $this->assertInstanceOf(ConsoleOutput::class, $driver);
        $this->assertInstanceOf(EvaluationOutputInterface::class, $driver);
    }

    public function testCreateConsoleDriverWithOptions(): void
    {
        $factory = new EvaluationOutputFactory();
        $driver = $factory->create(ConsoleOutput::class, ['verbose' => true]);

        $this->assertInstanceOf(ConsoleOutput::class, $driver);
    }

    public function testCreateJsonDriverWithOption(): void
    {
        $factory = new EvaluationOutputFactory();
        $driver = $factory->create(JsonOutput::class, ['path' => '/tmp/test.json']);

        $this->assertInstanceOf(JsonOutput::class, $driver);
    }

    public function testCreateJsonDriverWithoutOption(): void
    {
        $factory = new EvaluationOutputFactory();
        $driver = $factory->create(JsonOutput::class);

        $this->assertInstanceOf(JsonOutput::class, $driver);
    }

    public function testThrowsExceptionWhenClassNotFound(): void
    {
        $factory = new EvaluationOutputFactory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Driver class 'NonExistentDriver' not found");

        $factory->create('NonExistentDriver');
    }

    public function testThrowsExceptionWhenClassDoesNotImplementInterface(): void
    {
        $factory = new EvaluationOutputFactory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Driver 'stdClass' must implement EvaluationOutputInterface");

        $factory->create('stdClass');
    }

    public function testUsesCustomConstructorWhenRegistered(): void
    {
        $factory = new EvaluationOutputFactory();
        $factory->registerConstructor(
            ConsoleOutput::class,
            function (string $driverClass, array $options): EvaluationOutputInterface {
                $this->assertEquals(ConsoleOutput::class, $driverClass);
                $this->assertEquals(['verbose' => true], $options);
                return new ConsoleOutput(true);
            }
        );

        $driver = $factory->create(ConsoleOutput::class, ['verbose' => true]);

        $this->assertInstanceOf(ConsoleOutput::class, $driver);
    }

    public function testThrowsExceptionForMissingRequiredOption(): void
    {
        // Create a test driver with required parameter
        $testDriverClass = 'TestDriver_' . bin2hex(random_bytes(8));
        eval('class ' . $testDriverClass . ' implements NeuronAI\Evaluation\Contracts\EvaluationOutputInterface {
            private string $required;
            public function __construct(string $required) { $this->required = $required; }
            public function output(\NeuronAI\Evaluation\Runner\EvaluatorSummary $summary): void {}
        }');

        $factory = new EvaluationOutputFactory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Missing required option 'required' for driver");

        $factory->create($testDriverClass, []);
    }

    public function testUsesDefaultValueForOptionalParameter(): void
    {
        // Create a test driver with optional parameter
        $testDriverClass = 'TestDriver_' . bin2hex(random_bytes(8));
        eval('class ' . $testDriverClass . ' implements NeuronAI\Evaluation\Contracts\EvaluationOutputInterface {
            public bool $usedDefault = false;
            public function __construct(bool $optional = false) { $this->usedDefault = !$optional; }
            public function output(\NeuronAI\Evaluation\Runner\EvaluatorSummary $summary): void {}
        }');

        $factory = new EvaluationOutputFactory();
        $driver = $factory->create($testDriverClass);

        // Access the property via reflection to check default was used
        $reflection = new ReflectionProperty($driver, 'usedDefault');
        $this->assertTrue($reflection->getValue($driver));
    }

    public function testOverrideDefaultValueWithProvidedOption(): void
    {
        // Create a test driver with optional parameter
        $testDriverClass = 'TestDriver_' . bin2hex(random_bytes(8));
        eval('class ' . $testDriverClass . ' implements NeuronAI\Evaluation\Contracts\EvaluationOutputInterface {
            public bool $value = false;
            public function __construct(bool $optional = false) { $this->value = $optional; }
            public function output(\NeuronAI\Evaluation\Runner\EvaluatorSummary $summary): void {}
        }');

        $factory = new EvaluationOutputFactory();
        $driver = $factory->create($testDriverClass, ['optional' => true]);

        $reflection = new ReflectionProperty($driver, 'value');
        $this->assertTrue($reflection->getValue($driver));
    }
}
