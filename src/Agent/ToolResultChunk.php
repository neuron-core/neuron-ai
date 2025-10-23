<?php

namespace NeuronAI\Agent;

class ToolResultChunk
{
    public function __construct(public readonly array $tools)
    {
    }
}
