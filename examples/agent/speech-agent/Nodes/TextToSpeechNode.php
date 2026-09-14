<?php

declare(strict_types=1);

namespace NeuronAI\Tests\SpeechExperiment\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;

class TextToSpeechNode extends Node
{
    public function __construct(protected AIProviderInterface $provider)
    {
    }

    public function __invoke(AgentOutputEvent $event, AgentState $state): StopEvent
    {
        $text = $state->getMessage()?->getContent();
        if ($text !== null) {
            $state->set('speech.audio', $this->memoize(
                'synthesize',
                fn (): AudioContent => $this->provider->chat(new UserMessage($text))->message()->getAudio()
                    ?? throw new ProviderException('Text-to-speech returned no audio.'),
            ));
        }

        return new StopEvent();
    }
}
