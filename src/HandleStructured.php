<?php

declare(strict_types=1);

namespace NeuronAI;

use GuzzleHttp\Exception\RequestException;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ToolMaxTriesException;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\Deserialized;
use NeuronAI\Observability\Events\Deserializing;
use NeuronAI\Observability\Events\Extracted;
use NeuronAI\Observability\Events\Extracting;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Observability\Events\SchemaGenerated;
use NeuronAI\Observability\Events\SchemaGeneration;
use NeuronAI\Observability\Events\Validated;
use NeuronAI\Observability\Events\Validating;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
use NeuronAI\StructuredOutput\JsonExtractor;
use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\Validation\Validator;

trait HandleStructured
{
    /**
     * Enforce a structured response.
     *
     * @throws AgentException
     * @throws \ReflectionException
     * @throws \Throwable
     */
    public function structured(Message|array $messages, ?string $class = null, int $maxRetries = 1): mixed
    {
        $this->notify('structured-start');

        $this->addToChatHistory($messages);

        $tools = $this->bootstrapTools();

        // Get the JSON schema from the response model
        $class ??= $this->getOutputClass();
        $this->notify('schema-generation', new SchemaGeneration($class));
        $schema = (new JsonSchema())->generate($class);
        $this->notify('schema-generated', new SchemaGenerated($class, $schema));

        $error = '';
        do {
            try {
                // If something goes wrong, retry informing the model about the error
                if (\trim($error) !== '') {
                    $correctionMessage = new UserMessage(
                        "There was a problem in your previous response that generated the following errors".
                        \PHP_EOL.\PHP_EOL.'- '.$error.\PHP_EOL.\PHP_EOL.
                        "Try to generate the correct JSON structure based on the provided schema."
                    );
                    $this->addToChatHistory($correctionMessage);
                }

                $messages = $this->resolveChatHistory()->getMessages();

                $last = clone $this->resolveChatHistory()->getLastMessage();
                $this->notify(
                    'inference-start',
                    new InferenceStart($last)
                );
                $response = $this->resolveProvider()
                    ->systemPrompt($this->resolveInstructions())
                    ->setTools($tools)
                    ->structured($messages, $class, $schema);
                $this->notify(
                    'inference-stop',
                    new InferenceStop($last, $response)
                );

                $this->addToChatHistory($response);

                if ($response instanceof ToolCallMessage) {
                    $toolCallResult = $this->executeTools($response);
                    return self::structured($toolCallResult, $class, $maxRetries);
                }

                $this->addToChatHistory($response);

                $output = $this->processResponse($response, $schema, $class);
                $this->notify('structured-stop');
                return $output;
            } catch (RequestException $ex) {
                $exception = $ex;
                $error = $ex->getResponse()?->getBody()->getContents() ?? $ex->getMessage();
                $this->notify('error', new AgentError($ex, false));
            } catch (ToolMaxTriesException $ex) {
                // If the problem is a tool max tries exception, we don't want to retry
                throw $ex;
            } catch (\Exception $ex) {
                $exception = $ex;
                $error = $ex->getMessage();
                $this->notify('error', new AgentError($ex, false));
            }

            $maxRetries--;
        } while ($maxRetries >= 0);

        throw $exception;
    }

    /**
     * @param array<string, mixed> $schema
     * @throws AgentException
     * @throws DeserializerException
     * @throws \ReflectionException
     */
    protected function processResponse(
        Message $response,
        array $schema,
        string $class,
    ): object {
        // Try to extract a valid JSON object from the LLM response
        $this->notify('structured-extracting', new Extracting($response));
        $json = (new JsonExtractor())->getJson($response->getContent());
        $this->notify('structured-extracted', new Extracted($response, $schema, $json));
        if ($json === null || $json === '') {
            throw new AgentException("The response does not contains a valid JSON Object.");
        }

        // Deserialize the JSON response from the LLM into an instance of the response model
        $this->notify('structured-deserializing', new Deserializing($class));
        $obj = Deserializer::fromJson($json, $class);
        $this->notify('structured-deserialized', new Deserialized($class));

        // Validate if the object fields respect the validation attributes
        $this->notify('structured-validating', new Validating($class, $json));
        $violations = Validator::validate($obj);
        if (\count($violations) > 0) {
            $this->notify('structured-validated', new Validated($class, $json, $violations));
            throw new AgentException(\PHP_EOL.'- '.\implode(\PHP_EOL.'- ', $violations));
        }
        $this->notify('structured-validated', new Validated($class, $json));

        return $obj;
    }

    /**
     * @throws AgentException
     */
    protected function getOutputClass(): string
    {
        throw new AgentException('You need to specify an output class.');
    }
}
