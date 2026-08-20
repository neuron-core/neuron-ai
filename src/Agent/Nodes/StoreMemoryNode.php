<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\StoreMemoryEvent;
use NeuronAI\Agent\Memory\MemoryInterface;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\StepFinishedStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\StepStartedStreamEvent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Observability\Events\MemoryStored;
use NeuronAI\Observability\Events\MemoryStoring;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use function array_reverse;

class StoreMemoryNode extends Node implements AgentNodeInterface
{
    use ChatHistoryHelper;

    public function __construct(
        protected MemoryInterface $memory,
        ChatHistoryInterface $chatHistory,
    ) {
        $this->chatHistory = $chatHistory;
    }

    public function __invoke(StoreMemoryEvent $event, AgentState $state): Generator
    {
        [$user, $assistant] = $this->exchange($event->messages);

        if (!$user instanceof UserMessage || !$assistant instanceof AssistantMessage) {
            return new StopEvent();
        }

        $userContent = $user->getContent();
        $assistantContent = $assistant->getContent();

        if ($userContent === null || $assistantContent === null) {
            return new StopEvent();
        }

        $threadId = $this->chatHistory->getThreadId() ?? throw new ChatHistoryException(
            'Cannot use Agent memory without a thread identity.'
        );

        $this->emit(new MemoryStoring());
        yield new StepStartedStreamEvent('memory.store');

        $this->memoize('memory.remember', function () use ($threadId, $userContent, $assistantContent): bool {
            $this->memory->remember($threadId, $userContent, $assistantContent);

            return true;
        });

        $this->emit(new MemoryStored());
        yield new StepFinishedStreamEvent('memory.store');

        return new StopEvent();
    }

    /**
     * @param Message[] $messages
     * @return array{UserMessage|null, AssistantMessage|null}
     */
    protected function exchange(array $messages): array
    {
        $user = null;
        $assistant = null;

        foreach (array_reverse($messages) as $message) {
            if (
                $user === null
                && $message instanceof UserMessage
                && !$message instanceof ToolResultMessage
            ) {
                $user = $message;
            }

            if (
                $assistant === null
                && $message instanceof AssistantMessage
                && !$message instanceof ToolCallMessage
            ) {
                $assistant = $message;
            }
        }

        if ($user === null) {
            foreach (array_reverse($this->chatHistory->getMessages()) as $message) {
                if ($message instanceof UserMessage && !$message instanceof ToolResultMessage) {
                    $user = $message;
                    break;
                }
            }
        }

        return [$user, $assistant];
    }
}
