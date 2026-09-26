<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Gemini;

use NeuronAI\Providers\Gemini\StreamState;
use PHPUnit\Framework\TestCase;

class GeminiStreamStateTest extends TestCase
{
    public function test_accumulates_function_calls_arriving_in_a_single_chunk(): void
    {
        $state = new StreamState();

        $state->composeToolCalls([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Rome']], 'thoughtSignature' => 'sig'],
                            ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Milan']]],
                        ],
                    ],
                ],
            ],
        ]);

        $calls = $state->getToolCalls();
        $this->assertCount(2, $calls);
        $this->assertSame(['city' => 'Rome'], $calls[0]['functionCall']['args']);
        $this->assertSame(['city' => 'Milan'], $calls[1]['functionCall']['args']);
        $this->assertSame('sig', $calls[0]['thoughtSignature']);
    }

    public function test_only_the_first_function_call_keeps_its_thought_signature(): void
    {
        $state = new StreamState();

        $state->composeToolCalls(['candidates' => [['content' => ['parts' => [
            ['functionCall' => ['name' => 'a', 'args' => []], 'thoughtSignature' => 'sig-a'],
        ]]]]]);
        $state->composeToolCalls(['candidates' => [['content' => ['parts' => [
            ['functionCall' => ['name' => 'b', 'args' => []], 'thoughtSignature' => 'sig-b'],
        ]]]]]);

        $this->assertSame([
            ['functionCall' => ['name' => 'a', 'args' => []], 'thoughtSignature' => 'sig-a'],
            ['functionCall' => ['name' => 'b', 'args' => []]],
        ], $state->getToolCalls());
    }

    public function test_accumulates_function_calls_split_across_chunks(): void
    {
        $state = new StreamState();

        // Each chunk carries its function call at part index 0 — accumulation
        // must append, not overwrite by part index.
        $state->composeToolCalls([
            'candidates' => [
                ['content' => ['parts' => [
                    ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Rome']]],
                ]]],
            ],
        ]);
        $state->composeToolCalls([
            'candidates' => [
                ['content' => ['parts' => [
                    ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Milan']]],
                ]]],
            ],
        ]);

        $calls = $state->getToolCalls();
        $this->assertCount(2, $calls);
        $this->assertSame(['city' => 'Rome'], $calls[0]['functionCall']['args']);
        $this->assertSame(['city' => 'Milan'], $calls[1]['functionCall']['args']);
    }
}
