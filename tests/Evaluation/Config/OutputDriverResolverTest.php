<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Config;

use NeuronAI\Evaluation\Config\EvaluationOutputResolver;
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OutputDriverResolverTest extends TestCase
{
    public function test_resolves_zero_arg_class_string(): void
    {
        $resolver = new EvaluationOutputResolver();

        $drivers = $resolver->resolve([ConsoleOutput::class]);

        $this->assertCount(1, $drivers);
        $this->assertInstanceOf(ConsoleOutput::class, $drivers[0]);
    }

    public function test_resolves_multiple_class_strings(): void
    {
        $resolver = new EvaluationOutputResolver();

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
        $resolver = new EvaluationOutputResolver();
        $instance = new JsonOutput('/tmp/test.json');

        $drivers = $resolver->resolve([$instance]);

        $this->assertCount(1, $drivers);
        $this->assertSame($instance, $drivers[0]);
    }

    public function test_resolves_mixed_class_strings_and_instances(): void
    {
        $resolver = new EvaluationOutputResolver();

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
        $resolver = new EvaluationOutputResolver();

        $drivers = $resolver->resolve([]);

        $this->assertCount(0, $drivers);
    }

    public function test_throws_exception_when_class_not_found(): void
    {
        $resolver = new EvaluationOutputResolver();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Driver class 'NonExistentDriver' not found");

        $resolver->resolve(['NonExistentDriver']);
    }

    public function test_throws_exception_when_class_does_not_implement_interface(): void
    {
        $resolver = new EvaluationOutputResolver();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("must implement EvaluationOutputInterface");

        $resolver->resolve(['stdClass']);
    }
}
