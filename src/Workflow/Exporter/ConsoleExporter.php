<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Exporter;

use NeuronAI\Workflow\NodeInterface;
use ReflectionClass;

class ConsoleExporter implements ExporterInterface
{
    /**
     * @param array<string, NodeInterface> $eventNodeMap
     */
    public function export(array $eventNodeMap): string
    {
        $output = "Workflow Structure:\n";
        $output .= \str_repeat("=", 50) . "\n\n";

        $connections = $this->buildConnections($eventNodeMap);

        return $output . $this->renderFlow($connections);
    }

    private function buildConnections(array $eventNodeMap): array
    {
        $connections = [];

        foreach ($eventNodeMap as $eventClass => $node) {
            $eventName = $this->getShortClassName($eventClass);
            $nodeName = $this->getShortClassName($node::class);

            // Get the events this node produces
            $producedEvents = $this->getProducedEvents($node);

            $connections[] = [
                'event' => $eventName,
                'node' => $nodeName,
                'produces' => $producedEvents,
                'eventClass' => $eventClass,
                'nodeClass' => $node::class
            ];
        }

        return $connections;
    }

    private function getProducedEvents(object $node): array
    {
        $reflection = new ReflectionClass($node);
        $runMethod = $reflection->getMethod('__invoke');
        $returnType = $runMethod->getReturnType();

        if (!$returnType) {
            return [];
        }

        $returnEventClasses = [];

        if ($returnType instanceof \ReflectionNamedType && !$returnType->isBuiltin()) {
            $returnEventClasses[] = $returnType->getName();
        } elseif ($returnType instanceof \ReflectionUnionType) {
            foreach ($returnType->getTypes() as $type) {
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $returnEventClasses[] = $type->getName();
                }
            }
        }

        return \array_map(fn (string $class): string => $this->getShortClassName($class), $returnEventClasses);
    }

    private function renderFlow(array $connections): string
    {
        $output = "";

        // Find the start event
        $startConnection = null;
        foreach ($connections as $connection) {
            if ($connection['event'] === 'StartEvent') {
                $startConnection = $connection;
                break;
            }
        }

        if (!$startConnection) {
            return "No StartEvent found in workflow.\n";
        }

        // Build a map for quick lookups
        $eventToConnection = [];
        foreach ($connections as $connection) {
            $eventToConnection[$connection['event']] = $connection;
        }

        // Render the flow starting from StartEvent
        $visited = [];
        $output .= $this->renderConnection($startConnection, $eventToConnection, $visited, 0);

        // Render any unvisited connections (orphaned nodes)
        foreach ($connections as $connection) {
            if (!\in_array($connection['event'], $visited)) {
                $output .= "\n" . \str_repeat("─", 30) . "\n";
                $output .= "Orphaned Node:\n";
                $output .= $this->renderConnection($connection, $eventToConnection, $visited, 0);
            }
        }

        return $output;
    }

    private function renderConnection(array $connection, array $eventToConnection, array &$visited, int $depth): string
    {
        if (\in_array($connection['event'], $visited)) {
            return \str_repeat("  ", $depth) . "↻ [Cycle detected]\n";
        }

        $visited[] = $connection['event'];
        $indent = \str_repeat("  ", $depth);
        $output = "";

        // Render current step
        if ($depth === 0) {
            $output .= $indent . "🏁 " . $connection['event'] . "\n";
        } else {
            $output .= $indent . "🔗 " . $connection['event'] . "\n";
        }

        $output .= $indent . "   ↓\n";
        $output .= $indent . "⚡ " . $connection['node'] . "\n";

        // Handle produced events
        if (!empty($connection['produces'])) {
            foreach ($connection['produces'] as $producedEvent) {
                if ($producedEvent === 'StopEvent') {
                    $output .= $indent . "   ↓\n";
                    $output .= $indent . "🏁 " . $producedEvent . "\n";
                } elseif (isset($eventToConnection[$producedEvent])) {
                    $output .= $indent . "   ↓\n";
                    $output .= $this->renderConnection($eventToConnection[$producedEvent], $eventToConnection, $visited, $depth + 1);
                } else {
                    $output .= $indent . "   ↓\n";
                    $output .= $indent . "❓ " . $producedEvent . " (no handler)\n";
                }
            }
        } else {
            $output .= $indent . "   ↓\n";
            $output .= $indent . "❓ (unknown output)\n";
        }

        return $output;
    }

    private function getShortClassName(string $class): string
    {
        $reflection = new ReflectionClass($class);
        return $reflection->getShortName();
    }
}
