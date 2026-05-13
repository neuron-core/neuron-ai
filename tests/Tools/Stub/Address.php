<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Stub;

class Address
{
    public string $street;
    public string $city;
    public string $zipCode;
    /** @var float[] */
    public array $coordinates;
}
