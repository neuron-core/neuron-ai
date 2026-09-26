<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Config;

use ArrayObject;
use NeuronAI\Evaluation\Config\EvaluationOutputResolver;
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronAI\Tests\Evaluation\Stub\RecordingOutput;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $requested = [];
        $built = new RecordingOutput($reports);
        $resolver = new EvaluationOutputResolver(static function (string $class) use (&$requested, $built): object {
            $requested[] = $class;
            return $built;
        });

        $drivers = $resolver->resolve([RecordingOutput::class]);

        $this->assertSame([RecordingOutput::class], $requested);
        $this->assertSame([$built], $drivers);
    }

    public function test_instances_are_not_passed_to_the_resolver(): void
    {
        $instance = new JsonOutput('/tmp/test.json');
        $resolver = new EvaluationOutputResolver(function (string $class): object {
            $this->fail("The resolver must not be asked to build {$class}");
        });

        $this->assertSame([$instance], $resolver->resolve([$instance]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedClassStrings(): iterable
    {
        yield 'unknown class' => ['App\\MissingOutput', "Driver class 'App\\MissingOutput' not found"];
        yield 'class not implementing the interface' => [ArrayObject::class, "Driver 'ArrayObject' must implement EvaluationOutputInterface"];
        yield 'the interface itself' => [EvaluationOutputInterface::class, "Driver class '" . EvaluationOutputInterface::class . "' not found"];
        yield 'function name' => ['phpinfo', "Driver class 'phpinfo' not found"];
    }

    #[DataProvider('rejectedClassStrings')]
    public function test_rejected_class_strings_never_reach_the_resolver(string $driver, string $message): void
    {
        $resolver = new EvaluationOutputResolver(function (string $class): object {
            $this->fail("The resolver must not be asked to build {$class}");
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $resolver->resolve([$driver]);
    }

    public function test_an_invalid_entry_stops_resolution_before_later_drivers_are_built(): void
    {
        $built = [];
        $resolver = new EvaluationOutputResolver(static function (string $class) use (&$built): object {
            $built[] = $class;
            return new $class();
        });

        try {
            $resolver->resolve([ConsoleOutput::class, 'App\\MissingOutput', JsonOutput::class]);
            $this->fail('An unknown driver class must be rejected');
        } catch (RuntimeException $exception) {
            // PHPUnit's own failure is a RuntimeException too: the message tells them apart
            $this->assertSame("Driver class 'App\\MissingOutput' not found", $exception->getMessage());
        }

        $this->assertSame([ConsoleOutput::class], $built);
    }

    protected function instantiatingResolver(): EvaluationOutputResolver
    {
        return new EvaluationOutputResolver(static fn (string $class): object => new $class());
    }
}
