<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use GuzzleHttp\Exception\RequestException;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIResponseEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\ToolMaxTriesException;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\Deserialized;
use NeuronAI\Observability\Events\Deserializing;
use NeuronAI\Observability\Events\Extracted;
use NeuronAI\Observability\Events\Extracting;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\SchemaGenerated;
use NeuronAI\Observability\Events\SchemaGeneration;
use NeuronAI\Observability\Events\Validated;
use NeuronAI\Observability\Events\Validating;
use NeuronAI\Observability\Observable;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
use NeuronAI\StructuredOutput\JsonExtractor;
use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\Validation\Validator;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StartEvent;

/**
 * Node responsible for handling structured output requests with retry logic.
 */
class StructuredOutputNode extends Node
{
    use Observable;

    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(
        protected AIProviderInterface $provider,
        protected string $instructions,
        protected array $tools,
        protected string $class,
        protected int $maxRetries = 1
    ) {
    }

    /**
     * @throws \Throwable
     */
    public function __invoke(StartEvent $event, AgentState $state): AIResponseEvent
    {
        $chatHistory = $state->getChatHistory();

        // Generate JSON schema if not already generated
        if (!$state->has('structured_schema')) {
            $this->notify('schema-generation', new SchemaGeneration($this->class));
            $schema = JsonSchema::make()->generate($this->class);
            $this->notify('schema-generated', new SchemaGenerated($this->class, $schema));
            $state->set('structured_schema', $schema);
        }

        $schema = $state->get('structured_schema');
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
                    $chatHistory->addMessage($correctionMessage);
                }

                $messages = $chatHistory->getMessages();

                $last = clone $chatHistory->getLastMessage();
                $this->notify(
                    'inference-start',
                    new InferenceStart($last)
                );

                $response = $this->provider
                    ->systemPrompt($this->instructions)
                    ->setTools($this->tools)
                    ->structured($messages, $this->class, $schema);

                $this->notify(
                    'inference-stop',
                    new InferenceStop($last, $response)
                );

                $chatHistory->addMessage($response);

                // If the response is a tool call, route to tool execution
                if ($response instanceof ToolCallMessage) {
                    return new AIResponseEvent($response);
                }

                // Process the response: extract, deserialize, and validate
                $output = $this->processResponse($response, $schema, $this->class);

                // Store the structured output in state
                $state->set('structured_output', $output);

                return new AIResponseEvent($response);

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

            $this->maxRetries--;
        } while ($this->maxRetries >= 0);

        throw $exception;
    }

    /**
     * Process the response: extract JSON, deserialize, and validate.
     *
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
        $obj = Deserializer::make()->fromJson($json, $class);
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
}
