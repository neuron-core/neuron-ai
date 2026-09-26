<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\IsValidJson;
use NeuronAI\Evaluation\Assertions\MatchesRegex;
use NeuronAI\Evaluation\Assertions\StringAssertion;
use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\Assertions\StringContainsAll;
use NeuronAI\Evaluation\Assertions\StringContainsAny;
use NeuronAI\Evaluation\Assertions\StringDistance;
use NeuronAI\Evaluation\Assertions\StringEndsWith;
use NeuronAI\Evaluation\Assertions\StringLengthBetween;
use NeuronAI\Evaluation\Assertions\StringSimilarity;
use NeuronAI\Evaluation\Assertions\StringStartsWith;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * The type guard shared by every string assertion: a non-string is a coding
 * error in the evaluator, never a failed verdict about the agent.
 */
class StringAssertionTest extends TestCase
{
    /**
     * @return iterable<string, array{StringAssertion}>
     */
    public static function stringAssertions(): iterable
    {
        yield 'IsValidJson' => [new IsValidJson()];
        yield 'MatchesRegex' => [new MatchesRegex('/x/')];
        yield 'StringContains' => [new StringContains('x')];
        yield 'StringContainsAll' => [new StringContainsAll(['x'])];
        yield 'StringContainsAny' => [new StringContainsAny(['x'])];
        yield 'StringDistance' => [new StringDistance('x')];
        yield 'StringEndsWith' => [new StringEndsWith('x')];
        yield 'StringLengthBetween' => [new StringLengthBetween(0, 10)];
        yield 'StringStartsWith' => [new StringStartsWith('x')];
    }

    /**
     * @return iterable<string, array{StringAssertion, mixed, string}>
     */
    public static function nonStringInputs(): iterable
    {
        $inputs = [
            'null' => [null, 'null'],
            'int' => [42, 'int'],
            'float' => [1.5, 'float'],
            'bool' => [false, 'bool'],
            'array' => [['x'], 'array'],
            'trajectory' => [Trajectory::fromMessages([new UserMessage('x')]), Trajectory::class],
            'stringable object' => [new UserMessage('x'), UserMessage::class],
        ];

        foreach (self::stringAssertions() as $assertionName => [$assertion]) {
            foreach ($inputs as $inputName => [$input, $type]) {
                yield "{$assertionName} with {$inputName}" => [$assertion, $input, $type];
            }
        }
    }

    #[DataProvider('nonStringInputs')]
    public function test_non_string_input_is_rejected_with_the_offending_type(StringAssertion $assertion, mixed $input, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($assertion::class . " evaluates a string, got {$type}");

        $assertion->evaluate($input);
    }

    public function test_rejects_input_before_calling_the_embeddings_provider(): void
    {
        $provider = $this->createMock(EmbeddingsProviderInterface::class);
        $provider->expects($this->never())->method('embedText');

        $this->expectException(InvalidArgumentException::class);

        (new StringSimilarity('reference', $provider))->evaluate(new class () implements Stringable {
            public function __toString(): string
            {
                return 'reference';
            }
        });
    }
}
