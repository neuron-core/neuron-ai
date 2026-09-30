# Endpoints: the routes, the AG-UI request and the Vercel variant

## The routes

Every endpoint of the skill in one group; register the ones you build. The `chat/` prefix is the path `bootstrap/app.php` exempts from `TrimStrings` and `ConvertEmptyStringsToNull`.

```php
// routes/web.php
use App\Http\Controllers\BackgroundChatController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ThreadController;

Route::middleware('auth')->prefix('chat')->group(function () {
    Route::post('threads', [ThreadController::class, 'store']);
    Route::get('threads/{thread}', [ThreadController::class, 'show']);
    Route::post('threads/{thread}/messages', [ChatController::class, 'send']);
    Route::post('threads/{thread}/runs', [BackgroundChatController::class, 'dispatch']);
    Route::post('threads/{thread}/stop', [ThreadController::class, 'stop']);
    Route::post('agui', [ChatController::class, 'stream']);
    Route::post('agui/background', [BackgroundChatController::class, 'stream']);
});
```

## RunAgentRequest

The AG-UI `RunAgentInput` (CopilotKit, the AG-UI `HttpAgent`) as a FormRequest. It extends `ThreadRequest`, so `authorize()` checks the thread's owner before validation, with the thread read from the body.

```php
namespace App\Http\Requests;

use Illuminate\Support\Arr;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Tools\FrontendTool;

/**
 * The AG-UI RunAgentInput posted by CopilotKit or the AG-UI HttpAgent.
 */
class RunAgentRequest extends ThreadRequest
{
    public function rules(): array
    {
        return [
            'threadId' => ['required', 'string', 'max:255'],
            'runId' => ['required', 'string', 'max:255'],
            'messages' => ['present', 'array'],
            'messages.*.id' => ['required', 'string'],
            'state' => ['nullable', 'array'],
            'resume' => ['nullable', 'array'],
            'tools' => ['nullable', 'array'],
        ];
    }

    public function threadId(): string
    {
        return (string) $this->input('threadId');
    }

    /**
     * @return FrontendTool[]
     *
     * @throws InputTranslationException
     */
    public function frontendTools(): array
    {
        return (new AGUIInputTranslator)->tools($this->all());
    }

    /**
     * Answers to the pending interrupts, or tool results after the last user message, continue the suspended run.
     */
    public function isContinuation(): bool
    {
        if ($this->array('resume') !== []) {
            return true;
        }

        $messages = $this->array('messages');
        $lastUser = Arr::last(array_keys($messages), fn (int $index): bool => ($messages[$index]['role'] ?? null) === 'user') ?? -1;

        return collect($messages)->slice($lastUser + 1)->contains(fn (array $message): bool => ($message['role'] ?? null) === 'tool');
    }

    /**
     * The text of the last user message: the earlier ones are already in the agent's history.
     *
     * @throws InputTranslationException
     */
    public function prompt(): string
    {
        $message = Arr::last($this->array('messages'), fn (array $message): bool => ($message['role'] ?? null) === 'user')
            ?? throw new InputTranslationException('AG-UI input must end with a user message or carry a continuation.');
        $content = $message['content'] ?? '';

        return is_string($content) ? $content : collect($content)->where('type', 'text')->pluck('text')->implode("\n");
    }
}
```

- `isContinuation()` looks for a tool message anywhere after the last user message, not only at the end: CopilotKit can insert a tool result before trailing assistant text.
- The client's messages reach the adapter as its seed (`new AGUIAdapter($threadId, $request->input('runId'), $request->input('messages'), $request->array('state'))`) so the snapshots the adapter sends back are complete. That is why `TrimStrings` and `ConvertEmptyStringsToNull` must skip these routes: with them, an empty tool result comes back as `null` and indented text comes back trimmed.
- Continuation rules, resume shapes and browser tools: **neuron-frontend-integration**.

## The Vercel AI SDK variant

Same shape as the AG-UI controller, with the Vercel pieces swapped in: the thread is the chat `id`, the adapter is seeded with the last assistant message on a continuation, and the translator is `VercelAIInputTranslator`. This ran as a route in a feature test: a turn suspended with a `tool-approval-request` part, and an `approval-responded` part continued it to `tool-output-available` and the answer.

```php
Route::post('/chat/vercel', function (Request $request, SupportAgent $agent) {
    $last = $request->input('messages.'.(count($request->input('messages')) - 1));
    $continuation = ($last['role'] ?? null) === 'assistant';
    $adapter = $continuation ? new VercelAIAdapter($last['id'], $last['parts'] ?? []) : new VercelAIAdapter;

    $agent = $agent->for($request->input('id'))->setStreamAdapter(fn (): VercelAIAdapter => $adapter);
    $events = $continuation
        ? $agent->submitInputs($request->all(), new VercelAIInputTranslator)->events()
        : $agent->stream(new UserMessage(collect($last['parts'])->where('type', 'text')->pluck('text')->implode('')));
    $events->valid();

    return response()->stream(function () use ($events, $adapter): Generator {
        try {
            yield from SSEEncoder::encode($events);
        } catch (Throwable $e) {
            report($e);
            foreach ($adapter->error($e) as $event) {
                yield SSEEncoder::frame($event);
            }
        }
    }, 200, $adapter->getHeaders());
})->middleware('web');
```

- In the application, move this into a controller with a FormRequest that authorizes `id` the way `ThreadRequest` authorizes `threadId`.
- `getHeaders()` adds `x-vercel-ai-ui-message-stream: v1`.
- `VercelAIAdapter` sends no start frame, so `$events->valid()` waits for the provider's first chunk; refusals still throw before any header.
- `VercelAIAdapter` has no `hydrate()`: `useChat` keeps its messages client-side across reloads (**neuron-frontend-integration**, "Vercel AI SDK").
