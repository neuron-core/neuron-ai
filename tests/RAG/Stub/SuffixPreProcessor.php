<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Stub;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;

/** Appends a suffix to the query and records every query it received. */
class SuffixPreProcessor implements PreProcessorInterface
{
    /** @var Message[] */
    public array $received = [];

    public function __construct(protected string $suffix)
    {
    }

    public function process(Message $question): Message
    {
        $this->received[] = $question;

        return new UserMessage($question->getContent().$this->suffix);
    }
}
