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
 * Single reflection pass: the signature rules (an Event first — a concrete
 * class or an intersection with exactly one Event member — a WorkflowState
 * second, optionally the resources third, an Event/Generator return) and the
 * routed event-class extraction live together here, out of the Workflow bootstrap.
 */
class NodeSignature
{
    /**
     * The event class this node handles (the key in the event→node map).
     *
     * @return class-string<Event>
     * @throws WorkflowException when the __invoke signature is invalid, or the
     *                            segment's state or resources are not the types it declares.
     */
    public function eventClass(NodeInterface $node, WorkflowState $state, WorkflowResources $resources): string
    {
        try {
            $reflection = new ReflectionClass($node);

            if (!$reflection->hasMethod('__invoke')) {
                throw $this->invalid($node, 'Missing __invoke method');
            }

            $method = $reflection->getMethod('__invoke');
            $parameters = $method->getParameters();

            if (count($parameters) !== 2 && count($parameters) !== 3) {
                throw $this->invalid($node, '__invoke method must have 2 or 3 parameters');
            }

            $eventClass = $this->resolveEventClass($node, $parameters[0]->getType());

            $this->validateState($node, $parameters[1]->getType(), $state);

            if (isset($parameters[2])) {
                $this->validateResources($node, $parameters[2]->getType(), $resources);
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

            return $this->routable($node, $eventType);
        }

        if (!($type instanceof ReflectionNamedType) || !is_a($type->getName(), Event::class, true)) {
            throw $this->invalid($node, 'First parameter of __invoke method must be a type that implements ' . Event::class);
        }

        return $this->routable($node, $type);
    }

    /**
     * Events are routed by their exact class, so an interface or an abstract
     * class is never matched. A constructor that is not public is fine: such an
     * event is built through a named constructor.
     *
     * @return class-string<Event>
     * @throws WorkflowException
     * @throws ReflectionException
     */
    protected function routable(NodeInterface $node, ReflectionNamedType $type): string
    {
        /** @var class-string<Event> $class */
        $class = $type->getName();
        $event = new ReflectionClass($class);

        if ($event->isInterface() || $event->isAbstract()) {
            throw $this->invalid($node, "First parameter of __invoke method must be a concrete event class, {$class} can never be routed");
        }

        return $class;
    }

    /**
     * The graph is built with the segment's state, so a node that needs a state
     * the workflow does not provide fails here instead of mid-run.
     *
     * @throws WorkflowException
     */
    protected function validateState(NodeInterface $node, ?ReflectionType $type, WorkflowState $state): void
    {
        if (!($type instanceof ReflectionNamedType) || !is_a($type->getName(), WorkflowState::class, true)) {
            throw $this->invalid($node, 'Second parameter of __invoke method must be ' . WorkflowState::class);
        }

        $needed = $type->getName();
        if (!$state instanceof $needed) {
            throw $this->invalid($node, "__invoke method needs {$needed}, but the workflow provides " . $state::class);
        }
    }

    /**
     * The graph is built once the segment's resources exist, so a node that
     * needs more than the workflow provides fails here instead of mid-run.
     *
     * @throws WorkflowException
     */
    protected function validateResources(NodeInterface $node, ?ReflectionType $type, WorkflowResources $resources): void
    {
        if (!($type instanceof ReflectionNamedType) || !is_a($type->getName(), WorkflowResources::class, true)) {
            throw $this->invalid($node, 'Third parameter of __invoke method must be ' . WorkflowResources::class);
        }

        $needed = $type->getName();
        if (!$resources instanceof $needed) {
            throw $this->invalid($node, "__invoke method needs {$needed}, but the workflow provides " . $resources::class);
        }
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
