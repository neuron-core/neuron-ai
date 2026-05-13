<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Stub;

class Company
{
    public string $name;
    public Address $headquarters;
    /** @var Address[] */
    public array $offices;
}
