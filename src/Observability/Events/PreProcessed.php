<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Chat\Messages\Message;

class PreProcessed
{
    public function __construct(
        public string $processor,
        public Message $processed
    ) {
    }
}
