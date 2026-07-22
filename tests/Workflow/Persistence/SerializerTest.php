<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Workflow\Executor\StepResult;
use NeuronAI\Workflow\Persistence\IgbinarySerializer;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

final class SerializerTest extends TestCase
{
    private function sampleResult(): StepResult
    {
        return new StepResult(
            'step-1',
            null,
            null,
            null,
            ['summary' => 'memoized LLM output'],
            ['message' => 'boom', 'class' => 'RuntimeException'],
        );
    }

    public function test_php_serializer_round_trip(): void
    {
        $serializer = new PhpSerializer();
        $result = $this->sampleResult();

        $restored = $serializer->unserialize($serializer->serialize($result));

        Assert::assertSame('step-1', $restored->getStepId());
        Assert::assertSame(['summary' => 'memoized LLM output'], $restored->getOutput());
        Assert::assertTrue($restored->isFailed());
        Assert::assertSame(['message' => 'boom', 'class' => 'RuntimeException'], $restored->getError());
    }

    public function test_igbinary_serializer_round_trip(): void
    {
        if (!\function_exists('igbinary_serialize')) {
            self::markTestSkipped('ext-igbinary not available');
        }

        $serializer = new IgbinarySerializer();
        $result = $this->sampleResult();

        $restored = $serializer->unserialize($serializer->serialize($result));

        Assert::assertSame('step-1', $restored->getStepId());
        Assert::assertSame(['summary' => 'memoized LLM output'], $restored->getOutput());
        Assert::assertTrue($restored->isFailed());
        Assert::assertSame(['message' => 'boom', 'class' => 'RuntimeException'], $restored->getError());
    }

    public function test_igbinary_blob_is_smaller_than_php_blob(): void
    {
        if (!\function_exists('igbinary_serialize')) {
            self::markTestSkipped('ext-igbinary not available');
        }

        $result = $this->sampleResult();
        $phpSize = \strlen((new PhpSerializer())->serialize($result));
        $igbinarySize = \strlen((new IgbinarySerializer())->serialize($result));

        Assert::assertLessThan(
            $phpSize,
            $igbinarySize,
            'igbinary blob should be smaller than the native php-serialize blob',
        );
    }
}
