<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the local AI extraction service (the Python/FastAPI app).
 *
 * Laravel knows only: input, status, response, errors. It does NOT know how the
 * AI service extracts text, runs OCR, or calls the model — that stays behind
 * this boundary so the engine can be replaced without touching Laravel.
 */
class AiExtractionService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {}

    /**
     * Send a PDF plus prompts/schema to the extract endpoint.
     *
     * @return array{ok: bool, data?: array, error?: string}
     */
    public function extract(
        string $absolutePdfPath,
        string $originalFilename,
        string $templateId,
        string $systemPrompt,
        string $extractionPrompt,
        string $jsonSchema,
    ): array {
        $url = rtrim($this->baseUrl, '/') . '/api/v1/documents/extract';

        try {
            $response = Http::timeout($this->timeout)
                ->attach(
                    'file',
                    file_get_contents($absolutePdfPath),
                    $originalFilename,
                    ['Content-Type' => 'application/pdf'],
                )
                ->asMultipart()
                ->post($url, [
                    'template_id' => $templateId,
                    'system_prompt' => $systemPrompt,
                    'extraction_prompt' => $extractionPrompt,
                    'json_schema' => $jsonSchema,
                ]);
        } catch (ConnectionException $e) {
            // Connection refused / DNS / timeout at the socket level.
            return [
                'ok' => false,
                'error' => 'No se pudo conectar con el servicio de extracción. '
                    . 'Verifica que esté en ejecución.',
            ];
        }

        $body = $response->json();

        // The AI service returns structured errors with success=false.
        if (! $response->successful() || ! is_array($body) || ($body['success'] ?? false) !== true) {
            $message = is_array($body)
                ? ($body['error']['message'] ?? 'El servicio de extracción devolvió un error.')
                : 'El servicio de extracción devolvió una respuesta inválida.';

            return ['ok' => false, 'error' => $message];
        }

        return ['ok' => true, 'data' => $body];
    }
}
