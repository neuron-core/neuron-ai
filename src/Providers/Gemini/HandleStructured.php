<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;

use function array_key_exists;
use function end;
use function is_array;
use function json_encode;

/**
 * Structured outputs with tools is available only for the Gemini 3 series models: gemini-3-pro-preview and gemini-3-flash-preview.
 * See: https://ai.google.dev/gemini-api/docs/structured-output?example=recipe#structured_outputs_with_tools
 */
const MODELS_SUPPORTING_TOOL_CALLING_AND_STRUCTURED_OUTPUT_TOGETHER = [
    'gemini-3-pro-preview',
    'gemini-3-flash-preview',
];

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
        if (!empty($this->tools) && !in_array($this->model, MODELS_SUPPORTING_TOOL_CALLING_AND_STRUCTURED_OUTPUT_TOGETHER)) {
            $last_message = end($messages);
            if ($last_message instanceof Message && $last_message->getRole() === MessageRole::USER->value) {
                $last_message->setContent(
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

        // PHP generates nullable types as an array: {"type": ["string", "null"]}.
        // Gemini's v1beta protocol strictly forbids array types. It requires a single
        // string type combined with a separate "nullable": true boolean flag.
        // @see https://ai.google.dev/api/generate-content#generationconfig
        if (array_key_exists('type', $schema) && is_array($schema['type'])) {
            $types = array_filter($schema['type'], fn ($t) => $t !== 'null');
            $schema['type'] = array_shift($types);
            $schema['nullable'] = true;
        }

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $this->adaptSchema($value);
            }
        }

        return $schema;
    }
}
