<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\AiExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs one document's extraction on the queue worker.
 *
 * Design notes:
 * - The payload carries only the document id — never the PDF, prompts, or any
 *   personal data. Those are read from the DB inside handle().
 * - The heavy OCR + OpenAI call lives in AiExtractionService, unchanged. This
 *   job is just the async wrapper the controller used to do synchronously.
 * - Retries are disabled ($tries = 1). A partial/failed extraction should be
 *   inspected on the works page and reprocessed deliberately, not silently
 *   retried (which would re-send document text to OpenAI and re-run OCR).
 */
class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** A big deed on OCR + a slow API call needs room. */
    public int $timeout = 900;

    public function __construct(
        public readonly string $documentId,
    ) {}

    public function handle(AiExtractionService $ai): void
    {
        $document = Document::where('uuid', $this->documentId)->first();

        if ($document === null) {
            Log::warning('ProcessDocumentJob: document not found', ['uuid' => $this->documentId]);

            return;
        }

        // Guard against double-processing (e.g. a stray re-dispatch).
        if ($document->isProcessing()) {
            Log::info('ProcessDocumentJob: already processing, skipping', ['uuid' => $this->documentId]);

            return;
        }

        $document->update([
            'status' => 'processing',
            'processing_started_at' => now(),
            'processing_finished_at' => null,
            'error_message' => null,
        ]);

        $path = Storage::disk('local')->path($document->stored_path);

        $result = $ai->extract(
            absolutePdfPath: $path,
            originalFilename: $document->original_filename,
            templateId: (string) $document->template_id,
            systemPrompt: (string) $document->system_prompt,
            extractionPrompt: (string) $document->extraction_prompt,
            jsonSchema: $document->json_schema
                ? json_encode($document->json_schema)
                : '',
        );

        if (! $result['ok']) {
            // Log only the error message, never document text or prompts.
            Log::error('Document extraction failed', [
                'uuid' => $document->uuid,
                'error' => $result['error'],
            ]);

            $document->update([
                'status' => 'failed',
                'error_message' => $result['error'],
                'processing_finished_at' => now(),
            ]);

            return;
        }

        $body = $result['data'];

        $document->update([
            'status' => 'processed',
            'model' => $body['model']['name'] ?? null,
            'pages' => $body['document']['pages'] ?? null,
            'text_extraction' => $body['document']['text_extraction'] ?? null,
            'ocr_used' => $body['processing']['ocr_used'] ?? false,
            'duration_ms' => $body['processing']['duration_ms'] ?? null,
            'extracted_data' => $body['data'] ?? [],
            'evidence' => $body['evidence'] ?? [],
            'processing_finished_at' => now(),
        ]);
    }

    /** If the job itself throws (timeout, worker crash), mark the doc failed. */
    public function failed(Throwable $e): void
    {
        $document = Document::where('uuid', $this->documentId)->first();

        if ($document !== null && ! $document->isProcessed()) {
            $document->update([
                'status' => 'failed',
                'error_message' => 'El procesamiento falló: '.$e->getMessage(),
                'processing_finished_at' => now(),
            ]);
        }

        Log::error('ProcessDocumentJob failed', [
            'uuid' => $this->documentId,
            'error' => $e->getMessage(),
        ]);
    }
}
