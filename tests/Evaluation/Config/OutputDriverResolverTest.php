<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Config;

use NeuronAI\Evaluation\Config\EvaluationOutputFactory;
use NeuronAI\Evaluation\Config\EvaluationOutputResolver;
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OutputDriverResolverTest extends TestCase
{
    public function testResolvesSimpleDriverWithoutOptions(): void
    {
        $factory = new EvaluationOutputFactory();
        $resolver = new EvaluationOutputResolver($factory);

        $drivers = $resolver->resolve([ConsoleOutput::class]);

        $this->assertCount(1, $drivers);
        $this->assertInstanceOf(ConsoleOutput::class, $drivers[0]);
    }

    public function testResolvesMultipleSimpleDrivers(): void
    {
        $factory = new EvaluationOutputFactory();
        $resolver = new EvaluationOutputResolver($factory);

        $drivers = $resolver->resolve([
            ConsoleOutput::class,
            JsonOutput::class,
        ]);

        $this->assertCount(2, $drivers);
        $this->assertInstanceOf(ConsoleOutput::class, $drivers[0]);
        $this->assertInstanceOf(JsonOutput::class, $drivers[1]);
    }

    public function testResolvesDriverWithOptionsUsingClassKey(): void
    {
        $factory = new EvaluationOutputFactory();
        $resolver = new EvaluationOutputResolver($factory);

        $drivers = $resolver->resolve([
            JsonOutput::class => ['path' => '/tmp/test.json'],
        ]);

        $this->assertCount(1, $drivers);
        $this->assertInstanceOf(JsonOutput::class, $drivers[0]);
    }

    public function testResolvesMixedConfigFormat(): void
    {
        $factory = new EvaluationOutputFactory();
        $resolver = new EvaluationOutputResolver($factory);

        $drivers = $resolver->resolve([
            ConsoleOutput::class,
            JsonOutput::class => ['path' => '/tmp/test.json'],
        ]);

        $this->assertCount(2, $drivers);
        $this->assertInstanceOf(ConsoleOutput::class, $drivers[0]);
        $this->assertInstanceOf(JsonOutput::class, $drivers[1]);
    }

    public function testResolvesMultipleDriversWithOptions(): void
    {
        $factory = new EvaluationOutputFactory();
        $resolver = new EvaluationOutputResolver($factory);

        $drivers = $resolver->resolve([
            ConsoleOutput::class => ['verbose' => true],
            JsonOutput::class => ['path' => '/tmp/test.json'],
        ]);

        $this->assertCount(2, $drivers);
        $this->assertInstanceOf(ConsoleOutput::class, $drivers[0]);
        $this->assertInstanceOf(JsonOutput::class, $drivers[1]);
    }

    public function testThrowsExceptionForInvalidConfigStructure(): void
    {
        $factory = new EvaluationOutputFactory();
        $resolver = new EvaluationOutputResolver($factory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid driver config structure');

        $resolver->resolve([123 => 456]);
    }

    public function testPassesOptionsToFactory(): void
    {
        $factory = $this->createMockFactory();
        $resolver = new EvaluationOutputResolver($factory);

        $factory->expects($this->once())
            ->method('create')
            ->with(ConsoleOutput::class, ['verbose' => true]);

        $resolver->resolve([ConsoleOutput::class => ['verbose' => true]]);
    }

    public function testResolvesEmptyArray(): void
    {
        $factory = new EvaluationOutputFactory();
        $resolver = new EvaluationOutputResolver($factory);

        $drivers = $resolver->resolve([]);

        $this->assertCount(0, $drivers);
    }

    /**
     * @return EvaluationOutputFactory&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createMockFactory(): EvaluationOutputFactory
    {
        $mock = $this->createMock(EvaluationOutputFactory::class);
        $mock->method('create')
            ->willReturn($this->createMock(EvaluationOutputInterface::class));

        return $mock;
    }
}
