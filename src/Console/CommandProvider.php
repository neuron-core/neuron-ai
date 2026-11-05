<?php

declare(strict_types=1);

namespace NeuronAI\Console;

use Closure;

/**
 * Adds commands to the neuron CLI from an installed package, which declares it
 * under "extra.neuron.command-provider" in its composer.json.
 */
interface CommandProvider
{
    /**
     * @return array<string, array{string, Closure(): Command}> Command name => [description, factory].
     */
    public function commands(): array;
}
