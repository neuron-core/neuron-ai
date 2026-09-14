<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tests\SpeechExperiment\DemoSpeechAgent;

$agent = DemoSpeechAgent::make();
$state = $agent->chat(new UserMessage(new AudioContent(
    \base64_encode('Hello from the microphone.'),
    SourceType::BASE64,
    'application/x-fake-speech',
)));

echo 'Transcript: ' . $agent->transcribed[0] . \PHP_EOL;
echo 'Assistant: ' . $state->getMessage()->getContent() . \PHP_EOL;
echo 'Audio fixture: ' . $state->get('speech.audio')->content . \PHP_EOL;
echo 'Status: ' . $state->getStatus()->value . \PHP_EOL;
