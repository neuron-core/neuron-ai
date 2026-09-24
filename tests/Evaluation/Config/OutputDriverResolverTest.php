<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Config;

use ArrayObject;
use NeuronAI\Evaluation\Config\EvaluationOutputResolver;
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronAI\Tests\Evaluation\Stub\RecordingOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OutputDriverResolverTest extends TestCase
{
    public function test_resolves_zero_arg_class_string(): void
    {
        $resolver = $this->instantiatingResolver();

        $drivers = $resolver->resolve([ConsoleOutput::class]);

        $this->assertCount(1, $drivers);
        $this->assertInstanceOf(ConsoleOutput::class, $drivers[0]);
    }

    public function test_resolves_multiple_class_strings(): void
    {
        $resolver = $this->instantiatingResolver();

        $drivers = $resolver->resolve([
            ConsoleOutput::class,
            JsonOutput::class,
        ]);

        $this->assertCount(2, $drivers);
        $this->assertInstanceOf(ConsoleOutput::class, $drivers[0]);
        $this->assertInstanceOf(JsonOutput::class, $drivers[1]);
    }

    public function test_passes_through_already_constructed_instance(): void
    {
        $resolver = $this->instantiatingResolver();
        $instance = new JsonOutput('/tmp/test.json');

        $drivers = $resolver->resolve([$instance]);

        $this->assertCount(1, $drivers);
        $this->assertSame($instance, $drivers[0]);
    }

    public function test_resolves_mixed_class_strings_and_instances(): void
    {
        $resolver = $this->instantiatingResolver();

        $drivers = $resolver->resolve([
            ConsoleOutput::class,
            new JsonOutput('/tmp/test.json'),
        ]);

        $this->assertCount(2, $drivers);
        $this->assertInstanceOf(ConsoleOutput::class, $drivers[0]);
        $this->assertInstanceOf(JsonOutput::class, $drivers[1]);
    }

    public function test_resolves_empty_array(): void
    {
        $resolver = $this->instantiatingResolver();

        $drivers = $resolver->resolve([]);

        $this->assertCount(0, $drivers);
    }

    public function test_throws_exception_when_class_not_found(): void
    {
        $resolver = $this->instantiatingResolver();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Driver class 'NonExistentDriver' not found");

        $resolver->resolve(['NonExistentDriver']);
    }

    public function test_throws_exception_when_class_does_not_implement_interface(): void
    {
        $resolver = $this->instantiatingResolver();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("must implement EvaluationOutputInterface");

        $resolver->resolve(['stdClass']);
    }

    public function test_class_strings_are_built_by_the_resolver(): void
    {
        $reports = new ArrayObject();
        $resolver = new EvaluationOutputResolver(static fn (string $class): object => new $class($reports));

        $drivers = $resolver->resolve([RecordingOutput::class]);

        $this->assertCount(1, $drivers);
        $this->assertInstanceOf(RecordingOutput::class, $drivers[0]);
    }

    protected function instantiatingResolver(): EvaluationOutputResolver
    {
        return new EvaluationOutputResolver(static fn (string $class): object => new $class());
    }
}
