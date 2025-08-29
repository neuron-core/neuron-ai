<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Exporter;

use NeuronAI\Workflow\NodeInterface;
use ReflectionClass;

class MermaidExporter implements ExporterInterface
{
    /**
     * @param array<string, NodeInterface> $eventNodeMap
     * @throws \ReflectionException
     */
    public function export(array $eventNodeMap): string
    {
        $output = "graph TD\n";
        $processedConnections = [];

        foreach ($eventNodeMap as $eventClass => $node) {
            $eventName = $this->getShortClassName($eventClass);
            $nodeName = $this->getShortClassName($node::class);

            // Add connection from event to node
            $connection = "{$eventName} --> {$nodeName}";
            if (!\in_array($connection, $processedConnections)) {
                $output .= "    {$connection}\n";
                $processedConnections[] = $connection;
            }

            // Try to determine what event this node produces by looking at return type
            $reflection = new ReflectionClass($node);
            $runMethod = $reflection->getMethod('__invoke');
            $returnType = $runMethod->getReturnType();

            if ($returnType) {
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

                foreach ($returnEventClasses as $returnEventClass) {
                    $returnEventName = $this->getShortClassName($returnEventClass);

                    // Add connection from node to produced event
                    $connection = "{$nodeName} --> {$returnEventName}";
                    if (!\in_array($connection, $processedConnections)) {
                        $output .= "    {$connection}\n";
                        $processedConnections[] = $connection;
                    }
                }
            }
        }

        return $output;
    }

    private function getShortClassName(string $class): string
    {
        $reflection = new ReflectionClass($class);
        return $reflection->getShortName();
    }
}
