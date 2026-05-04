<?php

declare(strict_types=1);

namespace Flowline\Tests;

use Flowline\Step;
use Flowline\StepPendingException;
use PHPUnit\Framework\TestCase;

final class StepTest extends TestCase
{
    public function testRunReturnsMemoizedData(): void
    {
        $step = new Step([
            sha1('fetch-order') => ['data' => ['id' => 42, 'total' => 99.99]],
        ]);

        $result = $step->run('fetch-order', fn (): array => ['should not run']);

        $this->assertSame(['id' => 42, 'total' => 99.99], $result);
        $this->assertSame([], $step->getOps());
    }

    public function testRunExecutesAndYields(): void
    {
        $step = new Step([]);

        $caught = false;
        try {
            $step->run('fetch-order', fn (): string => 'result-from-db');
        } catch (StepPendingException) {
            $caught = true;
        }

        $this->assertTrue($caught, 'StepPendingException should be thrown');
        $ops = $step->getOps();
        $this->assertCount(1, $ops);
        $this->assertSame('StepRun', $ops[0]['op']);
        $this->assertSame('fetch-order', $ops[0]['displayName']);
        $this->assertSame('result-from-db', $ops[0]['data']);
    }

    public function testRunCapturesError(): void
    {
        $step = new Step([]);

        $caught = false;
        try {
            $step->run('failing-step', function (): never {
                throw new \RuntimeException('DB connection failed');
            });
        } catch (StepPendingException) {
            $caught = true;
        }

        $this->assertTrue($caught);
        $ops = $step->getOps();
        $this->assertCount(1, $ops);
        $this->assertSame('StepError', $ops[0]['op']);
        $this->assertSame('DB connection failed', $ops[0]['error']['message']);
    }

    public function testRunThrowsOnMemoizedError(): void
    {
        $step = new Step([
            sha1('bad-step') => ['error' => ['name' => 'RuntimeException', 'message' => 'previous failure']],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('previous failure');

        $step->run('bad-step', fn () => 'never');
    }

    public function testSleepReturnsImmediatelyWhenMemoized(): void
    {
        $step = new Step([sha1('wait') => ['data' => null]]);

        // Should not throw
        $step->sleep('wait', '5m');

        $this->assertSame([], $step->getOps());
    }

    public function testSleepYieldsWhenNotMemoized(): void
    {
        $step = new Step([]);

        $this->expectException(StepPendingException::class);

        $step->sleep('wait', '5m');
    }

    public function testSleepRecordsOp(): void
    {
        $step = new Step([]);

        try {
            $step->sleep('wait', '5m');
        } catch (StepPendingException) {
            // expected
        }

        $ops = $step->getOps();
        $this->assertCount(1, $ops);
        $this->assertSame('Sleep', $ops[0]['op']);
        $this->assertSame('wait', $ops[0]['displayName']);
        $this->assertSame(['duration' => '5m'], $ops[0]['opts']);
    }

    public function testWaitForEventReturnsMemoizedData(): void
    {
        $step = new Step([
            sha1('wait-payment') => ['data' => ['status' => 'paid']],
        ]);

        $result = $step->waitForEvent('wait-payment', 'payment/received', '10m');

        $this->assertSame(['status' => 'paid'], $result);
        $this->assertSame([], $step->getOps());
    }

    public function testWaitForEventYieldsWhenNotMemoized(): void
    {
        $step = new Step([]);

        try {
            $step->waitForEvent('wait-payment', 'payment/received', '10m');
        } catch (StepPendingException) {
            // expected
        }

        $ops = $step->getOps();
        $this->assertCount(1, $ops);
        $this->assertSame('WaitForEvent', $ops[0]['op']);
        $this->assertSame(['event' => 'payment/received', 'timeout' => '10m'], $ops[0]['opts']);
    }

    public function testMultipleStepsInSequence(): void
    {
        $step = new Step([
            sha1('step-1') => ['data' => 'first-result'],
        ]);

        // First step is memoized - returns immediately
        $result1 = $step->run('step-1', fn () => 'should not run');
        $this->assertSame('first-result', $result1);

        // Second step is not memoized - executes and yields
        try {
            $step->run('step-2', fn () => 'second-result');
        } catch (StepPendingException) {
            // expected
        }

        $ops = $step->getOps();
        $this->assertCount(1, $ops);
        $this->assertSame('step-2', $ops[0]['displayName']);
        $this->assertSame('second-result', $ops[0]['data']);
    }
}
