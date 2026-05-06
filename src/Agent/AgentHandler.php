<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use NeuronAI\Providers\ProviderResponse;
use Throwable;
use RuntimeException;

class AgentHandler
{
    protected ?AgentState $result = null;

    public function __construct(
        protected Generator $generator,
    ) {
    }

    /**
     * Stream workflow events, optionally through a protocol adapter.
     *
     * @phpstan-impure
     */
    public function events(?StreamAdapterInterface $adapter = null): Generator
    {
        if ($adapter instanceof StreamAdapterInterface) {
            foreach ($adapter->start() as $output) {
                yield $output;
            }
        }

        while ($this->generator->valid()) {
            $event = $this->generator->current();

            if ($adapter instanceof StreamAdapterInterface) {
                foreach ($adapter->transform($event) as $output) {
                    yield $output;
                }
            } else {
                yield $event;
            }

            $this->generator->next();
        }

        /** @var AgentState $state */
        $state = $this->generator->getReturn();
        $this->result = $state;

        if ($adapter instanceof StreamAdapterInterface) {
            foreach ($adapter->end() as $output) {
                yield $output;
            }
        }

        return $this->result;
    }

    /**
     * Run to completion and return the final state.
     *
     * @throws Throwable
     */
    public function run(): AgentState
    {
        if ($this->result instanceof AgentState) {
            return $this->result;
        }

        foreach ($this->events() as $event) {
        }

        if (!$this->result instanceof AgentState) {
            throw new RuntimeException('Agent execution produced no result');
        }

        return $this->result;
    }

    /**
     * Convenience: run to completion and extract the final Message.
     *
     * @throws Throwable
     */
    public function getMessage(): Message
    {
        return $this->run()->getMessage();
    }

    /**
     * Convenience: run to completion and extract the ProviderResponse.
     *
     * @throws Throwable
     */
    public function getResponse(): ProviderResponse
    {
        return $this->run()->getResponse();
    }
}
