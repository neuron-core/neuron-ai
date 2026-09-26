<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\SystemPrompt;
use PHPUnit\Framework\TestCase;

use function implode;

use const PHP_EOL;

class SystemPromptTest extends TestCase
{
    public function test_background_only_renders_the_identity_section(): void
    {
        $prompt = new SystemPrompt(['You are a support agent.', 'Be concise.']);

        $this->assertSame(
            '# IDENTITY AND PURPOSE' . PHP_EOL . 'You are a support agent.' . PHP_EOL . 'Be concise.',
            (string) $prompt
        );
    }

    public function test_every_section_renders_in_a_fixed_order(): void
    {
        $prompt = new SystemPrompt(
            background: ['You are a support agent.'],
            steps: ['Read the ticket.', 'Look up the order.'],
            output: ['Answer in English.', 'Use bullet points.'],
            toolsUsage: ['Call lookup before answering.'],
        );

        $this->assertSame(implode(PHP_EOL, [
            '# IDENTITY AND PURPOSE',
            'You are a support agent.',
            '',
            '# INTERNAL ASSISTANT STEPS',
            'Read the ticket.',
            'Look up the order.',
            '',
            '# OUTPUT INSTRUCTIONS',
            ' - Answer in English.',
            ' - Use bullet points.',
            '',
            '# TOOLS USAGE RULES',
            ' - Call lookup before answering.',
        ]), (string) $prompt);
    }

    public function test_empty_optional_sections_are_omitted(): void
    {
        $prompt = (string) new SystemPrompt(['Identity'], output: ['Be brief.']);

        $this->assertSame(
            '# IDENTITY AND PURPOSE' . PHP_EOL . 'Identity' . PHP_EOL . PHP_EOL . '# OUTPUT INSTRUCTIONS' . PHP_EOL . ' - Be brief.',
            $prompt
        );
    }

    public function test_sections_changed_after_construction_are_rendered(): void
    {
        $prompt = new SystemPrompt(['Identity']);
        $prompt->toolsUsage[] = 'Never guess an order number.';

        $this->assertSame(
            '# IDENTITY AND PURPOSE' . PHP_EOL . 'Identity' . PHP_EOL . PHP_EOL . '# TOOLS USAGE RULES' . PHP_EOL . ' - Never guess an order number.',
            (string) $prompt
        );
    }

    public function test_multibyte_content_is_preserved_verbatim(): void
    {
        $prompt = (string) new SystemPrompt(['Rispondi in italiano: perché è così 🚀']);

        $this->assertSame('# IDENTITY AND PURPOSE' . PHP_EOL . 'Rispondi in italiano: perché è così 🚀', $prompt);
    }
}
