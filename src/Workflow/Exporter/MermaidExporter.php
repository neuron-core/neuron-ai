<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Exporter;

use NeuronAI\Workflow\Workflow;
use ReflectionClass;

class MermaidExporter implements ExporterInterface
{
    public function export(Workflow $graph): string
    {
        $output = "graph TD\n";
        $eventNodeMap = $graph->getEventNodeMap();
        $processedConnections = [];

        foreach ($eventNodeMap as $eventClass => $node) {
            $eventName = $this->getShortClassName($eventClass);
            $nodeName = $this->getShortClassName(get_class($node));
            
            // Add connection from event to node
            $connection = "{$eventName} --> {$nodeName}";
            if (!in_array($connection, $processedConnections)) {
                $output .= "    {$connection}\n";
                $processedConnections[] = $connection;
            }
            
            // Try to determine what event this node produces by looking at return type
            $reflection = new ReflectionClass($node);
            $runMethod = $reflection->getMethod('run');
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
                    if (!in_array($connection, $processedConnections)) {
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
