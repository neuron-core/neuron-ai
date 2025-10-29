<?php

declare(strict_types=1);

namespace NeuronAI\Chat\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;

interface ContentBlock extends \JsonSerializable
{
    public function getType(): ContentBlockType;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
