<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

use NeuronAI\AgentInterface;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

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

    public function resetAssertionCounts(): void
    {
        $this->assertionsPassed = 0;
        $this->assertionsFailed = 0;
        $this->assertionFailures = [];
    }

    private function recordAssertion(bool $result, string $assertionMethod, string $message = '', array $context = []): bool
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
    protected function assertContains(string $needle, string $haystack): bool
    {
        $result = \str_contains($haystack, $needle);
        return $this->recordAssertion(
            $result,
            __FUNCTION__,
            $result ? '' : "Expected '$haystack' to contain '$needle'",
            ['needle' => $needle, 'haystack' => $haystack]
        );
    }

    /**
     * Assert that a string contains any of the provided keywords
     * @param array<string> $keywords
     */
    protected function assertContainsAny(array $keywords, string $haystack): bool
    {
        $result = false;
        foreach ($keywords as $keyword) {
            if (\str_contains(\strtolower($haystack), \strtolower($keyword))) {
                $result = true;
                break;
            }
        }
        return $this->recordAssertion(
            $result,
            __FUNCTION__,
            $result ? '' : "Expected '$haystack' to contain any of: " . \implode(', ', $keywords),
            ['keywords' => $keywords, 'haystack' => $haystack]
        );
    }

    /**
     * Assert that a string contains all of the provided keywords
     * @param array<string> $keywords
     */
    protected function assertContainsAll(array $keywords, string $haystack): bool
    {
        $result = true;
        $missing = [];
        foreach ($keywords as $keyword) {
            if (!\str_contains(\strtolower($haystack), \strtolower($keyword))) {
                $result = false;
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
    protected function assertLengthBetween(string $string, int $min, int $max): bool
    {
        $length = \strlen($string);
        $result = $length >= $min && $length <= $max;
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
    protected function assertResponseStartsWith(string $expected, string $actual): bool
    {
        $result = \str_starts_with($actual, $expected);
        return $this->recordAssertion(
            $result,
            __FUNCTION__,
            $result ? '' : "Expected response to start with '$expected'",
            ['expected' => $expected, 'actual' => $actual]
        );
    }

    /**
     * Assert that response ends with expected string
     */
    protected function assertResponseEndsWith(string $expected, string $actual): bool
    {
        $result = \str_ends_with($actual, $expected);
        return $this->recordAssertion(
            $result,
            __FUNCTION__,
            $result ? '' : "Expected response to end with '$expected'",
            ['expected' => $expected, 'actual' => $actual]
        );
    }

    /**
     * Assert that two strings are equal (case-insensitive)
     */
    protected function assertEqualsIgnoreCase(string $expected, string $actual): bool
    {
        $result = \strtolower(\trim($expected)) === \strtolower(\trim($actual));
        return $this->recordAssertion(
            $result,
            __FUNCTION__,
            $result ? '' : "Expected '$actual' to equal '$expected' (case insensitive)",
            ['expected' => $expected, 'actual' => $actual]
        );
    }

    /**
     * Assert that string matches a regular expression
     */
    protected function assertMatchesRegex(string $pattern, string $subject): bool
    {
        $result = \preg_match($pattern, $subject) === 1;
        return $this->recordAssertion(
            $result,
            __FUNCTION__,
            $result ? '' : "Expected '$subject' to match pattern '$pattern'",
            ['pattern' => $pattern, 'subject' => $subject]
        );
    }

    /**
     * Assert that response is not empty
     */
    protected function assertNotEmpty(string $response): bool
    {
        $result = !\in_array(\trim($response), ['', '0'], true);
        return $this->recordAssertion(
            $result,
            __FUNCTION__,
            $result ? '' : 'Expected response to not be empty',
            ['response' => $response]
        );
    }

    /**
     * Assert that response is JSON
     */
    protected function assertIsJson(string $response): bool
    {
        \json_decode($response);
        $result = \json_last_error() === \JSON_ERROR_NONE;
        return $this->recordAssertion(
            $result,
            __FUNCTION__,
            $result ? '' : 'Expected valid JSON response: ' . \json_last_error_msg(),
            ['response' => $response, 'json_error' => \json_last_error_msg()]
        );
    }

    /**
     * Assert using an AI judge agent
     */
    protected function assertWithAIJudge(
        AgentInterface $judgeAgent,
        string $output,
        float $threshold = 0.7
    ): bool {
        try {
            $score = $judgeAgent->structured(
                new UserMessage($this->buildJudgePrompt($output)),
                JudgeScore::class
            );

            $result = $score->score >= $threshold;

            return $this->recordAssertion(
                $result,
                __FUNCTION__,
                $result ? '' : "AI Judge failed: {$score->reasoning} (Score: {$score->score}, Threshold: {$threshold})",
                [
                    'judge_score' => $score,
                    'threshold' => $threshold,
                    'output' => $output
                ]
            );
        } catch (Throwable $e) {
            return $this->recordAssertion(
                false,
                __FUNCTION__,
                "AI Judge error: {$e->getMessage()}",
                ['error' => $e->getMessage(), 'output' => $output]
            );
        }
    }

    /**
     * Build the prompt for AI judge evaluation
     */
    private function buildJudgePrompt(string $output): string
    {
        return "Evaluate the following output:\n\n" .
               "OUTPUT:\n{$output}\n\n" .
               "Provide a score between 0.0 and 1.0, detailed reasoning, and whether it passes.";
    }
}
