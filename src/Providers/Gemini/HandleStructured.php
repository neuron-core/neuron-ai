<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;

use function array_key_exists;
use function end;
use function is_array;
use function json_encode;
use function strtoupper;

trait HandleStructured
{
    public function structured(
        array|Message $messages,
        string $class,
        array $response_format
    ): Message {
        $messages = is_array($messages) ? $messages : [$messages];

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
                    $last_message->getContent() . ' Respond using this JSON schema: ' . json_encode($response_format)
                );
            }
        } else {
            $this->parameters['generationConfig']['responseSchema'] = $this->adaptSchema($response_format);
            $this->parameters['generationConfig']['responseMimeType'] = 'application/json';
        }

        return $this->chat($messages);
    }

    /**
     * Adapt Neuron schema to Gemini requirements.
     */
    protected function adaptSchema(array $schema): array
    {
        if (array_key_exists('additionalProperties', $schema)) {
            unset($schema['additionalProperties']);
        }

        if (array_key_exists('type', $schema)) {
            if (is_array($schema['type'])) {
                foreach ($schema['type'] as $type) {
                    if ($type !== 'null') {
                        $schema['type'] = strtoupper((string) $type);
                        break;
                    }
                }
            } else {
                $schema['type'] = strtoupper((string) $schema['type']);
            }

            $schema['type'] = match ($schema['type']) {
                'INT' => 'INTEGER',
                'BOOL' => 'BOOLEAN',
                'DOUBLE', 'FLOAT' => 'NUMBER',
                default => $schema['type']
            };
        }

        if (array_key_exists('properties', $schema) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $value) {
                if (is_array($value)) {
                    $schema['properties'][$key] = $this->adaptSchema($value);
                }
            }
            $schema['properties'] = (object) $schema['properties'];
        }

        if (array_key_exists('items', $schema) && is_array($schema['items'])) {
            $schema['items'] = $this->adaptSchema($schema['items']);
        }

        return $schema;
    }
}
