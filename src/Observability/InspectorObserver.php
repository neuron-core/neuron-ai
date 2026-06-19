<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Exception;
use Inspector\Configuration;
use Inspector\Exceptions\InspectorException;
use Inspector\Inspector;
use Inspector\Models\Segment;
use Inspector\Scope;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

use function array_key_exists;
use function array_map;
use function strrchr;
use function substr;

/**
 * @deprecated Use the new observer from the Inspector PHP package - Inspector/Neuron/InspectorObserver
 */
class InspectorObserver extends \Inspector\Neuron\InspectorObserver {}
