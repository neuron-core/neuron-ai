<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\AWS;

use NeuronAI\Chat\Enums\MediaType;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AWS\MessageMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_encode;

class BedrockDocumentFormatTest extends TestCase
{
    /**
     * Converse DocumentFormat enum: pdf | csv | doc | docx | xls | xlsx | html | txt | md.
     *
     * @return array<string, array{string|MediaType, string}>
     */
    public static function document_media_types(): array
    {
        return [
            'pdf' => [MediaType::PDF, 'pdf'],
            'csv' => [MediaType::CSV, 'csv'],
            'html' => [MediaType::HTML, 'html'],
            'plain text' => [MediaType::TXT, 'txt'],
            'markdown' => [MediaType::MARKDOWN, 'md'],
            'word' => ['application/msword', 'doc'],
            'word openxml' => [MediaType::DOCX, 'docx'],
            'excel' => ['application/vnd.ms-excel', 'xls'],
            'excel openxml' => [MediaType::XLSX, 'xlsx'],
        ];
    }

    #[DataProvider('document_media_types')]
    public function test_document_media_types_map_to_converse_formats(string|MediaType $mediaType, string $format): void
    {
        $message = new UserMessage(new FileContent(base64_encode('data'), SourceType::BASE64, $mediaType, 'doc'));

        $this->assertSame($format, (new MessageMapper())->map([$message])[0]['content'][0]['document']['format']);
    }

    public function test_base64_payload_decoding_to_a_falsy_string_is_still_decoded(): void
    {
        $message = new UserMessage(new ImageContent(base64_encode('0'), SourceType::BASE64, MediaType::PNG));

        $this->assertSame(['bytes' => '0'], (new MessageMapper())->map([$message])[0]['content'][0]['image']['source']);
    }
}
