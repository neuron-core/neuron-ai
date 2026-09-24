<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Config;

use Closure;
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use RuntimeException;

use function class_exists;
use function is_subclass_of;

/**
 * Resolves the `output` config entries into driver instances.
 *
 * Each entry is either:
 *  - a class string of an {@see EvaluationOutputInterface}, built by the resolver, or
 *  - an already-constructed {@see EvaluationOutputInterface} instance.
 *
 * A resolver backed by the framework's DI container builds drivers that need
 * dependencies from their class string. There is no reflection-based option mapping.
 */
class EvaluationOutputResolver
{
    /**
     * @param Closure(class-string): object $resolver
     */
    public function __construct(protected Closure $resolver)
    {
    }

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

        return ($this->resolver)($driver);
    }
}
