<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

class Assertions
{
    private int $assertionsPassed = 0;

    private int $assertionsFailed = 0;

    /** @var array<AssertionFailure> */
    private array $assertionFailures = [];

    public function getAssertionsPassed(): int
    {
        return $this->assertionsPassed;
    }

    public function getAssertionsFailed(): int
    {
        return $this->assertionsFailed;
    }

    public function getTotalAssertions(): int
    {
        return $this->assertionsPassed + $this->assertionsFailed;
    }

    /**
     * @return array<AssertionFailure>
     */
    public function getAssertionFailures(): array
    {
        return $this->assertionFailures;
    }

    private function recordAssertion(float $result, string $assertionMethod, string $message = '', array $context = []): float
    {
        if ($result) {
            $this->assertionsPassed++;
        } else {
            $this->assertionsFailed++;
            $this->recordAssertionFailure($assertionMethod, $message, $context);
        }
        return $result;
    }

    private function recordAssertionFailure(string $assertionMethod, string $message, array $context): void
    {
        // Get the calling line from backtrace (skip recordAssertion and recordAssertionFailure)
        $backtrace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $lineNumber = $backtrace[2]['line'] ?? 0;

        $this->assertionFailures[] = new AssertionFailure(
            static::class,
            $assertionMethod,
            $message !== '' && $message !== '0' ? $message : 'Assertion failed',
            $lineNumber,
            $context
        );
    }

    /**
     * Assert that a string contains a substring
     */
    protected function assertStringContains(string $needle, string $haystack): float
    {
        $result = \str_contains($haystack, $needle);
        return $this->recordAssertion(
            $result ? 1.0 : 0.0,
            __FUNCTION__,
            $result ? '' : "Expected '$haystack' to contain '$needle'",
            ['needle' => $needle, 'haystack' => $haystack]
        );
    }

    /**
     * Assert that a string contains any of the provided keywords
     * @param array<string> $keywords
     */
    protected function assertStringContainsAny(array $keywords, string $haystack): float
    {
        $result = false;
        foreach ($keywords as $keyword) {
            if (\str_contains(\strtolower($haystack), \strtolower($keyword))) {
                $result = true;
                break;
            }
        }
        return $this->recordAssertion(
            $result ? 1.0 : 0.0,
            __FUNCTION__,
            $result ? '' : "Expected '$haystack' to contain any of: " . \implode(', ', $keywords),
            ['keywords' => $keywords, 'haystack' => $haystack]
        );
    }

    /**
     * Assert that a string contains all of the provided keywords
     * @param array<string> $keywords
     */
    protected function assertStringContainsAll(array $keywords, string $haystack): float
    {
        $result = 1.0;
        $missing = [];
        foreach ($keywords as $keyword) {
            if (!\str_contains(\strtolower($haystack), \strtolower($keyword))) {
                $result = 0.0;
                $missing[] = $keyword;
            }
        }
        return $this->recordAssertion(
            $result,
            __FUNCTION__,
            $result ? '' : "Expected '$haystack' to contain all keywords. Missing: " . \implode(', ', $missing),
            ['keywords' => $keywords, 'haystack' => $haystack, 'missing' => $missing]
        );
    }

    /**
     * Assert that string length is between min and max
     */
    protected function assertStringLengthBetween(string $string, int $min, int $max): float
    {
        $length = \strlen($string);
        $result = $length >= $min && $length <= $max ? 1.0 : 0.0;
        return $this->recordAssertion(
            $result,
            __FUNCTION__,
            $result ? '' : "Expected string length to be between $min and $max, got $length",
            ['string' => $string, 'min' => $min, 'max' => $max, 'actual_length' => $length]
        );
    }

    /**
     * Assert that response starts with expected string
     */
    protected function assertStringStartsWith(string $expected, string $actual): float
    {
        $result = \str_starts_with($actual, $expected);
        return $this->recordAssertion(
            $result ? 1.0 : 0.0,
            __FUNCTION__,
            $result ? '' : "Expected response to start with '$expected'",
            ['expected' => $expected, 'actual' => $actual]
        );
    }

    /**
     * Assert that response ends with expected string
     */
    protected function assertStringEndsWith(string $expected, string $actual): float
    {
        $result = \str_ends_with($actual, $expected);
        return $this->recordAssertion(
            $result ? 1.0 : 0.0,
            __FUNCTION__,
            $result ? '' : "Expected response to end with '$expected'",
            ['expected' => $expected, 'actual' => $actual]
        );
    }

    /**
     * Assert that string matches a regular expression
     */
    protected function assertMatchesRegex(string $pattern, string $subject): float
    {
        $result = \preg_match($pattern, $subject) === 1;
        return $this->recordAssertion(
            $result ? 1.0 : 0.0,
            __FUNCTION__,
            $result ? '' : "Expected '$subject' to match pattern '$pattern'",
            ['pattern' => $pattern, 'subject' => $subject]
        );
    }

    /**
     * Assert that response is JSON
     */
    protected function assertIsValidJson(string $response): float
    {
        \json_decode($response);
        $result = \json_last_error() === \JSON_ERROR_NONE;
        return $this->recordAssertion(
            $result ? 1.0 : 0.0,
            __FUNCTION__,
            $result ? '' : 'Expected valid JSON response: ' . \json_last_error_msg(),
            ['response' => $response, 'json_error' => \json_last_error_msg()]
        );
    }
}
