<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits;

/**
 * Separate from ToolkitInterface only to keep direct implementations of it
 * working: these methods move into ToolkitInterface in the next major version.
 */
interface ToolkitVisibilityInterface
{
    public function visible(bool $visible): ToolkitInterface;

    public function isVisible(): bool;
}
