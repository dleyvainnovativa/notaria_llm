<?php

namespace App\Services;

use OpenAI\Contracts\ClientContract as OpenAIClient;
use Throwable;

/**
 * Extraction engine: local OCR + OpenAI.
 *
 * Public contract is UNCHANGED from the previous (Python-service) version:
 * same method name, same arguments, same return shape. DocumentController and
 * the Blade views keep working without edits. Only the internals changed —
 * instead of POSTing the PDF to a local service, we now:
 *
 *   1. Extract page-separated TEXT locally (OcrService). The PDF never leaves
 *      the machine; only the extracted text is sent onward.
 *   2. Send that text to OpenAI with a strict JSON-schema structured output.
 *   3. Shape the reply into the { success, document, model, processing, data,
 *      evidence, warnings } envelope the app already consumes.
 *
 * Privacy note: sending extracted text (not page images) to OpenAI is the
 * data-minimizing choice and keeps cost down. Request Zero Data Retention on
 * the OpenAI account for notarial data; training on API data is already
 * excluded by default.
 */
class AiExtractionService
{
    /** Captured detail from the most recent extract() call, for debugging. */
    private array $lastDebug = [];

    public function __construct(
        private readonly OcrService $ocr,
        private readonly OpenAIClient $openai,
        private readonly string $model,
        private readonly int $maxSchemaRetries = 2,
    ) {}

    /**
     * Debug detail from the most recent extract(): the OCR diagnostics, the
     * exact prompts and strict schema sent to OpenAI, the raw model response,
     * and token usage. Never logged (contains document text) — used only by the
     * extract:debug command.
     */
    public function getLastDebug(): array
    {
        return $this->lastDebug;
    }

    /**
     * @return array{ok: bool, data?: array, error?: string, exception?: Throwable}
     */
    public function extract(
        string $absolutePdfPath,
        string $originalFilename,
        string $templateId,
        string $systemPrompt,
        string $extractionPrompt,
        string $jsonSchema,
    ): array {
        $startedAt = microtime(true);
        $this->lastDebug = [];

        // --- 1. Local text extraction (OCR only when needed) ------------------
        try {
            $extraction = $this->ocr->extract($absolutePdfPath);
        } catch (Throwable $e) {
            $this->lastDebug['ocr'] = $this->ocr->getDiagnostics();
            return ['ok' => false, 'error' => $e->getMessage(), 'exception' => $e];
        }

        $this->lastDebug['ocr'] = $this->ocr->getDiagnostics();
        $documentText = $extraction['text'];

        // --- 2. Build the OpenAI request -------------------------------------
        $schema = json_decode($jsonSchema, true);
        if (! is_array($schema)) {
            return ['ok' => false, 'error' => 'El esquema JSON proporcionado no es válido.'];
        }

        // OpenAI strict structured output requires the schema to be strict-safe.
        $strictSchema = $this->makeStrict($schema);

        $system = $this->buildSystemPrompt($systemPrompt);
        $user = $this->buildUserPrompt($extractionPrompt, $documentText);

        $this->lastDebug['request'] = [
            'model' => $this->model,
            'system_prompt' => $system,
            'user_prompt' => $user,
            'strict_schema' => $strictSchema,
        ];

        // --- 3. Call the model, with a small retry budget --------------------
        $lastError = null;
        $data = null;

        for ($attempt = 0; $attempt <= $this->maxSchemaRetries; $attempt++) {
            try {
                $response = $this->openai->chat()->create([
                    'model' => $this->model,
                    'temperature' => 0,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'notarial_extraction',
                            'strict' => true,
                            'schema' => $strictSchema,
                        ],
                    ],
                ]);
            } catch (Throwable $e) {
                // Auth, network, or model-side failure. Retrying rarely helps
                // for these, so surface immediately.
                return [
                    'ok' => false,
                    'error' => 'Error al comunicarse con OpenAI: ' . $e->getMessage(),
                    'exception' => $e,
                ];
            }

            $content = $response->choices[0]->message->content ?? null;

            $this->lastDebug['response'] = [
                'attempt' => $attempt,
                'raw_content' => $content,
                'finish_reason' => $response->choices[0]->finishReason ?? null,
                'usage' => [
                    'prompt_tokens' => $response->usage->promptTokens ?? null,
                    'completion_tokens' => $response->usage->completionTokens ?? null,
                    'total_tokens' => $response->usage->totalTokens ?? null,
                ],
            ];

            if ($content === null || trim($content) === '') {
                $lastError = 'La respuesta del modelo llegó vacía.';

                continue;
            }

            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                $lastError = 'La respuesta del modelo no es JSON válido.';

