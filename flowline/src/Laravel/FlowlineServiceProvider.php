<?php

declare(strict_types=1);

namespace Flowline\Laravel;

use Flowline\Client;
use Illuminate\Support\ServiceProvider;

final class FlowlineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/flowline.php',
            'flowline',
        );

        $this->app->singleton(Client::class, function () {
            $config = $this->app['config']->get('flowline');

            return new Client(
                appName: (string) ($config['app_name'] ?? ''),
                serveUrl: (string) ($config['serve_url'] ?? ''),
                signingKey: $config['signing_key'] ?? null,
                eventKey: (string) ($config['event_key'] ?? ''),
                platformUrl: (string) ($config['platform_url'] ?? 'https://api.flowline.dev'),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/flowline.php' => $this->app->configPath('flowline.php'),
            ], 'flowline-config');
        }

        $path = '/' . ltrim((string) $this->app['config']->get('flowline.path', 'api/flowline'), '/');

        $this->app['router']->match(
            ['GET', 'PUT', 'POST'],
            $path,
            [FlowlineController::class, '__invoke'],
        );
    }
}
