<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Mistral;

use NeuronAI\Chat\Attachments\Attachment;
use NeuronAI\Chat\Enums\AttachmentContentType;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\OpenAI\MessageMapper as OpenAIMessageMapper;

class MessageMapper extends OpenAIMessageMapper
{
    public function mapDocumentAttachment(Attachment $document): array
    {
        return match ($document->contentType) {
            AttachmentContentType::URL => [
                'type' => 'document',
                'document_url' => $document->content,
            ],
            AttachmentContentType::ID => [
                'type' => 'file',
                'file_id' => $document->content,
            ],
            default => throw new ProviderException('Could not map document attachment type '.$document->contentType->value),
        };
    }
}
