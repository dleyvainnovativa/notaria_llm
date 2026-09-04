<?php

namespace App\Console\Commands;

use App\Services\AiExtractionService;
use App\Services\OcrService;
use Illuminate\Console\Command;

/**
 * Phase-by-phase debugger for the extraction pipeline.
 *
 * Runs the SAME services the controller uses, so what you see here is what
 * production does — no parallel logic that can drift.
 *
 * Examples:
 *   php artisan extract:debug storage/app/private/documents/abc.pdf
 *       -> OCR phase only (no API call, no cost). Shows per-page path,
 *          char counts, detected rotation, and sample text.
 *
 *   php artisan extract:debug path/to.pdf --openai
 *       -> full pipeline: OCR, then the OpenAI request/response.
 *
 *   php artisan extract:debug path/to.pdf --openai --dump=debug.json
 *       -> also writes the full debug bundle (prompts, raw response) to a file.
 *          The file contains document text/PII — treat it as confidential.
 */
class ExtractDebugCommand extends Command
{
    protected $signature = 'extract:debug
        {pdf : Path to the PDF (absolute, or relative to the project root)}
        {--openai : Also run the OpenAI phase (makes an API call)}
        {--schema= : Path to a JSON schema file (defaults to the sample schema)}
        {--dump= : Write the full debug bundle to this JSON file}
        {--full-text : Print the entire extracted text, not just samples}';

    protected $description = 'Debug the OCR + OpenAI extraction pipeline phase by phase.';

    public function handle(OcrService $ocr, AiExtractionService $ai): int
    {
        $pdf = $this->argument('pdf');
        if (! str_starts_with($pdf, '/') && ! preg_match('/^[A-Za-z]:\\\\/', $pdf)) {
            $pdf = base_path($pdf);
        }

        if (! is_file($pdf)) {
            $this->error("PDF no encontrado: {$pdf}");

            return self::FAILURE;
        }

        // ---- PHASE 1: OCR / text extraction (no network) --------------------
        $this->line('');
        $this->components->info('FASE 1 — Extracción de texto (OCR local)');
        $this->line("Archivo: <comment>{$pdf}</comment>");

        try {
            $extraction = $ocr->extract($pdf);
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('OCR falló: '.$e->getMessage());
            $this->renderOcrDiagnostics($ocr->getDiagnostics());

            return self::FAILURE;
        }

        $diag = $ocr->getDiagnostics();
        $this->renderOcrDiagnostics($diag);

        if ($this->option('full-text')) {
            $this->newLine();
            $this->line('<comment>--- TEXTO COMPLETO ---</comment>');
            $this->line($extraction['text']);
        }

        // Sanity read-out that catches the usual failures at a glance.
        $this->newLine();
        if (($diag['total_chars'] ?? 0) === 0) {
            $this->warn('⚠ No se extrajo texto. Revisa binarios (pdftotext) o rotación (OCR).');
        } elseif ($extraction['ocr_used'] && ($diag['total_chars'] ?? 0) < 200) {
            $this->warn('⚠ Muy poco texto por OCR. Posible rotación no corregida o falta traineddata "osd".');
        } else {
            $this->components->info('Extracción OK: '.($diag['total_chars'] ?? 0).' caracteres, vía '.$extraction['extraction'].'.');
        }

        if (! $this->option('openai')) {
            $this->newLine();
            $this->line('Fase OpenAI omitida. Añade <comment>--openai</comment> para ejecutarla.');

            return self::SUCCESS;
        }

        // ---- PHASE 2 & 3: OpenAI request + result ---------------------------
        $schema = $this->resolveSchema();
        if ($schema === null) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('FASE 2/3 — OpenAI (petición y resultado)');

        $result = $ai->extract(
            absolutePdfPath: $pdf,
            originalFilename: basename($pdf),
            templateId: 'debug',
            systemPrompt: '',
            extractionPrompt: '',
            jsonSchema: $schema,
        );

        $debug = $ai->getLastDebug();

        // Request side
        if (isset($debug['request'])) {
            $req = $debug['request'];
            $this->line('<comment>Modelo:</comment> '.$req['model']);
            $this->line('<comment>Longitud system prompt:</comment> '.mb_strlen($req['system_prompt']).' chars');
            $this->line('<comment>Longitud user prompt:</comment> '.mb_strlen($req['user_prompt']).' chars');
            $this->line('<comment>Esquema estricto enviado:</comment>');
            $this->line(json_encode($req['strict_schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // Response side
        if (isset($debug['response'])) {
            $resp = $debug['response'];
            $this->newLine();
            $this->line('<comment>Intento:</comment> '.$resp['attempt']);
            $this->line('<comment>finish_reason:</comment> '.($resp['finish_reason'] ?? '—'));
            $this->line('<comment>Tokens:</comment> prompt='
                .($resp['usage']['prompt_tokens'] ?? '—')
                .' completion='.($resp['usage']['completion_tokens'] ?? '—')
                .' total='.($resp['usage']['total_tokens'] ?? '—'));
            $this->line('<comment>Respuesta cruda del modelo:</comment>');
            $this->line((string) ($resp['raw_content'] ?? '—'));
        }

        $this->newLine();
        if (! $result['ok']) {
            $this->error('Extracción falló: '.$result['error']);
        } else {
            $this->components->info('Resultado final (envelope de la app):');
            $this->line(json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        if ($dumpPath = $this->option('dump')) {
            $bundle = [
                'ocr' => $debug['ocr'] ?? $diag,
                'request' => $debug['request'] ?? null,
                'response' => $debug['response'] ?? null,
                'result' => $result,
            ];
            file_put_contents($dumpPath, json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();
            $this->warn("Bundle escrito en {$dumpPath} — contiene texto del documento (PII). Trátalo como confidencial.");
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderOcrDiagnostics(array $diag): void
    {
        if (isset($diag['binaries'])) {
            $this->newLine();
            $this->line('<comment>Binarios:</comment> pdftotext='.$diag['binaries']['pdftotext']
                .'  pdftoppm='.$diag['binaries']['pdftoppm']
                .'  tesseract='.$diag['binaries']['tesseract']);
            $this->line('<comment>Idioma:</comment> '.($diag['language'] ?? '—')
                .'  <comment>DPI:</comment> '.($diag['dpi'] ?? '—')
                .'  <comment>min_chars/página:</comment> '.($diag['min_chars_per_page'] ?? '—'));
        }

        $pages = $diag['pages'] ?? [];
        if ($pages === []) {
            return;
        }

        $this->newLine();
        $rows = [];
        foreach ($pages as $p) {
            $rows[] = [
                $p['page'],
                $p['path'],
                $p['native_chars'],
                $p['ocr_chars'] ?? '—',
                $p['rotation'] === null ? '—' : $p['rotation'].'°',
                str_replace(["\n", "\r"], ' ', mb_substr((string) $p['sample'], 0, 60)),
            ];
        }

        $this->table(
            ['Pág', 'Vía', 'Chars nativos', 'Chars OCR', 'Rotación', 'Muestra (60)'],
            $rows,
        );
    }

    private function resolveSchema(): ?string
    {
        $schemaPath = $this->option('schema');
        if ($schemaPath) {
            if (! is_file($schemaPath)) {
                $this->error("Esquema no encontrado: {$schemaPath}");

                return null;
            }
            $json = file_get_contents($schemaPath);
            if (json_decode($json) === null && json_last_error() !== JSON_ERROR_NONE) {
                $this->error('El archivo de esquema no es JSON válido.');

                return null;
            }

            return $json;
        }

        // Same sample schema shape the controller offers.
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
        ], JSON_UNESCAPED_UNICODE);
    }
}
