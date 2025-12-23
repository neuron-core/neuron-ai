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

trait HandleStructured
{
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

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $this->adaptSchema($value);
            }
        }

        // Always an object also if it's empty
        if (array_key_exists('properties', $schema) && is_array($schema['properties'])) {
            $schema['properties'] = (object) $schema['properties'];
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
