<?php

namespace App\Providers;

use App\Services\AiExtractionService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Inject the local AI service config so controllers/services never read
        // config() directly and the base URL/timeout live in one place.
        $this->app->singleton(AiExtractionService::class, function ($app) {
            return new AiExtractionService(
                baseUrl: config('services.local_ai.url'),
                timeout: (int) config('services.local_ai.timeout'),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
