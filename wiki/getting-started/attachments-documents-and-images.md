---
description: Attach documents and images to your message.
---

# Attachments (Documents & Images)

Most advanced LLMs can understand the content of documents and images other than simple text. With Neuron you can attach files to your messages to enrich the context provided to the Agent.

The most common use cases for documents analysis are:

* Caption and answer questions about images
* Transcribe and reason over document contents

You have two options to attach items to your messages: as an URL, or encoded in base64.

{% hint style="warning" %}
Be sure about the possible limitations of your AI provider to handle documents and images in specific formats.&#x20;
{% endhint %}

## Documents

#### URL

```php
use App\Neuron\MyAgent;
use NeuronAI\Chat\Attachments\Document;
use NeuronAI\Chat\Messages\UserMessage;

// Ollama only support images encoded in base64
$message = (new UserMessage("Describe this document"))
    ->addAttachment(
        new Document('https://url_of/document.pdf')
    );
    
$response = MyAgent::make()->chat($message);
// The document is a contract...
```

#### Base64

```php
use App\Neuron\MyAgent;
use NeuronAI\Chat\Attachments\AttachmentContentType;
use NeuronAI\Chat\Attachments\Document;
use NeuronAI\Chat\Messages\UserMessage;

$content = base64_encode(file_get_contents('/document.pdf'));

$message = (new UserMessage("Describe this document"))
    ->addAttachment(
        new Document(
            content: $content,
            contentType: AttachmentContentType::BASE64,
            mediaType: 'application/pdf'
        )
    );
    
$response = MyAgent::make()->chat($message);
// The document is a contract...
```

## Images

#### URL

```php
use App\Neuron\MyAgent;
use NeuronAI\Chat\Attachments\Image;
use NeuronAI\Chat\Messages\UserMessage;

// Ollama only support images encoded in base64
$message = (new UserMessage("Describe this image"))
    ->addAttachment(
        new Image('https://url_of/image.jpg')
    );
    
$response = MyAgent::make()->chat($message);
// The image shows...
```

#### Base64

```php
use App\Neuron\MyAgent;
use NeuronAI\Chat\Attachments\AttachmentContentType;
use NeuronAI\Chat\Attachments\Image;
use NeuronAI\Chat\Messages\UserMessage;

$content = base64_encode(file_get_contents('/image.jpg'));

$message = (new UserMessage("Describe this image"))
    ->addAttachment(
        new Image(
            content: $content,
            contentType: AttachmentContentType::BASE64,
            mediaType: 'image/jpeg'
        )
    );
    
$response = MyAgent::make()->chat($message);
// The image shows...
```

## Ollama limitations

Ollama only support images in base64 format, so you have to take care to convert the file content and set up the right type for attachments:

```php
use NeuronAI\Chat\Attachments\AttachmentContentType;
use NeuronAI\Chat\Attachments\Image;
use NeuronAI\Chat\Messages\UserMessage;

// Ollama only support images encoded in base64
$message = (new UserMessage("Describe this image"))
    ->addAttachment(
        new Image(
            image: 'base64-encoded-content', 
            type: AttachmentContentType::BASE64, 
            mediaType: 'image/jpeg'
        )
    );
```
