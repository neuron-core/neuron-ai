<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class SchemaProperty
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?bool $required = null,
        public ?int $min = null,
        public ?int $max = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?array $anyOf = null,
    ) {
    }
}
