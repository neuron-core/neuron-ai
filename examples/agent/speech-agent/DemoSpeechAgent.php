<?php

declare(strict_types=1);

namespace NeuronAI\Tests\SpeechExperiment;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\SpeechExperiment\Events\SpeechStartEvent;
use NeuronAI\Tests\SpeechExperiment\Nodes\SpeechToTextNode;
use NeuronAI\Tests\SpeechExperiment\Nodes\TextToSpeechNode;
use NeuronAI\Tests\SpeechExperiment\Stub\FakeSpeechProvider;
use NeuronAI\Workflow\Node;
use RuntimeException;

class DemoSpeechAgent extends Agent
{
    /** @var string[] */
    public array $transcribed = [];

    /** @var string[] */
    public array $synthesized = [];

    public bool $failSynthesis = false;

    protected function provider(): AIProviderInterface
    {
        return new FakeAIProvider(new AssistantMessage('Hello! Your speech workflow is working.'));
    }

    protected function instructions(): string
    {
        return 'Answer briefly in natural spoken language.';
    }

    protected function speechToText(): AIProviderInterface
    {
        return new FakeSpeechProvider(function (Message $message): Message {
            $audio = $message->getAudio();
            $transcript = base64_decode($audio->content, true);
            if ($audio->sourceType !== SourceType::BASE64 || $transcript === false) {
                throw new RuntimeException('The fake transcriber expects base64-encoded text.');
            }
            $this->transcribed[] = $transcript;
            return new AssistantMessage($transcript);
        });
    }

    protected function textToSpeech(): AIProviderInterface
    {
        return new FakeSpeechProvider(function (Message $message): Message {
            $text = $message->getContent();
            $this->synthesized[] = $text;
            if ($this->failSynthesis) {
                throw new RuntimeException('Simulated speech provider failure.');
            }

            // Deliberately not playable audio: this fixture tests orchestration only.
            return new AssistantMessage(new AudioContent(base64_encode($text), SourceType::BASE64, 'application/x-fake-speech'));
        });
    }

    protected function startEvent(): AgentStartEvent
    {
        $event = parent::startEvent();
        return new SpeechStartEvent($event->messages, $event->options);
    }

    /** @return Node[] */
    protected function entryNodes(): array
    {
        return [new SpeechToTextNode($this->speechToText()), ...parent::entryNodes()];
    }

    /** @return Node[] */
    protected function exitNodes(): array
    {
        return [new TextToSpeechNode($this->textToSpeech())];
    }
}
