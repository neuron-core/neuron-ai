<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Config;

use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use ReflectionClass;
use RuntimeException;
use ReflectionException;

use function array_key_exists;
use function class_exists;
use function is_subclass_of;

class EvaluationOutputFactory
{
    /**
     * @var array<string, callable(string, array): EvaluationOutputInterface>
     */
    private array $customConstructors = [];

    /**
     * @throws ReflectionException
     */
    public function create(string $driverClass, array $options = []): EvaluationOutputInterface
    {
        if (isset($this->customConstructors[$driverClass])) {
            return ($this->customConstructors[$driverClass])($driverClass, $options);
        }

        return $this->instantiateViaReflection($driverClass, $options);
    }

    public function registerConstructor(string $driverClass, callable $constructor): void
    {
        $this->customConstructors[$driverClass] = $constructor;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ReflectionException
     */
    protected function instantiateViaReflection(string $driverClass, array $options): EvaluationOutputInterface
    {
        if (!class_exists($driverClass)) {
            throw new RuntimeException("Driver class '{$driverClass}' not found");
        }

        if (!is_subclass_of($driverClass, EvaluationOutputInterface::class)) {
            throw new RuntimeException("Driver '{$driverClass}' must implement EvaluationOutputInterface");
        }

        $reflection = new ReflectionClass($driverClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            /** @var EvaluationOutputInterface */
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $paramName = $param->getName();
            if (array_key_exists($paramName, $options)) {
                $args[] = $options[$paramName];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif (!$param->isOptional()) {
                throw new RuntimeException(
                    "Missing required option '{$paramName}' for driver '{$driverClass}'"
                );
            }
        }

        /** @var EvaluationOutputInterface */
        return $reflection->newInstanceArgs($args);
    }
}
