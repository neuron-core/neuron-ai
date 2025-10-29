<?php

declare(strict_types=1);

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;

require_once __DIR__ . '/../../vendor/autoload.php';


$result = \NeuronAI\Agent\Agent::make()
    ->setAiProvider(
        new Anthropic(
            '',
            'claude-3-7-sonnet-latest'
        )
    )
    ->addTool(
        CalculatorToolkit::make()
    )
    ->chat(
        new UserMessage('Hi, using the tool you have, calculate the square root of 16!')
    );

\var_dump($result);
