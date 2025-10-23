<?php

namespace NeuronAI\Agent;

class ToolCallChunk
{
    public function __construct(public readonly array $tools)
    {
    }
}
