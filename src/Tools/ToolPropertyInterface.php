<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use JsonSerializable;
use NeuronAI\Exceptions\InvalidToolInput;

interface ToolPropertyInterface extends JsonSerializable
{
    public function getName(): string;

    public function getType(): PropertyType;

    public function getDescription(): ?string;

    public function isRequired(): bool;

    public function isNullable(): bool;

    public function getJsonSchema(): array;

    /**
     * The input converted to the declared type when nothing is lost, as PHP's
     * coercive mode would do: the model often sends a number as "5" or 5.0.
     *
     * @throws InvalidToolInput When the input cannot be converted
     */
    public function cast(mixed $input): mixed;
}
