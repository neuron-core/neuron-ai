<?php

declare(strict_types=1);

namespace NeuronAI\Tests\GraphStore;

use NeuronAI\RAG\GraphStore\Triplet;
use PHPUnit\Framework\TestCase;

class TripletTest extends TestCase
{
    public function test_create_triplet(): void
    {
        $triplet = new Triplet('Alice', 'KNOWS', 'Bob');

        $this->assertEquals('Alice', $triplet->subject);
        $this->assertEquals('KNOWS', $triplet->relation);
        $this->assertEquals('Bob', $triplet->object);
    }

    public function test_from_array(): void
    {
        $triplet = Triplet::fromArray('John', 'WORKS_AT', 'Google');

        $this->assertEquals('John', $triplet->subject);
        $this->assertEquals('WORKS_AT', $triplet->relation);
        $this->assertEquals('Google', $triplet->object);
    }

    public function test_to_array(): void
    {
        $triplet = new Triplet('Alice', 'KNOWS', 'Bob');
        $array = $triplet->toArray();

        $this->assertEquals(['Alice', 'KNOWS', 'Bob'], $array);
    }

    public function test_roundtrip_conversion(): void
    {
        $original = ['Subject', 'RELATION', 'Object'];
        $triplet = Triplet::fromArray($original[0], $original[1], $original[2]);
        $converted = $triplet->toArray();

        $this->assertEquals($original, $converted);
    }
}
