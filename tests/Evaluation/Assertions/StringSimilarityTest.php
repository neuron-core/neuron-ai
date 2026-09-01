<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringSimilarity;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use stdClass;
use InvalidArgumentException;

use function array_fill;

class StringSimilarityTest extends TestCase
{
    /** @var MockObject&EmbeddingsProviderInterface */
    private MockObject $embeddingsProvider;

    protected function setUp(): void
    {
        $this->embeddingsProvider = $this->createMock(EmbeddingsProviderInterface::class);
    }

    public function test_passes_when_similarity_is_above_threshold(): void
    {
        $this->embeddingsProvider
            ->expects($this->exactly(2))
            ->method('embedText')
            ->willReturnMap([
                ['hello world', [1.0, 0.0, 0.0]],
                ['hello earth', [0.9, 0.1, 0.0]],
            ]);

        $assertion = new StringSimilarity('hello world', $this->embeddingsProvider, 0.8);
        $result = $assertion->evaluate('hello earth');

        $this->assertTrue($result->passed);
        $this->assertGreaterThan(0.8, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function test_passes_when_identical_strings(): void
    {
        $this->embeddingsProvider
            ->expects($this->exactly(2))
            ->method('embedText')
            ->willReturnMap([
                ['hello world', [1.0, 0.0, 0.0]],
                ['hello world', [1.0, 0.0, 0.0]],
            ]);

        $assertion = new StringSimilarity('hello world', $this->embeddingsProvider, 0.6);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_when_similarity_is_below_threshold(): void
    {
        $this->embeddingsProvider
            ->expects($this->exactly(2))
            ->method('embedText')
            ->willReturnMap([
                ['hello world', [1.0, 0.0, 0.0]],
                ['goodbye universe', [0.0, 1.0, 0.0]],
            ]);

        $assertion = new StringSimilarity('hello world', $this->embeddingsProvider, 0.8);
        $result = $assertion->evaluate('goodbye universe');

        $this->assertFalse($result->passed);
        $this->assertLessThan(0.8, $result->score);
        $this->assertEquals("Expected 'goodbye universe' to be similar to 'hello world' (threshold: '0.8')", $result->message);
    }

    public function test_fails_with_non_string_input(): void
    {
        $assertion = new StringSimilarity('test', $this->embeddingsProvider, 0.6);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(123);
    }

    public function test_fails_with_array_input(): void
    {
        $assertion = new StringSimilarity('hello', $this->embeddingsProvider, 0.6);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(['hello', 'world']);
    }

    public function test_fails_with_null_input(): void
    {
        $assertion = new StringSimilarity('test', $this->embeddingsProvider, 0.6);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(null);
    }

    public function test_fails_with_object_input(): void
    {
        $assertion = new StringSimilarity('test', $this->embeddingsProvider, 0.6);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(new stdClass());
    }

    public function test_handles_embeddings_provider_exception(): void
    {
        $this->embeddingsProvider
            ->expects($this->once())
            ->method('embedText')
            ->willThrowException(new VectorStoreException('Embeddings service unavailable'));

        $assertion = new StringSimilarity('hello world', $this->embeddingsProvider, 0.6);

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Embeddings service unavailable');

        $assertion->evaluate('hello earth');
    }

    public function test_uses_default_threshold(): void
    {
        $this->embeddingsProvider
            ->expects($this->exactly(2))
            ->method('embedText')
            ->willReturnMap([
                ['hello world', [1.0, 0.0, 0.0]],
                ['hello earth', [0.7, 0.3, 0.0]],
            ]);

        $assertion = new StringSimilarity('hello world', $this->embeddingsProvider); // Default threshold 0.6
        $result = $assertion->evaluate('hello earth');

        $this->assertTrue($result->passed);
        $this->assertGreaterThan(0.6, $result->score);
    }

    public function test_fails_with_default_threshold_when_similarity_too_low(): void
    {
        $this->embeddingsProvider
            ->expects($this->exactly(2))
            ->method('embedText')
            ->willReturnMap([
                ['hello world', [1.0, 0.0, 0.0]],
                ['completely different', [0.0, 0.0, 1.0]],
            ]);

        $assertion = new StringSimilarity('hello world', $this->embeddingsProvider); // Default threshold 0.6
        $result = $assertion->evaluate('completely different');

        $this->assertFalse($result->passed);
        $this->assertLessThan(0.6, $result->score);
        $this->assertEquals("Expected 'completely different' to be similar to 'hello world' (threshold: '0.6')", $result->message);
    }

    public function test_handles_unicode_strings(): void
    {
        $this->embeddingsProvider
            ->expects($this->exactly(2))
            ->method('embedText')
            ->willReturnMap([
                ['café naïve', [1.0, 0.0, 0.0]],
                ['café native', [0.9, 0.1, 0.0]],
            ]);

        $assertion = new StringSimilarity('café naïve', $this->embeddingsProvider, 0.8);
        $result = $assertion->evaluate('café native');

        $this->assertTrue($result->passed);
        $this->assertGreaterThan(0.8, $result->score);
    }

    public function test_passes_with_high_dimensional_vectors(): void
    {
        $highDimVector1 = array_fill(0, 384, 0.1);
        $highDimVector1[0] = 1.0;

        $highDimVector2 = array_fill(0, 384, 0.1);
        $highDimVector2[0] = 0.9;

        $this->embeddingsProvider
            ->expects($this->exactly(2))
            ->method('embedText')
            ->willReturnMap([
                ['complex text', $highDimVector1],
                ['similar text', $highDimVector2],
            ]);

        $assertion = new StringSimilarity('complex text', $this->embeddingsProvider, 0.7);
        $result = $assertion->evaluate('similar text');

        $this->assertTrue($result->passed);
        $this->assertGreaterThan(0.7, $result->score);
    }

    public function test_get_name(): void
    {
        $assertion = new StringSimilarity('test', $this->embeddingsProvider, 0.6);
        $this->assertEquals('StringSimilarity', $assertion->getName());
    }
}
