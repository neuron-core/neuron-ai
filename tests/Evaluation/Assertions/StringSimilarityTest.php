<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringSimilarity;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use stdClass;

use function array_fill;

class StringSimilarityTest extends TestCase
{
    /** @var MockObject&EmbeddingsProviderInterface */
    private MockObject $embeddingsProvider;

    protected function setUp(): void
    {
        $this->embeddingsProvider = $this->createMock(EmbeddingsProviderInterface::class);
    }

    public function testPassesWhenSimilarityIsAboveThreshold(): void
    {
        $this->embeddingsProvider
            ->expects($this->exactly(2))
            ->method('embedText')
            ->willReturnMap([
                ['hello world', [1.0, 0.0, 0.0]],
                ['hello earth', [0.9, 0.1, 0.0]]
            ]);

        $assertion = new StringSimilarity('hello world', $this->embeddingsProvider, 0.8);
        $result = $assertion->evaluate('hello earth');

        $this->assertTrue($result->passed);
        $this->assertGreaterThan(0.8, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function testPassesWhenIdenticalStrings(): void
    {
        $this->embeddingsProvider
            ->expects($this->exactly(2))
            ->method('embedText')
            ->willReturnMap([
                ['hello world', [1.0, 0.0, 0.0]],
                ['hello world', [1.0, 0.0, 0.0]]
            ]);

        $assertion = new StringSimilarity('hello world', $this->embeddingsProvider, 0.6);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWhenSimilarityIsBelowThreshold(): void
    {
        $this->embeddingsProvider
            ->expects($this->exactly(2))
            ->method('embedText')
            ->willReturnMap([
                ['hello world', [1.0, 0.0, 0.0]],
                ['goodbye universe', [0.0, 1.0, 0.0]]
            ]);

        $assertion = new StringSimilarity('hello world', $this->embeddingsProvider, 0.8);
        $result = $assertion->evaluate('goodbye universe');

        $this->assertFalse($result->passed);
        $this->assertLessThan(0.8, $result->score);
        $this->assertEquals("Expected 'goodbye universe' to be similar to 'hello world' (threshold: '0.8')", $result->message);
    }

    public function testFailsWithNonStringInput(): void
    {
        $assertion = new StringSimilarity('test', $this->embeddingsProvider, 0.6);
        $result = $assertion->evaluate(123);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got integer', $result->message);
    }

    public function testFailsWithArrayInput(): void
    {
        $assertion = new StringSimilarity('hello', $this->embeddingsProvider, 0.6);
        $result = $assertion->evaluate(['hello', 'world']);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got array', $result->message);
    }

    public function testFailsWithNullInput(): void
    {
        $assertion = new StringSimilarity('test', $this->embeddingsProvider, 0.6);
        $result = $assertion->evaluate(null);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got NULL', $result->message);
    }

    public function testFailsWithObjectInput(): void
    {
        $assertion = new StringSimilarity('test', $this->embeddingsProvider, 0.6);
        $result = $assertion->evaluate(new stdClass());

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got object', $result->message);
    }

    public function testHandlesEmbeddingsProviderException(): void
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

    public function testUsesDefaultThreshold(): void
    {
        $this->embeddingsProvider
            ->expects($this->exactly(2))
            ->method('embedText')
            ->willReturnMap([
                ['hello world', [1.0, 0.0, 0.0]],
                ['hello earth', [0.7, 0.3, 0.0]]
            ]);

        $assertion = new StringSimilarity('hello world', $this->embeddingsProvider); // Default threshold 0.6
        $result = $assertion->evaluate('hello earth');

        $this->assertTrue($result->passed);
        $this->assertGreaterThan(0.6, $result->score);
    }

    public function testFailsWithDefaultThresholdWhenSimilarityTooLow(): void
    {
        $this->embeddingsProvider
            ->expects($this->exactly(2))
            ->method('embedText')
            ->willReturnMap([
                ['hello world', [1.0, 0.0, 0.0]],
                ['completely different', [0.0, 0.0, 1.0]]
            ]);

        $assertion = new StringSimilarity('hello world', $this->embeddingsProvider); // Default threshold 0.6
        $result = $assertion->evaluate('completely different');

        $this->assertFalse($result->passed);
        $this->assertLessThan(0.6, $result->score);
        $this->assertEquals("Expected 'completely different' to be similar to 'hello world' (threshold: '0.6')", $result->message);
    }

    public function testHandlesUnicodeStrings(): void
    {
        $this->embeddingsProvider
            ->expects($this->exactly(2))
            ->method('embedText')
            ->willReturnMap([
                ['café naïve', [1.0, 0.0, 0.0]],
                ['café native', [0.9, 0.1, 0.0]]
            ]);

        $assertion = new StringSimilarity('café naïve', $this->embeddingsProvider, 0.8);
        $result = $assertion->evaluate('café native');

        $this->assertTrue($result->passed);
        $this->assertGreaterThan(0.8, $result->score);
    }

    public function testPassesWithHighDimensionalVectors(): void
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
                ['similar text', $highDimVector2]
            ]);

        $assertion = new StringSimilarity('complex text', $this->embeddingsProvider, 0.7);
        $result = $assertion->evaluate('similar text');

        $this->assertTrue($result->passed);
        $this->assertGreaterThan(0.7, $result->score);
    }

    public function testGetName(): void
    {
        $assertion = new StringSimilarity('test', $this->embeddingsProvider, 0.6);
        $this->assertEquals('StringSimilarity', $assertion->getName());
    }
}
