<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OrcaRouter;

use NeuronAI\Providers\OpenAI\OpenAI;

class OrcaRouter extends OpenAI
{
    protected string $baseUri = 'https://api.orcarouter.ai/v1';
}
