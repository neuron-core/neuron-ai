<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Mistral;

use NeuronAI\Chat\Attachments\Attachment;
use NeuronAI\Chat\Enums\AttachmentContentType;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\OpenAI\MessageMapper as OpenAIMessageMapper;

use function uniqid;

class MessageMapper extends OpenAIMessageMapper
{
    public function mapDocumentAttachment(Attachment $document): array
    {
        return match ($document->contentType) {
            /*AttachmentContentType::BASE64 => [
                'type' => 'file',
                'file' => [
                    'filename' => $document->filename ?? "attachment-".uniqid().".pdf",
                    'file_data' => "data:{$document->mediaType};base64,{$document->content}",
                ]
            ],*/
            AttachmentContentType::URL => [
                'type' => 'document',
                'document_url' => $document->content,
                'document_name' => $document->filename ?? "attachment-".uniqid().".pdf",
            ],
            AttachmentContentType::ID => [
                'type' => 'file',
                'file_id' => $document->content,
            ],
            default => throw new ProviderException('Could not map document attachment type '.$document->contentType->value),
        };
    }
}
