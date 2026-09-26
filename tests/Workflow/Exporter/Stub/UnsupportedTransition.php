<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Exporter\Stub;

use NeuronAI\Workflow\Exporter\ExporterTransition;

/** A transition kind the graph builder does not know how to draw. */
final class UnsupportedTransition implements ExporterTransition
{
}
