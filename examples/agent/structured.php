<?php

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;

require_once __DIR__ . '/../../vendor/autoload.php';


class Person
{
    public string $name;
    public int $age;
}

$result = \NeuronAI\Agent\Agent::make()
    ->setAiProvider(
        new Anthropic(
            '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',
            'claude-3-7-sonnet-latest'
        )
    )
    ->addTool(
        CalculatorToolkit::make()
    )
    ->structured(
        new UserMessage("Hi, I'm Valerio and I was bor in 1982, today is 2025, using the tool calculate my age!"),
        Person::class
    );

var_dump($result);
