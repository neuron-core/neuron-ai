<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Workflow\Executor\StepResult;
use NeuronAI\Workflow\Persistence\IgbinarySerializer;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

use function function_exists;
use function strlen;

final class SerializerTest extends TestCase
{
    private function sampleResult(): StepResult
    {
        return new StepResult(
            stepId: 'step-1',
            error: ['message' => 'boom', 'class' => 'RuntimeException'],
        );
    }

    public function test_php_serializer_round_trip(): void
    {
        $serializer = new PhpSerializer();
        $result = $this->sampleResult();

        $restored = $serializer->unserialize($serializer->serialize($result));

        Assert::assertInstanceOf(StepResult::class, $restored);
        Assert::assertSame('step-1', $restored->getStepId());
        Assert::assertTrue($restored->isFailed());
        Assert::assertSame(['message' => 'boom', 'class' => 'RuntimeException'], $restored->getError());
    }

    public function test_igbinary_serializer_round_trip(): void
    {
        if (!function_exists('igbinary_serialize')) {
            self::markTestSkipped('ext-igbinary not available');
        }

        $serializer = new IgbinarySerializer();
        $result = $this->sampleResult();

        $restored = $serializer->unserialize($serializer->serialize($result));

        Assert::assertInstanceOf(StepResult::class, $restored);
        Assert::assertSame('step-1', $restored->getStepId());
        Assert::assertTrue($restored->isFailed());
        Assert::assertSame(['message' => 'boom', 'class' => 'RuntimeException'], $restored->getError());
    }

    public function test_igbinary_blob_is_smaller_than_php_blob(): void
    {
        if (!function_exists('igbinary_serialize')) {
            self::markTestSkipped('ext-igbinary not available');
        }

        $result = $this->sampleResult();
        $phpSize = strlen((new PhpSerializer())->serialize($result));
        $igbinarySize = strlen((new IgbinarySerializer())->serialize($result));

        Assert::assertLessThan(
            $phpSize,
            $igbinarySize,
            'igbinary blob should be smaller than the native php-serialize blob',
        );
    }
}
