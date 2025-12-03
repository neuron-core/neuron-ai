<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use GuzzleHttp\Exception\RequestException;
use Inspector\Exceptions\InspectorException;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\ToolMaxTriesException;
use NeuronAI\Observability\EventBus;
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
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
use NeuronAI\StructuredOutput\JsonExtractor;
use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\Validation\Validator;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use Exception;
use ReflectionException;

use function count;
use function implode;
use function trim;

use const PHP_EOL;

/**
 * Node responsible for handling structured output requests with retry logic.
 *
 * Receives an AIInferenceEvent containing instructions, tools, output class,
 * and max retries that can be modified by middleware before the actual inference call is made.
 */
class StructuredOutputNode extends Node
{
    use ChatHistoryHelper;

    public function __construct(
        protected AIProviderInterface $provider,
        protected readonly string $outputClass,
        protected int $maxTries = 1,
    ) {
    }

    /**
     * @throws ReflectionException
     * @throws InspectorException
     * @throws ToolMaxTriesException
     */
    public function __invoke(AIInferenceEvent $event, AgentState $state): ToolCallEvent|StopEvent
    {
        $chatHistory = $state->getChatHistory();

        // Generate JSON schema if not already generated
        if (!$state->has('structured_schema')) {
            EventBus::emit('schema-generation', $this, new SchemaGeneration($this->outputClass));
            $schema = JsonSchema::make()->generate($this->outputClass);
            EventBus::emit('schema-generated', $this, new SchemaGenerated($this->outputClass, $schema));
            $state->set('structured_schema', $schema);
        }

        $schema = $state->get('structured_schema');
        $error = '';

        do {
            try {
                // If something goes wrong, retry informing the model about the error
                if (trim($error) !== '') {
                    $correctionMessage = new UserMessage(
                        "There was a problem in your previous response that generated the following errors".
                        PHP_EOL.PHP_EOL.'- '.$error.PHP_EOL.PHP_EOL.
                        "Try to generate the correct JSON structure based on the provided schema."
                    );
                    $this->addToChatHistory($state, $correctionMessage);
                }

                $messages = $chatHistory->getMessages();

                $last = clone $chatHistory->getLastMessage();
                EventBus::emit(
                    'inference-start',
                    $this,
                    new InferenceStart($last)
                );

                // Use instructions and tools from the event
                $response = $this->provider
                    ->systemPrompt($event->instructions)
                    ->setTools($event->tools)
                    ->structured($messages, $this->outputClass, $schema);

                EventBus::emit(
                    'inference-stop',
                    $this,
                    new InferenceStop($last, $response)
                );

                $this->addToChatHistory($state, $response);

                // If the response is a tool call, route to tool execution
                if ($response instanceof ToolCallMessage) {
                    return new ToolCallEvent($response, $event);
                }

                // Process the response: extract, deserialize, and validate
                $output = $this->processResponse($response, $schema, $this->outputClass);

                // Store the structured output in state
                $state->set('structured_output', $output);

                return new StopEvent();

            } catch (RequestException $ex) {
                $exception = $ex;
                $error = $ex->getResponse()?->getBody()->getContents() ?? $ex->getMessage();
                EventBus::emit('error', $this, new AgentError($ex, false));
            } catch (ToolMaxTriesException $ex) {
                // If the problem is a tool max tries exception, we don't want to retry
                throw $ex;
            } catch (Exception $ex) {
                $exception = $ex;
                $error = $ex->getMessage();
                EventBus::emit('error', $this, new AgentError($ex, false));
            }

            $this->maxTries--;
        } while ($this->maxTries >= 0);

        throw $exception;
    }

    /**
     * Process the response: extract JSON, deserialize, and validate.
     *
     * @param array<string, mixed> $schema
     * @throws AgentException
     * @throws DeserializerException
     * @throws ReflectionException
     * @throws InspectorException
     */
    protected function processResponse(
        Message $response,
        array $schema,
        string $class,
    ): object {
        // Try to extract a valid JSON object from the LLM response
        EventBus::emit('structured-extracting', $this, new Extracting($response));
        $json = (new JsonExtractor())->getJson($response->getContent());
        EventBus::emit('structured-extracted', $this, new Extracted($response, $schema, $json));
        if ($json === null || $json === '') {
            throw new AgentException("The response does not contains a valid JSON Object.");
        }

        // Deserialize the JSON response from the LLM into an instance of the response model
        EventBus::emit('structured-deserializing', $this, new Deserializing($class));
        $obj = Deserializer::make()->fromJson($json, $class);
        EventBus::emit('structured-deserialized', $this, new Deserialized($class));

        // Validate if the object fields respect the validation attributes
        EventBus::emit('structured-validating', $this, new Validating($class, $json));
        $violations = Validator::validate($obj);
        if (count($violations) > 0) {
            EventBus::emit('structured-validated', $this, new Validated($class, $json, $violations));
            throw new AgentException(PHP_EOL.'- '.implode(PHP_EOL.'- ', $violations));
        }
        EventBus::emit('structured-validated', $this, new Validated($class, $json));

        return $obj;
    }
}
