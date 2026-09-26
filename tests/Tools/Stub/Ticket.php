<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Stub;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;

/**
 * Covers every branch of the class-to-property conversion: scalars, a backed
 * enum, a nullable scalar, a nullable nested object and an array of objects.
 */
class Ticket
{
    #[SchemaProperty(description: 'What needs to be done')]
    public string $title;

    public TicketPriority $priority;

    public ?int $estimate = null;

    public bool $urgent;

    public float $score;

    public ?Address $location = null;

    #[SchemaProperty(anyOf: [Contact::class])]
    #[ArrayOf(Contact::class)]
    public array $watchers;
}
