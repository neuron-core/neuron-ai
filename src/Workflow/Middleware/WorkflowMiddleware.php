<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Middleware;

use Closure;
use Generator;
use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\WorkflowState;

/**
 * Interface for workflow middleware components.
 *
 * Middleware intercepts the event flow between workflow nodes, allowing
 * developers to inject custom behavior at any point in the workflow's execution.
 *
 * Middleware can:
 * - Inspect and modify events before they reach nodes
 * - Transform node outputs
 * - Interrupt workflows for human-in-the-loop patterns
 * - Add logging, validation, caching, etc.
 * - Short-circuit execution
 *
 * Example usage:
 * ```php
 * class LoggingMiddleware implements WorkflowMiddleware
 * {
 *     public function handle(Event $event, WorkflowState $state, Closure $next): Event|Generator
 *     {
 *         Log::info('Event received', ['type' => get_class($event)]);
 *
 *         $result = $next($event);
 *
 *         Log::info('Event processed', ['type' => get_class($result)]);
 *
 *         return $result;
 *     }
 *
 *     public function shouldHandle(Event $event): bool
 *     {
 *         return true; // Handle all events
 *     }
 * }
 * ```
 */
interface WorkflowMiddleware
{
    /**
     * Handle the event through the middleware pipeline.
     *
     * @param Event $event The event being processed
     * @param WorkflowState $state The current workflow state
     * @param Closure $next Callback to invoke next middleware or node
     *                      Signature: function(Event $event): Event|Generator
     *
     * @return Event|Generator The processed event or generator for streaming
     */
    public function handle(Event $event, WorkflowState $state, Closure $next): Event|Generator;

    /**
     * Determine if this middleware should handle the given event.
     *
     * This allows middleware to be selective about which events they process.
     * Return true to handle the event, false to skip this middleware.
     *
     * @param Event $event The event to check
     * @return bool True if this middleware should handle the event
     */
    public function shouldHandle(Event $event): bool;
}
