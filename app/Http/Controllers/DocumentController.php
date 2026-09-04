<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DocumentController extends Controller
{
    /** List of processed documents (simple prototype index). */
    public function index(): View
    {
        $documents = Document::latest()->limit(50)->get();

        return view('documents.index', compact('documents'));
    }

    /** Upload form. */
    public function create(): View
    {
        return view('documents.create', [
            'defaultSchema' => $this->sampleSchema(),
        ]);
    }

    /**
     * Store the upload and queue extraction. Returns immediately — the queue
     * worker (php artisan queue:work) does the OCR + OpenAI call. Watch progress
     * on the works page.
     */
    public function store(StoreDocumentRequest $request): RedirectResponse
    {
        try {
            $file = $request->file('file');

            // Store privately (NOT under public/). Stored name is random, so the
            // original filename never drives the path.
            $storedPath = $file->store('documents', 'local');

            $schema = (string) $request->string('json_schema');

            $document = Document::create([
                'uuid' => (string) Str::uuid(),
                'original_filename' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'file_size' => $file->getSize(),
                'template_id' => $request->string('template_id'),
                // Store the prompts so the job (and any reprocess) can run later,
                // long after this request has ended.
                'system_prompt' => (string) $request->string('system_prompt'),
                'extraction_prompt' => (string) $request->string('extraction_prompt'),
                'json_schema' => $schema !== '' ? json_decode($schema, true) : null,
                'status' => 'queued',
            ]);

            ProcessDocumentJob::dispatch($document->uuid);

            return redirect()
                ->route('documents.works')
                ->with('success', 'Documento en cola. El procesamiento comenzará en breve.');
        } catch (\Throwable $e) {
            Log::error('Error queueing document: '.$e->getMessage(), ['exception' => $e]);

            return redirect()
                ->route('documents.create')
                ->with('error', 'No se pudo poner el documento en cola.');
        }
    }

    /** Re-queue a document that already finished or failed. */
    public function reprocess(Document $document): RedirectResponse
    {
        if (! $document->canReprocess()) {
            return redirect()
                ->route('documents.works')
                ->with('error', 'El documento ya está en cola o procesándose.');
        }

        $document->update([
            'status' => 'queued',
            'error_message' => null,
            'processing_started_at' => null,
            'processing_finished_at' => null,
        ]);

        ProcessDocumentJob::dispatch($document->uuid);

        return redirect()
            ->route('documents.works')
            ->with('success', 'Documento re-encolado.');
    }

    /** Works/jobs view: processing status per document. */
    public function works(): View
    {
        $documents = Document::latest()->limit(100)->get();

        return view('documents.works', compact('documents'));
    }

    /** Preview page: PDF beside extracted data. */
    public function show(Document $document): View
    {
        return view('documents.show', compact('document'));
    }

    /** Stream the private PDF through an authenticated app route. */
    public function pdf(Document $document): Response
    {
        abort_unless(Storage::disk('local')->exists($document->stored_path), 404);

        return response(
            Storage::disk('local')->get($document->stored_path),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'
                    . addslashes($document->original_filename) . '"',
            ],
        );
    }

    /** A neutral starter schema so the form isn't empty. */
    private function sampleSchema(): string
{
    return json_encode([
        'type' => 'object',
        'properties' => [
            'numero_escritura' => ['type' => ['string', 'null']],
            'fecha' => ['type' => ['string', 'null']],
            'notaria' => [
                'type' => 'object',
                'properties' => [
                    'notario' => ['type' => ['string', 'null']],
                    'numero_notaria' => ['type' => ['string', 'null']],
                ],
            ],
            'enajenantes' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'nombre' => ['type' => 'string'],
                        'rol' => ['type' => ['string', 'null']],
                        'rfc' => ['type' => ['string', 'null']],
                        'curp' => ['type' => ['string', 'null']],
                    ],
                    'required' => ['nombre'],
                ],
            ],
            'adquirientes' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'nombre' => ['type' => 'string'],
                        'rol' => ['type' => ['string', 'null']],
                        'rfc' => ['type' => ['string', 'null']],
                        'curp' => ['type' => ['string', 'null']],
                    ],
                    'required' => ['nombre'],
                ],
            ],
        ],
        'required' => ['numero_escritura', 'enajenantes', 'adquirientes'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
}
