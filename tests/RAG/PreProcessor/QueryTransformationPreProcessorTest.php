<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\PreProcessor;

use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\PreProcessor\QueryTransformationPreProcessor;
use NeuronAI\RAG\PreProcessor\QueryTransformationType;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_unique;

class QueryTransformationPreProcessorTest extends TestCase
{
    public function test_the_provider_answer_becomes_the_retrieval_query(): void
    {
        $answer = new AssistantMessage('Rewritten query');
        $provider = new FakeAIProvider($answer);

        $query = (new QueryTransformationPreProcessor($provider))->process(new UserMessage('Original query'));

        $this->assertSame($answer, $query);
    }

    public function test_the_original_query_is_delimited_in_a_single_user_message(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Rewritten query'));

        (new QueryTransformationPreProcessor($provider))->process(new AssistantMessage('Original query'));

        $provider->assertCallCount(1);
        $messages = $provider->getRecorded()[0]->messages;
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertSame('<ORIGINAL-QUERY>Original query</ORIGINAL-QUERY>', $messages[0]->getContent());
    }

    /** @return iterable<string, array{QueryTransformationType, string}> */
    public static function transformations(): iterable
    {
        yield 'rewriting' => [QueryTransformationType::REWRITING, 'Output only the reformulated query'];
        yield 'decomposition' => [QueryTransformationType::DECOMPOSITION, 'Output each sub-query on a separate line'];
        yield 'hyde' => [QueryTransformationType::HYDE, 'Output only the hypothetical document passage'];
    }

    #[DataProvider('transformations')]
    public function test_each_transformation_instructs_the_provider_with_its_own_prompt(QueryTransformationType $type, string $instruction): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Transformed'));
        $processor = new QueryTransformationPreProcessor($provider, $type);

        $processor->process(new UserMessage('Original query'));

        $systemPrompt = $provider->getRecorded()[0]->systemPrompt?->getContent();
        $this->assertSame($processor->getSystemPrompt(), $systemPrompt);
        $this->assertStringContainsString($instruction, $systemPrompt);
    }

    public function test_every_transformation_has_a_distinct_prompt(): void
    {
        $provider = new FakeAIProvider();
        $prompts = array_map(
            static fn (QueryTransformationType $type): string => (new QueryTransformationPreProcessor($provider, $type))->getSystemPrompt(),
            QueryTransformationType::cases(),
        );

        $this->assertSame($prompts, array_unique($prompts));
    }

    public function test_rewriting_is_the_default_transformation(): void
    {
        $provider = new FakeAIProvider();

        $this->assertSame(
            (new QueryTransformationPreProcessor($provider, QueryTransformationType::REWRITING))->getSystemPrompt(),
            (new QueryTransformationPreProcessor($provider))->getSystemPrompt(),
        );
    }

    public function test_set_transformation_switches_the_prompt(): void
    {
        $provider = new FakeAIProvider();
        $processor = new QueryTransformationPreProcessor($provider);

        $processor->setTransformation(QueryTransformationType::HYDE);

        $this->assertSame(
            (new QueryTransformationPreProcessor($provider, QueryTransformationType::HYDE))->getSystemPrompt(),
            $processor->getSystemPrompt(),
        );
    }

    public function test_custom_prompt_from_the_constructor_overrides_any_transformation(): void
    {
        $prompt = (string) new SystemPrompt(background: ['background'], steps: ['steps'], output: ['output']);
        $provider = new FakeAIProvider(new AssistantMessage('Transformed'));
        $processor = new QueryTransformationPreProcessor($provider, QueryTransformationType::HYDE, customPrompt: $prompt);

        $processor->process(new UserMessage('Original query'));

        $this->assertSame($prompt, $processor->getSystemPrompt());
        $this->assertSame($prompt, $provider->getRecorded()[0]->systemPrompt?->getContent());
    }

    public function test_custom_prompt_from_the_setter_survives_a_transformation_change(): void
    {
        $processor = (new QueryTransformationPreProcessor(new FakeAIProvider()))
            ->setCustomPrompt('Custom instructions')
            ->setTransformation(QueryTransformationType::DECOMPOSITION);

        $this->assertSame('Custom instructions', $processor->getSystemPrompt());
    }

    public function test_set_provider_routes_the_transformation_to_the_new_provider(): void
    {
        $initial = new FakeAIProvider();
        $replacement = new FakeAIProvider(new AssistantMessage('From replacement'));
        $processor = (new QueryTransformationPreProcessor($initial))->setProvider($replacement);

        $this->assertSame('From replacement', $processor->process(new UserMessage('Original query'))->getContent());
        $initial->assertNothingSent();
        $replacement->assertCallCount(1);
    }

    public function test_the_rewrite_request_offers_no_tools(): void
    {
        // A provider shared with the agent still holds the tools of its last inference.
        $provider = new FakeAIProvider(new AssistantMessage('Rewritten query'));
        $provider->setTools([new SearchTool()]);

        (new QueryTransformationPreProcessor($provider))->process(new UserMessage('Original query'));

        $provider->assertCallCount(1);
        $this->assertSame([], $provider->getRecorded()[0]->tools);
    }
}
