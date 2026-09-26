<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use Psr\Http\Message\RequestInterface;

use function implode;
use function str_contains;
use function strtolower;

/** Asserts an API key reaches the remote service only through its authentication header. */
trait AssertsApiKeyConfinement
{
    protected function assertApiKeyTravelsOnlyIn(string $header, string $apiKey, RequestInterface $request): void
    {
        $headersCarryingTheKey = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (str_contains(implode(', ', $values), $apiKey)) {
                $headersCarryingTheKey[] = strtolower((string) $name);
            }
        }

        $this->assertSame([strtolower($header)], $headersCarryingTheKey);
        $this->assertStringNotContainsString($apiKey, (string) $request->getUri());
        $this->assertStringNotContainsString($apiKey, (string) $request->getBody());
    }
}