                continue;
            }

            $data = $decoded;
            break;
        }

        if ($data === null) {
            return ['ok' => false, 'error' => $lastError ?? 'No se obtuvo una respuesta válida del modelo.'];
        }

        // --- 4. Shape into the app's existing envelope -----------------------
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        // Under strict structured output the model returns an object matching
        // the TEMPLATE schema directly (flat: numero_escritura, enajenantes, …)
        // — not a { data, evidence, warnings } wrapper. The strict schema wins
        // over any "wrap it" instruction, so $data IS the extracted data.
        //
        // If a schema ever explicitly defines top-level `data`/`evidence` keys,
        // honor them; otherwise treat the whole decoded object as the data.
        $hasWrapper = array_key_exists('data', $data) && is_array($data['data']);

        $extracted = $hasWrapper ? $data['data'] : $data;
        $evidence = $hasWrapper ? ($data['evidence'] ?? []) : [];
        $warnings = $hasWrapper ? ($data['warnings'] ?? []) : [];

        $body = [
            'success' => true,
            'document' => [
                'pages' => $extraction['pages'],
                'text_extraction' => $extraction['extraction'],
            ],
            'model' => [
                'name' => $this->model,
            ],
            'processing' => [
                'ocr_used' => $extraction['ocr_used'],
                'ocr_pages' => $extraction['ocr_pages'],
                'duration_ms' => $durationMs,
            ],
            'data' => $extracted,
            'evidence' => $evidence,
            'warnings' => $warnings,
        ];

        return ['ok' => true, 'data' => $body];
    }

    /**
     * Base extraction rules + the template's own system prompt.
     * The base rules encode the notarial-extraction behaviour that used to live
     * in the Python service's system prompt.
     */
    private function buildSystemPrompt(string $templateSystemPrompt): string
    {
        $base = <<<'TXT'
Eres un motor de extracción de información especializado en documentos notariales mexicanos.

Tu tarea es identificar información CONTENIDA EXPLÍCITAMENTE en el documento proporcionado y mapearla al esquema JSON indicado.

Reglas:
- No inventes información. Si un dato no aparece con soporte suficiente en el texto, usa null o un arreglo vacío según el esquema.
- Conserva nombres, identificadores legales, folios, números de escritura e identificadores de inmuebles tal como están escritos.
- Nunca generes RFC, CURP, direcciones, fechas o montos por suposición.
- El RFC de persona física tiene 13 caracteres; el RFC de persona moral tiene 12. La CURP tiene 18 caracteres. No confundas RFC con CURP.
- No uses el RFC ni los datos del encabezado de la notaría para las partes (enajenantes/adquirientes).
- Distingue con cuidado entre personas físicas y morales y entre sus roles legales (enajenante, adquiriente, representante, apoderado, acreedor, deudor, compareciente, notario).
- Una persona puede tener más de un rol.
- Solo produce la información solicitada por el esquema.

El texto del documento está dividido por páginas con marcadores "--- PAGE n ---".

Devuelve un único objeto JSON que cumpla EXACTAMENTE el esquema proporcionado. No lo envuelvas en claves adicionales ni agregues campos fuera del esquema.
TXT;

        $template = trim($templateSystemPrompt);

        return $template === ''
            ? $base
            : $base . "\n\n--- INSTRUCCIONES DE LA PLANTILLA ---\n" . $template;
    }

    private function buildUserPrompt(string $extractionPrompt, string $documentText): string
    {
        $instructions = trim($extractionPrompt);
        $prefix = $instructions === ''
            ? ''
            : "Instrucciones de extracción:\n" . $instructions . "\n\n";

        return $prefix . "Documento:\n\n" . $documentText;
    }

    /**
     * OpenAI strict structured output requires, at every object level:
     *   - "additionalProperties": false
     *   - every property listed in "required"
     * Optional fields are expressed as a type union with "null" (the template
     * schemas already do this, e.g. ["string","null"]). This walks the schema
     * and enforces those two rules without changing the field shapes.
     */
    private function makeStrict(array $schema): array
    {
        $type = $schema['type'] ?? null;
        $isObject = $type === 'object'
            || (is_array($type) && in_array('object', $type, true))
            || isset($schema['properties']);

        if ($isObject && isset($schema['properties']) && is_array($schema['properties'])) {
            $schema['additionalProperties'] = false;
            $schema['required'] = array_keys($schema['properties']);

            foreach ($schema['properties'] as $key => $prop) {
                if (is_array($prop)) {
                    $schema['properties'][$key] = $this->makeStrict($prop);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->makeStrict($schema['items']);
        }

        return $schema;
    }
}
