<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Config;

use NeuronAI\Evaluation\Config\OutputDriverFactory;
use NeuronAI\Evaluation\Contracts\OutputDriverInterface;
use NeuronAI\Evaluation\OutputDrivers\ConsoleOutputDriver;
use NeuronAI\Evaluation\OutputDrivers\JsonOutputDriver;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ReflectionProperty;

use function bin2hex;
use function random_bytes;

class OutputDriverFactoryTest extends TestCase
{
    public function testCreateConsoleDriverWithoutOptions(): void
    {
        $factory = new OutputDriverFactory();
        $driver = $factory->create(ConsoleOutputDriver::class);

        $this->assertInstanceOf(ConsoleOutputDriver::class, $driver);
        $this->assertInstanceOf(OutputDriverInterface::class, $driver);
    }

    public function testCreateConsoleDriverWithOptions(): void
    {
        $factory = new OutputDriverFactory();
        $driver = $factory->create(ConsoleOutputDriver::class, ['verbose' => true]);

        $this->assertInstanceOf(ConsoleOutputDriver::class, $driver);
    }

    public function testCreateJsonDriverWithOption(): void
    {
        $factory = new OutputDriverFactory();
        $driver = $factory->create(JsonOutputDriver::class, ['path' => '/tmp/test.json']);

        $this->assertInstanceOf(JsonOutputDriver::class, $driver);
    }

    public function testCreateJsonDriverWithoutOption(): void
    {
        $factory = new OutputDriverFactory();
        $driver = $factory->create(JsonOutputDriver::class);

        $this->assertInstanceOf(JsonOutputDriver::class, $driver);
    }

    public function testThrowsExceptionWhenClassNotFound(): void
    {
        $factory = new OutputDriverFactory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Driver class 'NonExistentDriver' not found");

        $factory->create('NonExistentDriver');
    }

    public function testThrowsExceptionWhenClassDoesNotImplementInterface(): void
    {
        $factory = new OutputDriverFactory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Driver 'stdClass' must implement OutputDriverInterface");

        $factory->create('stdClass');
    }

    public function testUsesCustomConstructorWhenRegistered(): void
    {
        $factory = new OutputDriverFactory();
        $factory->registerConstructor(
            ConsoleOutputDriver::class,
            function (string $driverClass, array $options): OutputDriverInterface {
                $this->assertEquals(ConsoleOutputDriver::class, $driverClass);
                $this->assertEquals(['verbose' => true], $options);
                return new ConsoleOutputDriver(true);
            }
        );

        $driver = $factory->create(ConsoleOutputDriver::class, ['verbose' => true]);

        $this->assertInstanceOf(ConsoleOutputDriver::class, $driver);
    }

    public function testThrowsExceptionForMissingRequiredOption(): void
    {
        // Create a test driver with required parameter
        $testDriverClass = 'TestDriver_' . bin2hex(random_bytes(8));
        eval('class ' . $testDriverClass . ' implements NeuronAI\Evaluation\Contracts\OutputDriverInterface {
            private string $required;
            public function __construct(string $required) { $this->required = $required; }
            public function output(\NeuronAI\Evaluation\Runner\EvaluatorSummary $summary): void {}
        }');

        $factory = new OutputDriverFactory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Missing required option 'required' for driver");

        $factory->create($testDriverClass, []);
    }

    public function testUsesDefaultValueForOptionalParameter(): void
    {
        // Create a test driver with optional parameter
        $testDriverClass = 'TestDriver_' . bin2hex(random_bytes(8));
        eval('class ' . $testDriverClass . ' implements NeuronAI\Evaluation\Contracts\OutputDriverInterface {
            public bool $usedDefault = false;
            public function __construct(bool $optional = false) { $this->usedDefault = !$optional; }
            public function output(\NeuronAI\Evaluation\Runner\EvaluatorSummary $summary): void {}
        }');

        $factory = new OutputDriverFactory();
        $driver = $factory->create($testDriverClass);

        // Access the property via reflection to check default was used
        $reflection = new ReflectionProperty($driver, 'usedDefault');
        $this->assertTrue($reflection->getValue($driver));
    }

    public function testOverrideDefaultValueWithProvidedOption(): void
    {
        // Create a test driver with optional parameter
        $testDriverClass = 'TestDriver_' . bin2hex(random_bytes(8));
        eval('class ' . $testDriverClass . ' implements NeuronAI\Evaluation\Contracts\OutputDriverInterface {
            public bool $value = false;
            public function __construct(bool $optional = false) { $this->value = $optional; }
            public function output(\NeuronAI\Evaluation\Runner\EvaluatorSummary $summary): void {}
        }');

        $factory = new OutputDriverFactory();
        $driver = $factory->create($testDriverClass, ['optional' => true]);

        $reflection = new ReflectionProperty($driver, 'value');
        $this->assertTrue($reflection->getValue($driver));
    }
}
