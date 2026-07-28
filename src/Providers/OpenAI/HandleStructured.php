<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\ProviderResponse;

use function end;
use function explode;
use function preg_match;
use function preg_replace;
use function array_replace_recursive;
use function is_array;

trait HandleStructured
{
    /**
     * @throws ProviderException
     * @throws HttpException
     */
    public function structured(
        array|Message $messages,
        string $class,
        array $response_format,
    ): ProviderResponse {
        $originalParameters = $this->parameters;

        try {
            $tk = explode('\\', $class);
            $className = end($tk);

            if ($this->strict_response) {
                $response_format = $this->enforceStrictSchema($response_format);
            }

            $this->parameters = array_replace_recursive($this->parameters, [
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'strict' => $this->strict_response,
                        "name" => $this->sanitizeClassName($className),
                        "schema" => $response_format,
                    ],
                ],
            ]);

            return $this->chat(...(is_array($messages) ? $messages : [$messages]));
        } finally {
            $this->parameters = $originalParameters;
        }
    }

    protected function sanitizeClassName(string $name): string
    {
        // Remove anonymous class markers and special characters
        $name = preg_replace('/class@anonymous.*$/', 'anonymous', $name);
        // Replace any non-alphanumeric characters with underscore
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $name);
        // Ensure it starts with a letter
        if (preg_match('/^[^a-zA-Z]/', (string) $name)) {
            return 'class_' . $name;
        }
        return $name;
    }

    /**
     * Recursively inject `additionalProperties: false` into every object of the
     * JSON schema, as required by OpenAI strict mode.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function enforceStrictSchema(array $schema): array
    {
        if (($schema['type'] ?? null) === 'object') {
            $schema['additionalProperties'] = false;
        }

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $this->enforceStrictSchema($value);
            }
        }

        return $schema;
    }

}
