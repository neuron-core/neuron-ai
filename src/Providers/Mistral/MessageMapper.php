<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Mistral;

use NeuronAI\Chat\Attachments\Document;
use NeuronAI\Chat\Enums\AttachmentContentType;
use NeuronAI\Providers\OpenAI\MessageMapper as OpenAIMessageMapper;

use function uniqid;

class MessageMapper extends OpenAIMessageMapper
{
    public function mapDocumentAttachment(Document $document): array
    {
        return match ($document->contentType) {
            AttachmentContentType::BASE64 => [
                'type' => 'file',
                'file' => [
                    'filename' => $document->filename ?? "attachment-".uniqid().".pdf",
                    'file_data' => "data:{$document->mediaType};base64,{$document->content}",
                ]
            ],
            AttachmentContentType::URL => [
                'type' => 'document',
                'document_url' => $document->content,
            ],
            AttachmentContentType::ID => [
                'type' => 'file',
                'file_id' => $document->content,
            ],
        };
    }
}
