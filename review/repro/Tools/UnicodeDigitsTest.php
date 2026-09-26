<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tools\Toolkits\Calculator\Expression;
use NeuronAI\Tools\Toolkits\Calculator\ExpressionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnicodeDigitsTest extends TestCase
{
    #[DataProvider('nonAsciiDigits')]
    public function test_non_ascii_digits_are_rejected_instead_of_read_as_zero(string $expression, string $message): void
    {
        $this->expectException(ExpressionException::class);
        $this->expectExceptionMessage($message);

        Expression::evaluate($expression);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nonAsciiDigits(): array
    {
        return [
            'arabic-indic three plus one' => ['٣+1', "Unexpected character '٣'"],
            'fullwidth three' => ['３', "Unexpected character '３'"],
            'devanagari seven times two' => ['७*2', "Unexpected character '७'"],
            'ascii digit followed by arabic-indic digit' => ['1٣', "Unexpected character '٣'"],
        ];
    }

    public function test_ascii_digits_still_evaluate(): void
    {
        $this->assertSame(4, Expression::evaluate('3+1'));
        $this->assertSame(1.5e3, Expression::evaluate('1.5e3'));
    }
}
