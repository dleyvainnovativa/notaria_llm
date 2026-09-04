<?php

namespace App\Providers;

use App\Services\AiExtractionService;
use App\Services\OcrService;
use OpenAI;
use OpenAI\Contracts\ClientContract as OpenAIClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Local OCR (Poppler + Tesseract). Binary paths come from config so the
        // same code runs on Windows (absolute .exe paths) and macOS (PATH).
        $this->app->singleton(OcrService::class, function ($app) {
            $c = config('services.ocr');

            return new OcrService(
                tesseractBin: $c['tesseract'],
                pdftotextBin: $c['pdftotext'],
                pdftoppmBin: $c['pdftoppm'],
                language: $c['language'],
                minCharsPerPage: (int) $c['min_chars_per_page'],
                ocrDpi: (int) $c['dpi'],
            );
        });

        // OpenAI client, built from config with a generous timeout for big deeds.
        $this->app->singleton(OpenAIClient::class, function ($app) {
            $c = config('services.openai');

            $factory = OpenAI::factory()
                ->withApiKey((string) $c['api_key'])
                ->withHttpHeader('OpenAI-Beta', 'assistants=v2')
                ->withHttpClient(new \GuzzleHttp\Client([
                    'timeout' => (int) $c['request_timeout'],
                ]));

            if (! empty($c['organization'])) {
                $factory = $factory->withOrganization($c['organization']);
            }
            if (! empty($c['project'])) {
                $factory = $factory->withProject($c['project']);
            }

            return $factory->make();
        });

        // Extraction engine. Same class the controller already depends on; only
        // its collaborators changed (OCR + OpenAI instead of an HTTP service).
        $this->app->singleton(AiExtractionService::class, function ($app) {
            $c = config('services.openai');

            return new AiExtractionService(
                ocr: $app->make(OcrService::class),
                openai: $app->make(OpenAIClient::class),
                model: (string) $c['model'],
                maxSchemaRetries: (int) $c['max_schema_retries'],
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
