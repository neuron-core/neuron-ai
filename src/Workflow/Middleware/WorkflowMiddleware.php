<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Middleware;

use Generator;
use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

/**
 * Interface for workflow middleware components.
 *
 * Middleware wraps node execution, allowing developers to inject custom behavior
 * before and after each node executes. Middleware is bound to specific node classes.
 *
 * Middleware can:
 * - Execute logic before node execution (validation, logging, etc.)
 * - Execute logic after node execution (transformation, caching, etc.)
 * - Access the node instance and workflow state
 * - Interrupt workflows for human-in-the-loop patterns
 * - Add cross-cutting concerns like monitoring, error handling, etc.
 *
 * Example usage:
 * ```php
 * class LoggingMiddleware implements WorkflowMiddleware
 * {
 *     public function before(NodeInterface $node, Event $event, WorkflowState $state): void
 *     {
 *         Log::info('Node starting', [
 *             'node' => $node::class,
 *             'event' => $event::class,
 *         ]);
 *     }
 *
 *     public function after(NodeInterface $node, Event $event, Event|Generator $result, WorkflowState $state): void
 *     {
 *         Log::info('Node completed', [
 *             'node' => $node::class,
 *             'result' => $result instanceof Generator ? 'Generator' : $result::class,
 *         ]);
 *     }
 * }
 * ```
 *
 * Register global middleware that runs on all nodes:
 * ```php
 * $workflow->globalMiddleware(new LoggingMiddleware());
 * ```
 *
 * Register middleware on specific nodes:
 * ```php
 * // Single node
 * $workflow->middleware(MyNode::class, new ValidationMiddleware());
 *
 * // Multiple nodes at once
 * $workflow->middleware([
 *     MyNode::class => [new LoggingMiddleware(), new ValidationMiddleware()],
 *     AnotherNode::class => new CachingMiddleware(),
 * ]);
 * ```
 */
interface WorkflowMiddleware
{
    /**
     * Execute before the node runs.
     *
     * This method is called before the node's __invoke method executes.
     * Use this for validation, logging, state preparation, etc.
     *
     * @param NodeInterface $node The node about to execute
     * @param Event $event The event being processed
     * @param WorkflowState $state The current workflow state
     * @return void
     */
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void;

    /**
     * Execute after the node runs.
     *
     * This method is called after the node's __invoke method completes.
     * Use this for logging, caching, result transformation, etc.
     *
     * Note: For streaming nodes that return Generators, this is called after
     * the generator is fully consumed, not after it's created.
     *
     * @param NodeInterface $node The node that executed
     * @param Event $event The input event that was processed
     * @param Event|Generator $result The result from the node (Event or Generator for streaming)
     * @param WorkflowState $state The current workflow state
     * @return void
     */
    public function after(NodeInterface $node, Event $event, Event|Generator $result, WorkflowState $state): void;
}
