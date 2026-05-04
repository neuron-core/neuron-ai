<?php

declare(strict_types=1);

namespace Flowline\Protocol;

/**
 * The payload sent to the platform during sync/registration.
 * Describes the app and all its registered tasks.
 */
final class RegistrationPayload
{
    /**
     * @param string $url The SDK endpoint URL the platform should call back to
     * @param string $appName Application identifier
     * @param string $sdk SDK identifier and version
     * @param string $v Protocol version
     * @param list<array{id: string, name: string, triggers: list<array>, steps: array}> $functions Registered task configurations
     */
    public function __construct(
        public readonly string $url,
        public readonly string $appName,
        public readonly string $sdk,
        public readonly string $v = '0.1',
        public readonly array $functions = [],
    ) {}

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'appName' => $this->appName,
            'sdk' => $this->sdk,
            'v' => $this->v,
            'functions' => $this->functions,
        ];
    }
}
