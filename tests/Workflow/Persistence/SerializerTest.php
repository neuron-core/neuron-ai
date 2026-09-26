<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Workflow\Executor\StepResult;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Persistence\IgbinarySerializer;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Persistence\Serializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function function_exists;
use function igbinary_serialize;
use function serialize;
use function strlen;
use function substr;

final class SerializerTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function serializerProvider(): array
    {
        return ['php' => ['php'], 'igbinary' => ['igbinary']];
    }

    protected function serializer(string $name): Serializer
    {
        if ($name === 'php') {
            return new PhpSerializer();
        }
        if (!function_exists('igbinary_serialize')) {
            $this->markTestSkipped('ext-igbinary not available');
        }

        return new IgbinarySerializer();
    }

    protected function sampleResult(): StepResult
    {
        return new StepResult(
            stepId: 'step-1',
            event: InterruptEvent::fromRequest((new WaitForEventRequest('approval'))->withId(1)),
        );
    }

    #[DataProvider('serializerProvider')]
    public function test_a_step_result_round_trips(string $name): void
    {
        $serializer = $this->serializer($name);

        $restored = $serializer->unserialize($serializer->serialize($this->sampleResult()));

        $this->assertInstanceOf(StepResult::class, $restored);
        $this->assertSame('step-1', $restored->getStepId());
        $this->assertTrue($restored->isInterrupted());
        $event = $restored->getEvent();
        $this->assertInstanceOf(InterruptEvent::class, $event);
        $this->assertInstanceOf(WaitForEventRequest::class, $event->request);
        $this->assertSame('approval', $event->request->getEventName());
        $this->assertSame(1, $event->request->getId());
    }

    #[DataProvider('serializerProvider')]
    public function test_scalars_and_nested_structures_round_trip_exactly(string $name): void
    {
        $serializer = $this->serializer($name);
        $object = new stdClass();
        $object->bytes = "\0\xFF\xFE";
        $values = [
            false, null, true, 0, -1, 1.5, '', '0', "multi\nline ✓ 日本語",
            ['nested' => ['list' => [1, 2, 3], 'empty' => []], 7 => 'int key'],
            $object,
        ];

        foreach ($values as $value) {
            $restored = $serializer->unserialize($serializer->serialize($value));
            $value instanceof stdClass ? $this->assertEquals($value, $restored) : $this->assertSame($value, $restored);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function malformedProvider(): array
    {
        $php = serialize(['step' => 'a result long enough to be truncated']);
        $cases = [
            'php empty' => ['php', ''],
            'php garbage' => ['php', 'not-serialized-data'],
            'php truncated' => ['php', substr($php, 0, strlen($php) - 5)],
            'igbinary empty' => ['igbinary', ''],
            'igbinary garbage' => ['igbinary', 'not-serialized-data'],
            'igbinary given php bytes' => ['igbinary', $php],
        ];
        if (function_exists('igbinary_serialize')) {
            $igbinary = (string) igbinary_serialize(['step' => 'a result long enough to be truncated']);
            $cases['php given igbinary bytes'] = ['php', $igbinary];
            $cases['igbinary truncated'] = ['igbinary', substr($igbinary, 0, strlen($igbinary) - 5)];
        }

        return $cases;
    }

    #[DataProvider('malformedProvider')]
    public function test_malformed_bytes_are_rejected_instead_of_read_as_a_value(string $name, string $data): void
    {
        $serializer = $this->serializer($name);

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('Unable to unserialize persisted Workflow value.');

        $serializer->unserialize($data);
    }

    public function test_igbinary_blob_is_smaller_than_php_blob(): void
    {
        if (!function_exists('igbinary_serialize')) {
            self::markTestSkipped('ext-igbinary not available');
        }

        $result = $this->sampleResult();
        $phpSize = strlen((new PhpSerializer())->serialize($result));
        $igbinarySize = strlen((new IgbinarySerializer())->serialize($result));

        $this->assertLessThan(
            $phpSize,
            $igbinarySize,
            'igbinary blob should be smaller than the native php-serialize blob',
        );
    }
}
