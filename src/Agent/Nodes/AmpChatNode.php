<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use GuzzleHttp\Promise\PromiseInterface;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\Messages\Message;

/**
 * Amp-compatible ChatNode that uses async inference.
 *
 * This node bridges Guzzle's async promises to Amp's Future system,
 * allowing the Fiber to suspend during HTTP requests instead of blocking.
 *
 * Usage:
 *
 *   $agent = Agent::make()->withChatNode(AmpChatNode::class);
 *   $handler = $agent->chat(new UserMessage('Hello'));
 *
 *   $executor = new AmpWorkflowExecutor();
 *   $future = $executor->execute($handler);
 *   $result = $future->await();
 *
 * Benefits:
 * - Multiple agents can run concurrently in separate Fibers
 * - HTTP requests don't block the entire workflow
 * - Clean separation between sync and async execution
 */
class AmpChatNode extends ChatNode
{
    /**
     * Override inference to use async operations with Amp.
     *
     * @param Message[] $messages
     */
    protected function inference(AIInferenceEvent $event, array $messages): Message
    {
        // Get the async promise from the provider
        $promise = $this->provider
            ->systemPrompt($event->instructions)
            ->setTools($event->tools)
            ->chatAsync($messages);

        // Bridge Guzzle promise to Amp Future
        return $this->awaitPromise($promise);
    }

    /**
     * Bridge a Guzzle promise to an Amp Future.
     *
     * This allows the Fiber to suspend while waiting for the HTTP response,
     * enabling true concurrent execution of multiple agents.
     *
     * The key insight is that Guzzle promises need to be actively resolved.
     * We use Amp's async() to run the promise resolution in a separate Fiber.
     */
    protected function awaitPromise(PromiseInterface $promise): Message
    {
        // Wrap the Guzzle promise wait() in an Amp async context
        // This allows the promise to resolve while the Fiber can suspend
        return \Amp\async(fn () => $promise->wait())->await();
    }
}
