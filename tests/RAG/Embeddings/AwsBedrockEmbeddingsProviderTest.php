<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Embeddings;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\AwsBedrockEmbeddingsProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class AwsBedrockEmbeddingsProviderTest extends TestCase
{
    /** @var CommandInterface[] */
    protected array $commands = [];

    /** @param float[] ...$embeddings */
    protected function client(array ...$embeddings): BedrockRuntimeClient
    {
        $handler = new MockHandler();
        foreach ($embeddings as $embedding) {
            $handler->append(function (CommandInterface $command) use ($embedding): Result {
                $this->commands[] = $command;

                return new Result([
                    'body' => Utils::streamFor(json_encode(['embedding' => $embedding, 'inputTextTokenCount' => 2], JSON_THROW_ON_ERROR)),
                    'contentType' => 'application/json',
                ]);
            });
        }

        return new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'aws-key', 'secret' => 'aws-secret'],
            'handler' => $handler,
        ]);
    }

    public function test_embed_text_invokes_the_titan_model_with_the_input_text(): void
    {
        $provider = new AwsBedrockEmbeddingsProvider($this->client([0.1, -0.2]));

        $this->assertSame([0.1, -0.2], $provider->embedText('Hello'));

        $this->assertCount(1, $this->commands);
        $this->assertSame('InvokeModel', $this->commands[0]->getName());
        $this->assertSame('amazon.titan-embed-text-v2:0', $this->commands[0]['modelId']);
        $this->assertSame('application/json', $this->commands[0]['contentType']);
        $this->assertSame(['inputText' => 'Hello'], json_decode($this->commands[0]['body'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_embed_documents_invokes_the_configured_model_once_per_document_in_order(): void
    {
        $documents = [new Document('First'), new Document('Second')];
        $provider = new AwsBedrockEmbeddingsProvider($this->client([1.0], [2.0]), 'cohere.embed-english-v3');

        $result = $provider->embedDocuments($documents);

        $this->assertSame($documents, $result);
        $this->assertSame([[1.0], [2.0]], array_map(static fn (Document $document): ?array => $document->getEmbedding(), $result));
        $this->assertSame(['cohere.embed-english-v3', 'cohere.embed-english-v3'], [$this->commands[0]['modelId'], $this->commands[1]['modelId']]);
        $this->assertSame(['inputText' => 'Second'], json_decode($this->commands[1]['body'], true, flags: JSON_THROW_ON_ERROR));
    }
}
