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
