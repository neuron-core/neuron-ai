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

        if (!array_key_exists('generation_config', $this->parameters)) {
            $this->parameters['generation_config'] = [
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
            // If there are no tools, we can enforce the structured output.
            $this->parameters['generation_config']['response_schema'] = $this->adaptSchema($response_format);
            $this->parameters['generation_config']['response_mime_type'] = 'application/json';
        }

        return $this->chat($messages);
    }

    /**
     * Adapt Neuron schema to Gemini requirements.
     * 1. Remove additionalProperties.
     * 2. Convert type arrays to single uppercase strings.
     * 3. Ensure properties is an object map.
     */
    protected function adaptSchema(array $schema): array
    {
        // 1. Gemini does not support additionalProperties
        if (array_key_exists('additionalProperties', $schema)) {
            unset($schema['additionalProperties']);
        }

        // 2. Fix the 'type' field
        if (array_key_exists('type', $schema)) {
            if (is_array($schema['type'])) {
                // Gemini doesn't support type arrays (e.g. ["string", "null"])
                // We pick the first non-null type.
                foreach ($schema['type'] as $type) {
                    if ($type !== 'null') {
                        $schema['type'] = strtoupper((string) $type);
                        break;
                    }
                }
            } else {
                $schema['type'] = strtoupper((string) $schema['type']);
            }

            // Map common types to Gemini expected types
            $schema['type'] = match ($schema['type']) {
                'INT' => 'INTEGER',
                'BOOL' => 'BOOLEAN',
                'DOUBLE', 'FLOAT' => 'NUMBER',
                default => $schema['type']
            };
        }

        // 3. Handle properties map
        if (array_key_exists('properties', $schema) && is_array($schema['properties'])) {
            $properties = [];
            foreach ($schema['properties'] as $key => $value) {
                // Neuron sometimes generates properties as an indexed array of objects
                // with 'name' and 'value' keys.
                $propName = $value['name'] ?? $value['propertyName'] ?? $key;
                $propSchema = $value['value'] ?? $value['schema'] ?? $value;

                if (is_array($propSchema)) {
                    $properties[$propName] = $this->adaptSchema($propSchema);
                }
            }
            $schema['properties'] = (object) $properties;
        }

        // 4. Handle nested items
        if (array_key_exists('items', $schema) && is_array($schema['items'])) {
            $schema['items'] = $this->adaptSchema($schema['items']);
        }

        return $schema;
    }
}
