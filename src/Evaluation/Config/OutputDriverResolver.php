<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Config;

use NeuronAI\Evaluation\Contracts\OutputDriverInterface;
use RuntimeException;

use function is_array;
use function is_int;
use function is_string;

class OutputDriverResolver
{
    /**
     * @var OutputDriverInterface[]
     */
    private array $resolvedDrivers = [];

    public function __construct(
        private readonly OutputDriverFactory $factory = new OutputDriverFactory()
    ) {
    }

    /**
     * @param array<string|int, mixed> $driverConfigs
     * @return OutputDriverInterface[]
     */
    public function resolve(array $driverConfigs): array
    {
        foreach ($driverConfigs as $key => $value) {
            // Case 1: Integer key, value is class string (no options)
            if (is_int($key) && is_string($value)) {
                $this->resolvedDrivers[] = $this->factory->create($value, []);
            }
            // Case 2: String key (class name), value is options array
            elseif (is_string($key) && is_array($value)) {
                $this->resolvedDrivers[] = $this->factory->create($key, $value);
            } else {
                throw new RuntimeException('Invalid driver config structure');
            }
        }

        return $this->resolvedDrivers;
    }
}
