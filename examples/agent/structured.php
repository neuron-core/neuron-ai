<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;

require_once __DIR__ . '/../../vendor/autoload.php';


class Person
{
    public string $name;
    public int $age;
}

$result = Agent::make()
    ->setAiProvider(
        new Anthropic(
            '',
            'claude-5-sonnet'
        )
    )
    ->addTool(
        CalculatorToolkit::make()
    )
    ->structured(
        new UserMessage("Hi, I'm Valerio and I was bor in 1982, today is 2025, using the tool calculate my age!"),
        Person::class
    );

\var_dump($result);
