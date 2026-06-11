<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;

use function array_key_exists;
use function end;
use function is_array;
use function json_encode;
use function in_array;

trait HandleStructured
{
    /**
     * Models that do not support structured output in combination with tools.
     */
    protected array $unsupportedModels = [
        'gemini-2.0-flash',
        'gemini-2.0-flash-lite-preview',
        'gemini-2.0-pro-preview',
        'gemini-2.0-flash-thinking-preview',
        'gemini-1.5-flash',
        'gemini-1.5-pro',
        'gemini-1.0-pro',
    ];

    /**
     * @throws ProviderException
     * @throws HttpException
     */
    public function structured(
        array|Message $messages,
        string $class,
        array $response_format
    ): Message {
        $messages = is_array($messages) ? $messages : [$messages];

        $originalParameters = $this->parameters;

        if (!array_key_exists('generationConfig', $this->parameters)) {
            $this->parameters['generationConfig'] = [
                'temperature' => 0,
            ];
        }

        try {
            // Gemini does not support structured output in combination with tools.
            // So we try to work with a JSON mode in case the agent has some tools defined.
            if (!empty($this->tools) && in_array($this->model, $this->unsupportedModels)) {
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

            return $this->chat(...$messages);
        } finally {
            $this->parameters = $originalParameters;
        }
    }

    /**
     * Adapt Neuron schema to Gemini requirements.
     */
    protected function adaptSchema(array $schema): array
    {
        if (array_key_exists('additionalProperties', $schema)) {
            unset($schema['additionalProperties']);
        }

        // properties: name → schema map — recurse per-property, then cast
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $name => $propertySchema) {
                $schema['properties'][$name] = $this->adaptSchema($propertySchema);
            }
            $schema['properties'] = (object) $schema['properties'];
        }

        // items: single sub-schema (array elements)
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->adaptSchema($schema['items']);
        }

        // anyOf: list of sub-schemas (discriminated unions)
        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $i => $subSchema) {
                $schema['anyOf'][$i] = $this->adaptSchema($subSchema);
            }
        }

        // Reduce the array type to a single not-nullable type
        if (isset($schema['type']) && is_array($schema['type'])) {
            foreach ($schema['type'] as $type) {
                if ($type !== 'null') {
                    $schema['type'] = $type;
                    break;
                }
            }
        }

        return $schema;
    }
}
