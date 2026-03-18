<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Config;

use NeuronAI\Evaluation\Config\OutputDriverFactory;
use NeuronAI\Evaluation\Config\OutputDriverResolver;
use NeuronAI\Evaluation\Contracts\OutputDriverInterface;
use NeuronAI\Evaluation\OutputDrivers\ConsoleOutputDriver;
use NeuronAI\Evaluation\OutputDrivers\JsonOutputDriver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OutputDriverResolverTest extends TestCase
{
    public function testResolvesSimpleDriverWithoutOptions(): void
    {
        $factory = new OutputDriverFactory();
        $resolver = new OutputDriverResolver($factory);

        $drivers = $resolver->resolve([ConsoleOutputDriver::class]);

        $this->assertCount(1, $drivers);
        $this->assertInstanceOf(ConsoleOutputDriver::class, $drivers[0]);
    }

    public function testResolvesMultipleSimpleDrivers(): void
    {
        $factory = new OutputDriverFactory();
        $resolver = new OutputDriverResolver($factory);

        $drivers = $resolver->resolve([
            ConsoleOutputDriver::class,
            JsonOutputDriver::class,
        ]);

        $this->assertCount(2, $drivers);
        $this->assertInstanceOf(ConsoleOutputDriver::class, $drivers[0]);
        $this->assertInstanceOf(JsonOutputDriver::class, $drivers[1]);
    }

    public function testResolvesDriverWithOptionsUsingClassKey(): void
    {
        $factory = new OutputDriverFactory();
        $resolver = new OutputDriverResolver($factory);

        $drivers = $resolver->resolve([
            JsonOutputDriver::class => ['path' => '/tmp/test.json'],
        ]);

        $this->assertCount(1, $drivers);
        $this->assertInstanceOf(JsonOutputDriver::class, $drivers[0]);
    }

    public function testResolvesMixedConfigFormat(): void
    {
        $factory = new OutputDriverFactory();
        $resolver = new OutputDriverResolver($factory);

        $drivers = $resolver->resolve([
            ConsoleOutputDriver::class,
            JsonOutputDriver::class => ['path' => '/tmp/test.json'],
        ]);

        $this->assertCount(2, $drivers);
        $this->assertInstanceOf(ConsoleOutputDriver::class, $drivers[0]);
        $this->assertInstanceOf(JsonOutputDriver::class, $drivers[1]);
    }

    public function testResolvesMultipleDriversWithOptions(): void
    {
        $factory = new OutputDriverFactory();
        $resolver = new OutputDriverResolver($factory);

        $drivers = $resolver->resolve([
            ConsoleOutputDriver::class => ['verbose' => true],
            JsonOutputDriver::class => ['path' => '/tmp/test.json'],
        ]);

        $this->assertCount(2, $drivers);
        $this->assertInstanceOf(ConsoleOutputDriver::class, $drivers[0]);
        $this->assertInstanceOf(JsonOutputDriver::class, $drivers[1]);
    }

    public function testThrowsExceptionForInvalidConfigStructure(): void
    {
        $factory = new OutputDriverFactory();
        $resolver = new OutputDriverResolver($factory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid driver config structure');

        $resolver->resolve([123 => 456]);
    }

    public function testPassesOptionsToFactory(): void
    {
        $factory = $this->createMockFactory();
        $resolver = new OutputDriverResolver($factory);

        $factory->expects($this->once())
            ->method('create')
            ->with(ConsoleOutputDriver::class, ['verbose' => true]);

        $resolver->resolve([ConsoleOutputDriver::class => ['verbose' => true]]);
    }

    public function testResolvesEmptyArray(): void
    {
        $factory = new OutputDriverFactory();
        $resolver = new OutputDriverResolver($factory);

        $drivers = $resolver->resolve([]);

        $this->assertCount(0, $drivers);
    }

    /**
     * @return OutputDriverFactory&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createMockFactory(): OutputDriverFactory
    {
        $mock = $this->createMock(OutputDriverFactory::class);
        $mock->method('create')
            ->willReturn($this->createMock(OutputDriverInterface::class));

        return $mock;
    }
}
