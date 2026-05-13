<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Stub;

class Person
{
    public string $name;
    public int $age;
    public Address $address;
    /** @var Contact[] */
    public array $contacts;
    /** @var string[] */
    public array $tags;
    public ?Company $company = null;
}
