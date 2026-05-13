<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Stub;

class NestedComplexStructure
{
    public string $id;
    /** @var Person[] */
    public array $people;
    /** @var array<string, Company> */
    public array $companies;
    public array $metadata;
}
