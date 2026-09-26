<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use NeuronAI\HttpClient\HttpResponse;
use PHPUnit\Framework\TestCase;

class HttpResponseScalarJsonTest extends TestCase
{
    public function test_json_of_a_scalar_body_is_empty(): void
    {
        foreach (['42', '"ok"', 'true'] as $body) {
            $this->assertSame([], (new HttpResponse(200, $body))->json());
        }
    }
}
