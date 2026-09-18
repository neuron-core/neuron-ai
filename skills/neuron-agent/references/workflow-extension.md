# Extending the Agent workflow

Use `entryNodes()` and `exitNodes()` when Agent's inference/tool loop fits and you need extra work before or after it. The default boundaries are `AgentStartNode` and `AgentEndNode`.

The output handoff is `AgentOutputEvent`: the final provider response is in `AgentState`. An output node returns another routing event to continue, or `StopEvent` to terminate. A node must not extend `StopEvent` to create an intermediate routing signal, because the executor treats its subclasses as terminal too.

## Speech output without replacing inference nodes

The following definitions use the existing provider interface. The application supplies a speech provider through a protected hook, just as it supplies its LLM provider. Namespaces are omitted so both PHP blocks below can be combined into one file loaded after Composer's autoloader.

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;

class TextToSpeechNode extends Node
{
    public function __construct(protected AIProviderInterface $provider)
    {
    }

    public function __invoke(AgentOutputEvent $event, AgentState $state): StopEvent
    {
        $state->delete('speech.audio');
        $text = $state->getMessage()?->getContent();

        if ($text !== null) {
            $state->set('speech.audio', $this->memoize(
                'synthesize',
                fn (): AudioContent => $this->provider
                    ->chat(new UserMessage($text))
                    ->message()
                    ->getAudio()
                    ?? throw new ProviderException('Speech provider returned no audio.'),
            ));
        }

        return new StopEvent();
    }
}

abstract class SpeechAgent extends Agent
{
    abstract protected function textToSpeech(): AIProviderInterface;

    /** @return Node[] */
    protected function exitNodes(): array
    {
        return [new TextToSpeechNode($this->textToSpeech())];
    }
}
```

This executable fixture uses fake LLM and speech providers. Its base64 payload is text, not playable audio:

```php
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\WorkflowStatus;

class DemoVoiceAgent extends SpeechAgent
{
    protected function provider(): AIProviderInterface
    {
        return new FakeAIProvider(new AssistantMessage('Hello.'));
    }

    protected function textToSpeech(): AIProviderInterface
    {
        return new FakeAIProvider(new AssistantMessage(new AudioContent(
            base64_encode('Hello.'),
            SourceType::BASE64,
            'application/x-fake-speech',
        )));
    }
}

$agent = DemoVoiceAgent::make();
$state = $agent->chat(new UserMessage('Say hello.'));

if ($state->getStatus() === WorkflowStatus::Completed) {
    $audio = $state->get('speech.audio');
    echo $state->getMessage()->getContent();
    // Deliver $audio through your application's chosen transport.
}
```

Replace the fake provider hooks with application-configured implementations for real use. The repository's `OpenAI\Audio\OpenAITextToSpeech` and `ElevenLabs\ElevenLabsTextToSpeech` classes under `NeuronAI\Providers` implement the same `AIProviderInterface`. Their media formats and transport configuration remain provider concerns.

## Composition and lifecycle rules

- **Replace the default output handler.** Do not also include `parent::exitNodes()` here: `AgentEndNode` and `TextToSpeechNode` would both handle `AgentOutputEvent`.
- **Connect multiple stages through events.** For synthesis followed by storage, let TTS return an application `AudioGeneratedEvent`, register a storage node for it, then return `StopEvent`. Registration order alone never establishes edges. You do not need to modify the chat or structured-output nodes.
- **Preserve the provider response.** Store generated audio separately so `getMessage()` continues to expose the LLM answer and subsequent text inferences do not receive synthesized audio in history.
- **Treat artifacts as run results.** Read audio only after successful completion. The example clears it when the output step runs, including when the response has no text. If a reused Agent must expose empty artifact keys even during a new turn's earlier suspension, clear those keys in the new turn's entry node too; generic state keys are not automatically reset.
- **Keep external calls durable.** Providers remain live dependencies on nodes rebuilt per segment. `memoize()` reuses a committed synthesis result after replay; it cannot prevent a repeat if an external call succeeds but its memo never commits.
- **Resume the same turn after output failure.** Reconstruct the same persistence, history, and dependencies, then call `run()` or `events()`. Completed inference steps are reused. Calling `chat()` starts a new turn instead. History may already contain the final text while synthesis is failed or incomplete.
- **Keep return contracts explicit.** `chat()` returns `AgentState`; `stream()` yields the original live output and returns state when exhausted. This serial example starts synthesis after final inference, without overlapping audio and text streams. `structured()` still returns the typed object; the example would speak its raw JSON, with audio available through `getState()`. Provide an explicit narration mapping if needed.
- **Keep graph export accurate.** This node's `__invoke()` declares its outgoing `StopEvent`. Generator-only nodes can implement `NeuronAI\Workflow\Exporter\DescibeExporterTransitions` to describe their final routing events.

## Adding speech input

An input stage follows the complementary pattern:

1. Define an application event extending `AgentStartEvent` and return it from the Agent's protected `startEvent()` hook. Preserve the parent's messages and options; the Agent convenience methods and ignition logic expect those fields.
2. Return the transcribing node alongside `parent::entryNodes()`. Its `__invoke()` accepts the application event and returns the original `AgentStartEvent` with transcribed `UserMessage` content and preserved options.
3. Memoize the speech call, preserve caller messages rather than mutating them, and leave `AgentStartNode` to initialize `AgentState::$request` and reset tool-run counters. Clear per-turn output artifacts in this entry node if required.

An arbitrary Workflow start event without the `AgentStartEvent` contract is not interchangeable here. Also respect the speech provider's audio input contract: the current OpenAI transcription implementation opens the audio content as a file path; a base64 audio block cannot simply be passed as that path.
