<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;
use JsonSerializable;

interface ContentBlockInterface extends JsonSerializable
{
    public function accumulateContent(string $content): void;

    public function getType(): ContentBlockType;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
