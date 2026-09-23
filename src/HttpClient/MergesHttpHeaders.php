<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use function strtolower;

/**
 * @internal
 */
trait MergesHttpHeaders
{
    /**
     * @param array<string, string> $defaults
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    protected function mergeRequestHeaders(array $defaults, array $overrides): array
    {
        $headers = [];
        $names = [];
        foreach ([$defaults, $overrides] as $source) {
            foreach ($source as $name => $value) {
                $normalized = strtolower($name);
                if (isset($names[$normalized])) {
                    unset($headers[$names[$normalized]]);
                }
                $names[$normalized] = $name;
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
