<?php

declare(strict_types=1);

namespace NeuronAI\Tests\SpeechExperiment\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tests\SpeechExperiment\Events\SpeechStartEvent;
use NeuronAI\Workflow\Node;

use function array_shift;

class SpeechToTextNode extends Node
{
    public function __construct(protected AIProviderInterface $provider)
    {
    }

    public function __invoke(SpeechStartEvent $event, AgentState $state): AgentStartEvent
    {
        $state->delete('speech.audio');
        $messages = [];

        foreach ($event->messages as $messageIndex => $message) {
            if (!$message instanceof UserMessage || $message->getAudio() === null) {
                $messages[] = $message;
                continue;
            }

            $blocks = [];
            foreach ($message->getContentBlocks() as $blockIndex => $block) {
                $blocks[] = $block instanceof AudioContent
                    ? new TextContent($this->memoize(
                        "transcribe.{$messageIndex}.{$blockIndex}",
                        fn (): string => $this->provider->chat(new UserMessage($block))->message()->getContent()
                            ?? throw new ProviderException('Speech-to-text returned no transcript.'),
                    ))
                    : $block;
            }

            // The single-block overload replaces; the array overload appends.
            $transcribed = clone $message;
            $transcribed->setContents(array_shift($blocks));
            foreach ($blocks as $block) {
                $transcribed->addContent($block);
            }
            $messages[] = $transcribed;
        }

        return new AgentStartEvent($messages, clone $event->options);
    }
}
