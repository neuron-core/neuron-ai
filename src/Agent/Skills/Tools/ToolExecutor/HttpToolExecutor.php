<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills\Tools\ToolExecutor;

use NeuronAI\Agent\Skills\Tools\ToolDefinition;
use NeuronAI\Agent\Skills\Tools\ToolExecutorInterface;
use NeuronAI\Agent\Skills\Tools\ToolResult;

use function curl_close;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt;
use function curl_strerror;
use function is_array;
use function json_encode;
use function str_replace;
use function strtolower;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;

class HttpToolExecutor implements ToolExecutorInterface
{
    public function supports(string $type): bool
    {
        return $type === 'http';
    }

    public function execute(ToolDefinition $definition, array $inputs): ToolResult
    {
        $config = $definition->execution;
        $url = $this->resolveUrl($config['url'] ?? '', $inputs);
        $method = strtoupper($config['http_method'] ?? 'GET');
        $timeout = (int) ($config['timeout'] ?? 30);
        $retries = (int) ($config['retry'] ?? 0);
        $headers = $config['headers'] ?? [];

        $attempt = 0;
        while (true) {
            $result = $this->sendRequest($url, $method, $inputs, $headers, $timeout, $config);

            if ($result->isSuccess() || $attempt >= $retries) {
                return $result;
            }

            $attempt++;
        }
    }

    private function resolveUrl(string $template, array $inputs): string
    {
        foreach ($inputs as $key => $value) {
            $template = str_replace('{{' . $key . '}}', urlencode((string) $value), $template);
        }

        return $template;
    }

    /**
     * @param array<string, mixed> $inputs
     * @param array<string, string> $headers
     * @param array<string, mixed> $config
     */
    private function sendRequest(
        string $url,
        string $method,
        array $inputs,
        array $headers,
        int $timeout,
        array $config,
    ): ToolResult {
        $ch = curl_init();

        if ($ch === false) {
            return new ToolResult(
                exitCode: 1,
                error: 'Failed to initialize cURL.',
            );
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $body = $config['body'] ?? $inputs;
            $curlHeaders[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if ($curlHeaders !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return new ToolResult(
                exitCode: 1,
                error: $error ?: 'cURL request failed.',
            );
        }

        $output = is_string($response) ? $response : '';

        return new ToolResult(
            exitCode: ($httpCode >= 200 && $httpCode < 300) ? 0 : 1,
            output: $output,
            metadata: ['http_code' => $httpCode],
            error: ($httpCode >= 400) ? "HTTP {$httpCode}" : null,
        );
    }
}
