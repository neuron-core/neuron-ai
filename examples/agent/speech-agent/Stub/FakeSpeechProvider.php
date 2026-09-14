<?php

declare(strict_types=1);

namespace NeuronAI\Tests\SpeechExperiment\Stub;

use Closure;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;

class FakeSpeechProvider extends FakeAIProvider
{
    /** @param Closure(Message): Message $respond */
    public function __construct(protected Closure $respond)
    {
        parent::__construct();
    }

    public function chat(Message ...$messages): ProviderResponse
    {
        return new ProviderResponse(message: ($this->respond)($messages[0]));
    }
}
