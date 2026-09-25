<?php

declare(strict_types=1);

use NeuronAI\Observability\ObservabilityEvent;

/**
 * Deliberately outside any namespace, so the autoloader can't find it:
 * tests require this file.
 */
class UnnamespacedEvent extends ObservabilityEvent
{
}
