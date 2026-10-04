<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use NeuronAI\Workflow\Streaming\Channel\MercureChannel;

/**
 * Runs on a clock the test moves: waiting advances it instead of sleeping.
 */
final class ManualClockMercureChannel extends MercureChannel
{
    public float $time = 1_000.0;

    /** @var list<float> */
    public array $waits = [];

    protected function now(): float
    {
        return $this->time;
    }

    protected function sleep(float $seconds): void
    {
        $this->waits[] = $seconds;
        $this->time += $seconds;
    }
}
