<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\StoreMemoryEvent;
use NeuronAI\Agent\Events\StructuredInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\ChatHistoryException;
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
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
use NeuronAI\StructuredOutput\JsonExtractor;
use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\Validation\Validator;
use NeuronAI\Workflow\Events\StopEvent;
use ReflectionException;
use function count;
use function end;
use function implode;
use function max;
use function trim;
use const PHP_EOL;

/**
 * Node responsible for handling structured output requests with retry logic.
 *
 * Routed by its ingress event's exact class; the output class and retry
 * budget are inference intent carried on the event, so the node itself is
 * constructible by any blank factory.
 */
class StructuredOutputNode extends InferenceNode
{
    /**
     * @throws AgentException
     * @throws DeserializerException
     * @throws ReflectionException
     * @throws ChatHistoryException
     */
    public function __invoke(StructuredInferenceEvent $event, AgentState $state): ToolCallEvent|StopEvent|StoreMemoryEvent
    {
        $outputClass = $event->outputClass
            ?? throw new AgentException('Structured inference requires an output class on the event.');
        $maxTries = max(1, $event->maxTries);
        // User-side messages (inbound, then corrections) awaiting a successful
        // provider call before being committed to the chat history.
        $pending = $event->getMessages();

        if (!$state->has('structured_schema')) {
            $this->emit(new SchemaGeneration($outputClass));
            $schema = JsonSchema::make()->generate($outputClass);
            $this->emit(new SchemaGenerated($outputClass, $schema));
            $state->set('structured_schema', $schema);
        }

        $schema = $state->get('structured_schema');
        $error = '';
        $attempt = 0;

        do {
            try {
                if (trim($error) !== '') {
                    $pending[] = new UserMessage(
                        "There was a problem in your previous response that generated the following error:\n\n{$error}\n\n".
                        "Try to generate the correct JSON structure based on the provided schema."
                    );
                }

                $messages = $this->pendingConversation($pending);

                $last = clone end($messages);

                $this->emit(new InferenceStart($last));

                // Each retry attempt is a distinct non-deterministic inference
                // call, so the memo key is attempt-indexed: on replay a
                // previously-succeeded attempt is recalled without re-calling
                // the provider.
                $providerResponse = $this->memoize(
                    "inference.{$attempt}",
                    fn (): ProviderResponse => $this->provider
                        ->systemPrompt($event->instructions)
                        ->setTools($event->tools)
                        ->structured($messages, $outputClass, $schema),
                );

                $this->addToChatHistory($pending, "history.inbound.{$attempt}");
                $pending = [];

                $message = $providerResponse->message();

                $this->emit(new InferenceStop($last, $providerResponse));

                if ($message instanceof ToolCallMessage) {
                    return new ToolCallEvent($message, $event);
                }

                // The response memo is attempt-indexed too: a shared name would
                // silently skip the write of every retry's corrected response.
                $this->addToChatHistory($message, "history.response.{$attempt}");

                $output = $this->processResponse($message, $schema, $outputClass);

                $state->set('structured_output', $output);
                $state->setResponse($providerResponse);

                return $this->routeThroughMemory
                    ? new StoreMemoryEvent([...$event->getMessages(), $message])
                    : new StopEvent();

            } catch (AgentException|DeserializerException $ex) {
                $lastException = $ex;
                $error = $ex->getMessage();
            }

            $attempt++;
        } while ($attempt <= $maxTries);

        throw $lastException;
    }

    /**
     * Process the response: extract JSON, deserialize, and validate.
     *
     * @param array<string, mixed> $schema
     * @throws AgentException
     * @throws DeserializerException
     * @throws ReflectionException
     */
    protected function processResponse(
        Message $response,
        array $schema,
        string $class,
    ): object {
        $this->emit(new Extracting($response));
        $json = (new JsonExtractor())->getJson($response->getContent());
        $this->emit(new Extracted($response, $schema, $json));
        if ($json === null || $json === '') {
            throw new AgentException("The response does not contains a valid JSON Object.");
        }

        $this->emit(new Deserializing($class));
        $obj = Deserializer::make()->fromJson($json, $class);
        $this->emit(new Deserialized($class));

        $this->emit(new Validating($class, $json));
        $violations = Validator::validate($obj);
        if (count($violations) > 0) {
            $this->emit(new Validated($class, $json, $violations));
            throw new AgentException(PHP_EOL.'- '.implode(PHP_EOL.'- ', $violations));
        }
        $this->emit(new Validated($class, $json));

        return $obj;
    }
}
