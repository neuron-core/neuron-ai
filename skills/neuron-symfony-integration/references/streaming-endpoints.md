# Streaming endpoint for the Vercel AI SDK (`useChat`)

The AG-UI controller in SKILL.md, adapted to `useChat`. The thread is the chat `id` and the route carries it, so `#[IsGranted('THREAD')]` authorizes it before the agent is built. A trailing assistant message is a continuation: the adapter resumes that message and `VercelAIInputTranslator` reads the tool outputs and approval responses from its parts. Client wiring and the continuation rules: **neuron-frontend-integration** ("Vercel AI SDK").

```php
namespace App\Controller;

use App\Neuron\Agents\SupportAgent;
use App\Neuron\StopSignal;
use Generator;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Frontend\VercelAIInputTranslator;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

/** useChat: the thread is the chat id; a trailing assistant message continues the run. */
class VercelChatController extends AbstractController
{
    public function __construct(
        protected SupportAgent $agent,
        protected StopSignal $stopSignal,
        protected LoggerInterface $logger,
    ) {
    }

    #[Route('/chat/{threadId}/vercel', methods: ['POST'])]
    #[IsCsrfTokenValid('chat', tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    #[IsGranted('THREAD', subject: 'threadId')]
    public function __invoke(string $threadId, Request $request): StreamedResponse
    {
        $input = $request->toArray();
        $messages = $input['messages'] ?? [];
        $last = end($messages) ?: [];

        $this->stopSignal->clear($threadId);
        if (($last['role'] ?? null) === 'assistant') {
            $adapter = new VercelAIAdapter(messageId: $last['id'], parts: $last['parts'] ?? []);
            $events = $this->agent->for($threadId)
                ->setStreamAdapter(fn (): VercelAIAdapter => $adapter)
                ->submitInputs($input, new VercelAIInputTranslator())
                ->events();
        } elseif (($last['role'] ?? null) === 'user') {
            $adapter = new VercelAIAdapter();
            $text = implode('', array_map(fn (array $part): string => $part['type'] === 'text' ? (string) $part['text'] : '', $last['parts'] ?? []));
            $this->agent->for($threadId)->recoverFailedTurn();
            $events = $this->agent->for($threadId)
                ->setStreamAdapter(fn (): VercelAIAdapter => $adapter)
                ->stream(new UserMessage($text));
        } else {
            throw new InputTranslationException('useChat input must end with a user or an assistant message.');
        }

        $events->valid();

        return new StreamedResponse($this->frames($events, $adapter), 200, $adapter->getHeaders());
    }

    protected function frames(Generator $events, VercelAIAdapter $adapter): Generator
    {
        try {
            yield from SSEEncoder::encode($events);
        } catch (Throwable $e) {
            $this->logger->error('Agent stream failed: '.$e->getMessage(), ['exception' => $e]);
            foreach ($adapter->error($e) as $event) {
                yield SSEEncoder::frame($event);
            }
        }
    }
}
```

What differs from the AG-UI endpoint, as run:

- Priming gave the same 409 JSON response before any frame when a second turn arrived during a pending approval, though `valid()` returns only at the first provider chunk: `VercelAIAdapter::start()` emits nothing.
- The headers add `x-vercel-ai-ui-message-stream: v1` to the SSE ones.
- There is no `hydrate()`: `useChat` keeps its messages client-side. A reload endpoint returns `loadAll()` and `pendingApprovals()` as JSON (SKILL.md, "Reload").
- A new turn finishes a failed one first, on its own copy, as in the AG-UI endpoint: after a failure past a tool, the next message answered and history held the recovered turn before it.
- A `WebTestCase` with `FakeAIProvider` ran a turn, an approval round trip through a trailing assistant message, the 409 and the recovery (the base class is in [testing.md](testing.md)).
