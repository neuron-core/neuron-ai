<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\SkillActivated;
use NeuronAI\Observability\Events\SkillsBootstrapped;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolPropertyInterface;

use function array_map;
use function array_reduce;
use function json_encode;
use function str_repeat;

class ConsoleDebugObserver implements ObserverInterface
{
    private int $inferenceRound = 0;

    public function onEvent(string $event, object $source, mixed $data = null): void
    {
        match ($event) {
            'skills-bootstrapped' => $this->onSkillsBootstrapped($data),
            'skill-activated' => $this->onSkillActivated($data),
            'inference-start' => $this->onInferenceStart($data),
            'inference-stop' => $this->onInferenceStop($data),
            'tool-calling' => $this->onToolCalling($data),
            'tool-called' => $this->onToolCalled($data),
            'message-saved' => $this->onMessageSaved($data),
            default => null,
        };
    }

    private function onSkillsBootstrapped(mixed $data): void
    {
        if (!($data instanceof SkillsBootstrapped)) {
            return;
        }

        $this->header('SKILLS BOOTSTRAPPED');
        foreach ($data->skills as $skill) {
            $toolCount = count($skill->tools());
            $this->line("  • {$skill->name()}" . ($toolCount > 0 ? " ({$toolCount} tool(s))" : ''));
        }
    }

    private function onSkillActivated(mixed $data): void
    {
        if (!($data instanceof SkillActivated)) {
            return;
        }

        $this->header('SKILL ACTIVATED');
        $this->line("  {$data->skillName}");
        if ($data->reason !== null) {
            $this->line("  Reason: {$data->reason}");
        }
    }

    private function onInferenceStart(mixed $data): void
    {
        if (!($data instanceof InferenceStart)) {
            return;
        }

        $this->inferenceRound++;
        $this->header("LLM CALL #{$this->inferenceRound}");

        // Build the complete request payload as it would be sent to the API
        $request = [];

        if ($data->instructions !== '') {
            $request['system'] = $data->instructions;
        }

        // Messages
        $request['messages'] = array_map(
            fn (\NeuronAI\Chat\Messages\Message $msg): array => $msg->jsonSerialize(),
            $data->messages,
        );

        // Tools
        if ($data->tools !== []) {
            $request['tools'] = array_map(fn (ToolInterface $tool): array => $this->toolToJsonSchema($tool), $data->tools);
        }

        $this->section('Request Payload');
        $this->text(json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('');
    }

    private function onInferenceStop(mixed $data): void
    {
        if (!($data instanceof InferenceStop)) {
            return;
        }

        $response = $data->response;

        $this->section('Response Payload');
        $this->text(json_encode($response->jsonSerialize(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('');
    }

    private function onToolCalling(mixed $data): void
    {
        if (!($data instanceof ToolCalling)) {
            return;
        }

        $tool = $data->tool;
        $this->section('Tool Executing');
        $this->line("  {$tool->getName()}(" . json_encode($tool->getInputs(), JSON_UNESCAPED_UNICODE) . ')');
    }

    private function onToolCalled(mixed $data): void
    {
        if (!($data instanceof ToolCalled)) {
            return;
        }

        $tool = $data->tool;
        $this->section('Tool Result');
        $result = $tool->getResult();
        $this->text($result);
        $this->line('');
    }

    private function onMessageSaved(mixed $data): void
    {
        // We only log non-standard messages here for brevity.
        // Inference start/stop already covers the main flow.
    }

    /**
     * Build the JSON schema for a tool (matches what ToolMapper sends to the API).
     */
    private function toolToJsonSchema(ToolInterface $tool): array
    {
        $properties = array_reduce(
            $tool->getProperties(),
            fn (array $carry, ToolPropertyInterface $p): array => $carry + [$p->getName() => $p->getJsonSchema()],
            [],
        );

        $payload = [
            'type' => 'function',
            'function' => [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
        ];

        if ($properties !== []) {
            $payload['function']['parameters'] = [
                'type' => 'object',
                'properties' => $properties,
                'required' => $tool->getRequiredProperties(),
            ];
        }

        return $payload;
    }

    // --- Formatting helpers ---

    private function header(string $text): void
    {
        $line = str_repeat('─', 80);
        $this->line('');
        $this->line($line);
        $this->line("  {$text}");
        $this->line($line);
    }

    private function section(string $text): void
    {
        $this->line("  ▸ {$text}:");
    }

    private function text(string $text): void
    {
        foreach (explode("\n", $text) as $line) {
            $this->line("    {$line}");
        }
    }

    private function line(string $text): void
    {
        fwrite(STDERR, $text . PHP_EOL);
    }
}
