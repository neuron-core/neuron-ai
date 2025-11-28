<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

use NeuronAI\StructuredOutput\Validation\ValidationRuleInterface;

use function str_replace;

abstract class AbstractValidationRule implements ValidationRuleInterface
{
    protected function buildMessage(string $name, string $messageTemplate, array $vars = []): string
    {
        foreach ($vars as $key => $value) {
            $messageTemplate = str_replace('{'.$key.'}', (string) $value, $messageTemplate);
        }

        return str_replace('{name}', $name, $messageTemplate);
    }
}
