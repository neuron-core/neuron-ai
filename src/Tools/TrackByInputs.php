<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use function array_map;
use function hash;
use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

/**
 * Trait for tools that want input-based run key tracking.
 *
 * Provides a getRunKey() implementation that combines the tool name with
 * its input parameters. Tools can use this trait as-is, or override
 * getRunKey() to select which parameters matter for their use case.
 */
trait TrackByInputs
{
    /**
     * Only the declared inputs, in declaration order: reordering the arguments
     * or adding undeclared ones must not reset the tool's run budget.
     */
    public function getRunKey(): string
    {
        $inputs = array_map(
            fn (ToolPropertyInterface $property): mixed => $this->getInput($property->getName()),
            $this->getProperties()
        );

        return $this->getName() . ':' . hash('sha1', json_encode($inputs, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }
}
