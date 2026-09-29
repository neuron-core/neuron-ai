<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

class CitationsTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_anthropic_web_search_citation_keeps_cited_text_and_source_url(): void
    {
        $citations = $this->anthropicCitations([
            'type' => 'web_search_result_location',
            'url' => 'https://example.com/sky',
            'title' => 'Sky facts',
            'encrypted_index' => 'Eo8BCioIAhgBIiQy',
            'cited_text' => 'The sky appears blue because of Rayleigh scattering.',
        ]);

        $this->assertSame('https://example.com/sky', $citations[0]->source);
        $this->assertSame('Sky facts', $citations[0]->title);
        $this->assertSame('The sky appears blue because of Rayleigh scattering.', $citations[0]->citedText);
    }

    public function test_anthropic_document_char_location_citation_keeps_cited_text(): void
    {
        $citations = $this->anthropicCitations([
            'type' => 'char_location',
            'cited_text' => 'The grass is green.',
            'document_index' => 0,
            'document_title' => 'Example Document',
            'start_char_index' => 0,
            'end_char_index' => 20,
        ]);

        $this->assertSame('The grass is green.', $citations[0]->citedText);
        $this->assertSame('Example Document', $citations[0]->title);
        $this->assertSame('', $citations[0]->source);
        // The indexes are the span of the answer the citation supports; the document location stays in metadata
        $this->assertSame(0, $citations[0]->startIndex);
        $this->assertSame(16, $citations[0]->endIndex);
        $this->assertSame([
            'type' => 'char_location',
            'document_index' => 0,
            'start_char_index' => 0,
            'end_char_index' => 20,
            'block_index' => 0,
            'provider' => 'anthropic',
        ], $citations[0]->metadata);
    }

    public function test_anthropic_citations_of_later_blocks_span_their_own_block(): void
    {
        $body = json_encode(['content' => [
            ['type' => 'text', 'text' => 'According to the page, '],
            ['type' => 'text', 'text' => 'the sky is blue.', 'citations' => [[
                'type' => 'search_result_location',
                'source' => 'https://example.com/sky',
                'title' => 'Sky facts',
                'cited_text' => 'Blue light is scattered more.',
                'search_result_index' => 0,
                'start_block_index' => 0,
                'end_block_index' => 1,
            ]]],
        ]], JSON_THROW_ON_ERROR);
        $provider = new Anthropic('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body)));

        $citation = $provider->chat(new UserMessage('Why?'))->message()->getMetadata('citations')[0];

        $this->assertSame('https://example.com/sky', $citation->source);
        $this->assertSame(23, $citation->startIndex);
        $this->assertSame(39, $citation->endIndex);
    }

    public function test_openai_chat_completions_url_citations_are_extracted(): void
    {
        $body = json_encode(['choices' => [[
            'index' => 0,
            'finish_reason' => 'stop',
            'message' => [
                'role' => 'assistant',
                'content' => 'The sky is blue.',
                'annotations' => [[
                    'type' => 'url_citation',
                    'url_citation' => ['start_index' => 0, 'end_index' => 16, 'url' => 'https://example.com/sky', 'title' => 'Sky facts'],
                ]],
            ],
        ]]], JSON_THROW_ON_ERROR);
        $provider = new OpenAI('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body)));

        $citations = $provider->chat(new UserMessage('Why is the sky blue?'))->message()->getMetadata('citations');

        $this->assertIsArray($citations);
        $this->assertSame('https://example.com/sky', $citations[0]->source);
        $this->assertSame('Sky facts', $citations[0]->title);
        $this->assertSame(0, $citations[0]->startIndex);
        $this->assertSame(16, $citations[0]->endIndex);
        // OpenAI quotes nothing from the source
        $this->assertNull($citations[0]->citedText);
        $this->assertSame(['type' => 'url_citation', 'provider' => 'openai'], $citations[0]->metadata);
    }

    public function test_openai_chat_completions_without_annotations_attach_no_citations(): void
    {
        $body = json_encode(['choices' => [[
            'index' => 0,
            'finish_reason' => 'stop',
            'message' => ['role' => 'assistant', 'content' => 'The sky is blue.'],
        ]]], JSON_THROW_ON_ERROR);
        $provider = new OpenAI('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body)));

        $this->assertNull($provider->chat(new UserMessage('Why?'))->message()->getMetadata('citations'));
    }

    public function test_openai_responses_url_citations_are_extracted(): void
    {
        $citations = $this->responsesCitations([
            'type' => 'url_citation',
            'start_index' => 0,
            'end_index' => 16,
            'url' => 'https://example.com/sky',
            'title' => 'Sky facts',
        ]);

        $this->assertIsArray($citations);
        $this->assertSame('https://example.com/sky', $citations[0]->source);
        $this->assertSame('Sky facts', $citations[0]->title);
        $this->assertSame(0, $citations[0]->startIndex);
        $this->assertSame(16, $citations[0]->endIndex);
        $this->assertSame(['type' => 'url_citation', 'provider' => 'openai_responses'], $citations[0]->metadata);
    }

    public function test_openai_responses_flat_file_citation_keeps_the_file_id(): void
    {
        $citations = $this->responsesCitations([
            'type' => 'file_citation',
            'file_id' => 'file-abc123',
            'filename' => 'sky.pdf',
            'index' => 16,
        ]);

        $this->assertIsArray($citations);
        $this->assertSame('file-abc123', $citations[0]->source);
        $this->assertSame('sky.pdf', $citations[0]->title);
        $this->assertSame(16, $citations[0]->startIndex);
        $this->assertSame(16, $citations[0]->endIndex);
    }

    public function test_openai_responses_container_file_citation_keeps_the_file_id_and_span(): void
    {
        $citations = $this->responsesCitations([
            'type' => 'container_file_citation',
            'container_id' => 'cntr_1',
            'file_id' => 'cfile_1',
            'filename' => 'chart.png',
            'start_index' => 4,
            'end_index' => 8,
        ]);

        $this->assertIsArray($citations);
        $this->assertSame('cfile_1', $citations[0]->source);
        $this->assertSame('chart.png', $citations[0]->title);
        $this->assertSame(4, $citations[0]->startIndex);
        $this->assertSame(8, $citations[0]->endIndex);
        $this->assertSame(['type' => 'container_file_citation', 'provider' => 'openai_responses', 'container_id' => 'cntr_1'], $citations[0]->metadata);
    }

    /**
     * @param array<string, mixed> $citation
     * @return Citation[]
     */
    protected function anthropicCitations(array $citation): array
    {
        $body = json_encode(['content' => [[
            'type' => 'text',
            'text' => 'The sky is blue.',
            'citations' => [$citation],
        ]]], JSON_THROW_ON_ERROR);
        $provider = new Anthropic('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body)));

        return $provider->chat(new UserMessage('Why?'))->message()->getMetadata('citations');
    }

    /**
     * @param array<string, mixed> $annotation
     * @return Citation[]|null
     */
    protected function responsesCitations(array $annotation): ?array
    {
        $body = json_encode(['status' => 'completed', 'output' => [[
            'type' => 'message',
            'content' => [[
                'type' => 'output_text',
                'text' => 'The sky is blue.',
                'annotations' => [$annotation],
            ]],
        ]]], JSON_THROW_ON_ERROR);
        $provider = new OpenAIResponses('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body)));

        return $provider->chat(new UserMessage('Why?'))->message()->getMetadata('citations');
    }
}
