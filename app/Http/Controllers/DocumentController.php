<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Models\Document;
use App\Services\AiExtractionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DocumentController extends Controller
{
    public function __construct(
        private readonly AiExtractionService $ai,
    ) {}

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
     * Store the upload, call the AI service synchronously, save the result.
     *
     * Synchronous is acceptable for the prototype. The controller stays thin:
     * validation is in the Form Request, the AI call is in the service.
     */
    public function store(StoreDocumentRequest $request): RedirectResponse
    {
        set_time_limit(0); // no PHP time limit for this synchronous extraction
        try{
        $file = $request->file('file');

        // Store privately (NOT under public/). Stored name is random, so the
        // original filename never drives the path.
        $storedPath = $file->store('documents', 'local');

        $document = Document::create([
            'uuid' => (string) Str::uuid(),
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'file_size' => $file->getSize(),
            'template_id' => $request->string('template_id'),
            'status' => 'processing',
        ]);

        $result = $this->ai->extract(
            absolutePdfPath: Storage::disk('local')->path($storedPath),
            originalFilename: $document->original_filename,
            templateId: (string) $request->string('template_id'),
            systemPrompt: (string) $request->string('system_prompt'),
            extractionPrompt: (string) $request->string('extraction_prompt'),
            jsonSchema: (string) $request->string('json_schema'),
        );

        if (! $result['ok']) {
            Log::error('Error processing document: ' . $result['error'], ['exception' => $result['exception'] ?? null]);


            $document->update([
                'status' => 'failed',
                'error_message' => $result['error'],
            ]);

            return redirect()
                ->route('documents.show', $document)
                ->with('error', $result['error']);
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
        ]);

        return redirect()
            ->route('documents.show', $document)
            ->with('success', 'Documento procesado.');
        } catch (\Exception $e) {
            Log::error('Error processing document: ' . $e->getMessage(), ['exception' => $e]);
            return redirect()
            ->route('documents.create')
            ->with('error', 'Error processing document.');
        }
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
