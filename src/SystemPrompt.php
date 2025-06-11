<?php

namespace NeuronAI;

class SystemPrompt implements \Stringable
{
    public function __construct(
        public array $background,
        public array $steps = [],
        public array $output = [],
        public array $toolsUsage = []
    ) {
    }

    public function __toString(): string
    {
        $prompt = "# IDENTITY AND PURPOSE" . PHP_EOL . implode(PHP_EOL, $this->background);

        if (!empty($this->steps)) {
            $prompt .= PHP_EOL . PHP_EOL . "# INTERNAL ASSISTANT STEPS" . PHP_EOL . implode(PHP_EOL, $this->steps);
        }

        if (!empty($this->output)) {
            $prompt .= PHP_EOL . PHP_EOL . "# OUTPUT INSTRUCTIONS" . PHP_EOL . " - " . implode(PHP_EOL . " - ", $this->output);
        }

        if (!empty($this->toolsUsage)) {
            $prompt .= PHP_EOL . PHP_EOL . "# TOOLS USAGE RULES" . PHP_EOL . " - " . implode(PHP_EOL . " - ", $this->toolsUsage);
        }

        return $prompt;
    }
}
