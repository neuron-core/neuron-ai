<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;

use function array_key_exists;
use function end;
use function is_array;
use function json_encode;

trait HandleStructured
{
    public function structured(
        array $messages,
        string $class,
        array $response_format
    ): Message {
        if (!array_key_exists('generationConfig', $this->parameters)) {
            $this->parameters['generationConfig'] = [
                'temperature' => 0,
            ];
        }

        // Gemini does not support structured output in combination with tools.
        // So we try to work with a JSON mode in case the agent has some tools defined.
        if (!empty($this->tools)) {
            $last_message = end($messages);
            if ($last_message instanceof Message && $last_message->getRole() === MessageRole::USER->value) {
                $last_message->setContents(
                    $last_message->getContent() . ' Respond using this JSON schema: '.json_encode($response_format)
                );
            }
        } else {
            // If there are no tools, we can enforce the structured output.
            $this->parameters['generationConfig']['response_schema'] = $this->adaptSchema($response_format);
            $this->parameters['generationConfig']['response_mime_type'] = 'application/json';
        }

        return $this->chat($messages);
    }

    /**
     * Gemini does not support additionalProperties attribute.
     */
    protected function adaptSchema(array $schema): array
    {
        if (array_key_exists('additionalProperties', $schema)) {
            unset($schema['additionalProperties']);
        }

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $this->adaptSchema($value);
            }
        }

        return $schema;
    }
}
