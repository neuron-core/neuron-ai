<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Generator;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\Event;
use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

use function array_filter;
use function count;
use function is_a;
use function reset;

/**
 * Validates a node's __invoke signature and resolves the event class it handles.
 *
 * Single reflection pass: the signature rules (two typed parameters, an Event
 * first — a concrete class or an intersection with exactly one Event member —
 * a WorkflowState second, an Event/Generator return) and the routed
 * event-class extraction live together here, out of the Workflow bootstrap.
 */
class NodeSignature
{
    /**
     * The event class this node handles (the key in the event→node map).
     *
     * @return class-string<Event>
     * @throws WorkflowException when the __invoke signature is invalid.
     */
    public function eventClass(NodeInterface $node): string
    {
        try {
            $reflection = new ReflectionClass($node);

            if (!$reflection->hasMethod('__invoke')) {
                throw $this->invalid($node, 'Missing __invoke method');
            }

            $method = $reflection->getMethod('__invoke');
            $parameters = $method->getParameters();

            if (count($parameters) !== 2) {
                throw $this->invalid($node, '__invoke method must have exactly 2 parameters');
            }

            $eventClass = $this->resolveEventClass($node, $parameters[0]->getType());

            $secondParamType = $parameters[1]->getType();
            if (!($secondParamType instanceof ReflectionNamedType) || !is_a($secondParamType->getName(), WorkflowState::class, true)) {
                throw $this->invalid($node, 'Second parameter of __invoke method must be ' . WorkflowState::class);
            }

            $this->validateReturnType($node, $method);

            return $eventClass;
        } catch (ReflectionException $e) {
            throw new WorkflowException('Failed to validate ' . $node::class . ': ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Resolve the routed event class from the first parameter's type: a named
     * Event class, or an intersection carrying exactly one Event member (the
     * other members further constrain the argument but don't affect routing).
     *
     * @return class-string<Event>
     * @throws WorkflowException
     */
    protected function resolveEventClass(NodeInterface $node, ?ReflectionType $type): string
    {
        if (!$type instanceof ReflectionType) {
            throw $this->invalid($node, 'First parameter of __invoke method must have a type declaration');
        }

        if ($type instanceof ReflectionUnionType) {
            throw $this->invalid($node, 'Nodes can handle only one event type.');
        }

        if ($type instanceof ReflectionIntersectionType) {
            $eventTypes = array_filter(
                $type->getTypes(),
                fn (ReflectionType $member): bool => $member instanceof ReflectionNamedType && is_a($member->getName(), Event::class, true)
            );

            if (count($eventTypes) !== 1) {
                throw $this->invalid($node, 'Intersection type must contain exactly one type that implements ' . Event::class);
            }

            /** @var ReflectionNamedType $eventType */
            $eventType = reset($eventTypes);

            /** @var class-string<Event> */
            return $eventType->getName();
        }

        if (!($type instanceof ReflectionNamedType) || !is_a($type->getName(), Event::class, true)) {
            throw $this->invalid($node, 'First parameter of __invoke method must be a type that implements ' . Event::class);
        }

        /** @var class-string<Event> */
        return $type->getName();
    }

    /**
     * @throws WorkflowException
     */
    protected function validateReturnType(NodeInterface $node, ReflectionMethod $method): void
    {
        $returnType = $method->getReturnType();

        if ($returnType instanceof ReflectionNamedType) {
            if (!is_a($returnType->getName(), Event::class, true) && !is_a($returnType->getName(), Generator::class, true)) {
                throw $this->invalid($node, '__invoke method must return a type that implements ' . Event::class);
            }
            return;
        }

        if ($returnType instanceof ReflectionUnionType) {
            foreach ($returnType->getTypes() as $type) {
                if (
                    !($type instanceof ReflectionNamedType) ||
                    (!is_a($type->getName(), Event::class, true) && !is_a($type->getName(), Generator::class, true))
                ) {
                    throw $this->invalid($node, 'All return types in union must implement ' . Event::class);
                }
            }
            return;
        }

        throw $this->invalid($node, '__invoke method must return a type that implements ' . Event::class);
    }

    protected function invalid(NodeInterface $node, string $reason): WorkflowException
    {
        return new WorkflowException('Failed to validate ' . $node::class . ': ' . $reason);
    }
}
