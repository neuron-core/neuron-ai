<?php

declare(strict_types=1);

namespace NeuronAI\Providers\HuggingFace;

enum InferenceProvider: string
{
    case HF_INFERENCE = 'hf-inference';
    case CEREBRAS = 'cerebras';
    case COHERE = 'cohere';
    case FEATHERLESS_AI = 'featherless-ai';
    case FIREWORKS_AI = 'fireworks-ai';
    case GROQ = 'groq';
    case HYPERBOLIC = 'hyperbolic';
    case NEBIUS = 'nebius';
    case NSCALE = 'nscale';
    case SAMBANOVA = 'sambanova';
    case TOGETHER = 'together';
}
