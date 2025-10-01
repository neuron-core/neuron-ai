<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use NeuronAI\Chat\Messages\Message;

trait HandleResponsesStructured
{
    public function structured(
        array $messages,
        string $class,
        array $response_format,
    ): Message {
        $tk = \explode('\\', $class);
        $className = \end($tk);

        $this->parameters = \array_merge($this->parameters, [
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'strict' => $this->strict_response,
                    "name" => $this->sanitizeClassName($className),
                    "schema" => $response_format,
                ],
            ]
        ]);

        return $this->chat($messages);
    }

    protected function sanitizeClassName(string $name): string
    {
        // Remove anonymous class markers and special characters
        $name = \preg_replace('/class@anonymous.*$/', 'anonymous', $name);
        // Replace any non-alphanumeric characters with underscore
        $name = \preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $name);
        // Ensure it starts with a letter
        if (\preg_match('/^[^a-zA-Z]/', (string) $name)) {
            return 'class_' . $name;
        }
        return $name;
    }
}
