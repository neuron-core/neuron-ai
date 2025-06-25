<?php

declare(strict_types=1);

namespace NeuronAI;

use Illuminate\Support\ServiceProvider;
use NeuronAI\Console\Commands\AgentMakeCommand;

class NeuronServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->commands([
            AgentMakeCommand::class,
        ]);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/Console/stubs' => base_path('stubs/neuron-ai'),
            ], 'neuron-ai-stubs');
        }
    }
}
