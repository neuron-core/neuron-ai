<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Config;

use NeuronAI\Evaluation\Contracts\OutputDriverInterface;
use ReflectionClass;
use RuntimeException;
use ReflectionException;

use function array_key_exists;
use function class_exists;
use function is_subclass_of;

class OutputDriverFactory
{
    /**
     * @var array<string, callable(string, array): OutputDriverInterface>
     */
    private array $customConstructors = [];

    public function create(string $driverClass, array $options = []): OutputDriverInterface
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
    private function instantiateViaReflection(string $driverClass, array $options): OutputDriverInterface
    {
        if (!class_exists($driverClass)) {
            throw new RuntimeException("Driver class '{$driverClass}' not found");
        }

        if (!is_subclass_of($driverClass, OutputDriverInterface::class)) {
            throw new RuntimeException("Driver '{$driverClass}' must implement OutputDriverInterface");
        }

        $reflection = new ReflectionClass($driverClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            /** @var OutputDriverInterface */
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

        /** @var OutputDriverInterface */
        return $reflection->newInstanceArgs($args);
    }
}
