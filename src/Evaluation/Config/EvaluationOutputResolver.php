<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Config;

use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use RuntimeException;

use function class_exists;
use function is_subclass_of;

/**
 * Resolves the `output` config entries into driver instances.
 *
 * Each entry is either:
 *  - a class string of a zero-argument {@see EvaluationOutputInterface}, or
 *  - an already-constructed {@see EvaluationOutputInterface} instance.
 *
 * Drivers that need constructor arguments (dependencies, options) must be
 * supplied as concrete instances - the framework's DI container can build
 * them. There is no reflection-based option mapping or callable factory.
 */
class EvaluationOutputResolver
{
    /**
     * @param array<int, string|EvaluationOutputInterface> $drivers
     * @return EvaluationOutputInterface[]
     */
    public function resolve(array $drivers): array
    {
        $resolved = [];

        foreach ($drivers as $driver) {
            $resolved[] = $this->resolveOne($driver);
        }

        return $resolved;
    }

    protected function resolveOne(string|EvaluationOutputInterface $driver): EvaluationOutputInterface
    {
        if ($driver instanceof EvaluationOutputInterface) {
            return $driver;
        }

        if (!class_exists($driver)) {
            throw new RuntimeException("Driver class '{$driver}' not found");
        }

        if (!is_subclass_of($driver, EvaluationOutputInterface::class)) {
            throw new RuntimeException("Driver '{$driver}' must implement EvaluationOutputInterface");
        }

        /** @var class-string<EvaluationOutputInterface> $driver */
        return new $driver();
    }
}
