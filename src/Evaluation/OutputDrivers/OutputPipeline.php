<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\OutputDrivers;

use NeuronAI\Evaluation\Contracts\OutputDriverInterface;
use NeuronAI\Evaluation\Runner\EvaluatorSummary;
use Throwable;

use function error_log;

class OutputPipeline implements OutputDriverInterface
{
    /**
     * @param OutputDriverInterface[] $drivers
     */
    public function __construct(
        private readonly array $drivers
    ) {
    }

    public function output(EvaluatorSummary $summary): void
    {
        foreach ($this->drivers as $driver) {
            try {
                $driver->output($summary);
            } catch (Throwable $e) {
                error_log("Output driver " . $driver::class . " failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * @return OutputDriverInterface[]
     */
    public function getDrivers(): array
    {
        return $this->drivers;
    }
}
