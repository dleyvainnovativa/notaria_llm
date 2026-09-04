<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Local text extraction. Nothing here ever touches the network.
 *
 * Strategy per PDF:
 *   1. pdftotext  -> try the native text layer (fast, free, no OCR).
 *   2. For any page whose native text is too thin, rasterize that page with
 *      pdftoppm and OCR the image with Tesseract (Spanish). Before OCR, detect
 *      page orientation with Tesseract OSD and rotate the image upright, so
 *      rotated scans (90/180/270 deg) still read.
 *   3. Assemble page-separated text using the `--- PAGE n ---` contract so the
 *      model can still cite the page an answer came from.
 *
 * Binaries (pdftotext, pdftoppm, tesseract) are resolved from explicit .env
 * paths first, then from PATH. Same code runs on Windows and macOS.
 *
 * Diagnostics: every extract() run records per-page detail (path taken, char
 * counts, detected rotation, sample text, timings). Read it with
 * getDiagnostics() — the extract:debug command uses this. Diagnostics are held
 * in memory only; they are never logged (they contain document text).
 */
class OcrService
{
    /** @var array<string, mixed> */
    private array $diagnostics = [];

    public function __construct(
        private readonly string $tesseractBin,
        private readonly string $pdftotextBin,
        private readonly string $pdftoppmBin,
        private readonly string $language = 'spa',
        private readonly int $minCharsPerPage = 40,
        private readonly int $ocrDpi = 300,
    ) {}

    /** Diagnostics from the most recent extract() call. */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @return array{text: string, pages: int, ocr_used: bool, ocr_pages: int[], extraction: string}
     */
    public function extract(string $absolutePdfPath): array
    {
        $this->diagnostics = [
            'pdf_path' => $absolutePdfPath,
            'binaries' => [
                'pdftotext' => $this->pdftotextBin,
                'pdftoppm' => $this->pdftoppmBin,
                'tesseract' => $this->tesseractBin,
            ],
            'language' => $this->language,
            'min_chars_per_page' => $this->minCharsPerPage,
            'dpi' => $this->ocrDpi,
            'pages' => [],
        ];

        if (! is_file($absolutePdfPath)) {
            throw new RuntimeException('PDF no encontrado para extracción de texto.');
        }

        $nativePages = $this->nativeTextByPage($absolutePdfPath);
        $pageCount = count($nativePages);
        $this->diagnostics['page_count'] = $pageCount;

        if ($pageCount === 0) {
            throw new RuntimeException('No se pudo determinar el contenido del PDF.');
        }

        $ocrPages = [];
        $finalPages = [];

        foreach ($nativePages as $index => $nativeText) {
            $pageNumber = $index + 1;
            $clean = trim($nativeText);
            $nativeChars = mb_strlen(preg_replace('/\s+/', '', $clean));

            $pageDiag = [
                'page' => $pageNumber,
                'native_chars' => $nativeChars,
                'path' => null,
                'rotation' => null,
                'ocr_chars' => null,
                'sample' => null,
            ];

            if ($nativeChars >= $this->minCharsPerPage) {
                $finalPages[$pageNumber] = $clean;
                $pageDiag['path'] = 'native';
                $pageDiag['sample'] = mb_substr($clean, 0, 200);
                $this->diagnostics['pages'][] = $pageDiag;

                continue;
            }

            // Thin/empty native layer -> this page is (probably) scanned. OCR it.
            $ocr = $this->ocrPage($absolutePdfPath, $pageNumber);
            $ocrText = trim($ocr['text']);
            $pageDiag['rotation'] = $ocr['rotation'];
            $pageDiag['ocr_chars'] = mb_strlen(preg_replace('/\s+/', '', $ocrText));

            if ($ocrText !== '') {
                $finalPages[$pageNumber] = $ocrText;
                $ocrPages[] = $pageNumber;
                $pageDiag['path'] = 'ocr';
                $pageDiag['sample'] = mb_substr($ocrText, 0, 200);
            } else {
                // Keep whatever native text existed, even if thin, rather than drop the page.
                $finalPages[$pageNumber] = $clean;
                $pageDiag['path'] = 'ocr_empty';
                $pageDiag['sample'] = mb_substr($clean, 0, 200);
            }

            $this->diagnostics['pages'][] = $pageDiag;
        }

        $assembled = $this->assemble($finalPages);

        if (trim(preg_replace('/--- PAGE \d+ ---/', '', $assembled)) === '') {
            throw new RuntimeException('No se encontró texto legible en el documento.');
        }

        $ocrUsed = count($ocrPages) > 0;
        $extraction = $ocrUsed
            ? (count($ocrPages) === $pageCount ? 'ocr' : 'mixed')
            : 'native';

        $this->diagnostics['ocr_used'] = $ocrUsed;
        $this->diagnostics['ocr_pages'] = $ocrPages;
        $this->diagnostics['extraction'] = $extraction;
        $this->diagnostics['total_chars'] = mb_strlen(preg_replace('/--- PAGE \d+ ---/', '', $assembled));

        return [
            'text' => $assembled,
            'pages' => $pageCount,
            'ocr_used' => $ocrUsed,
            'ocr_pages' => $ocrPages,
            'extraction' => $extraction,
        ];
    }

    /**
     * Native text layer, one string per page.
     * pdftotext separates pages with the form-feed char (\f = \x0C).
     *
     * @return array<int, string> zero-indexed page texts
     */
    private function nativeTextByPage(string $pdfPath): array
    {
        $process = new Process([
            $this->pdftotextBin,
            '-layout',
            '-enc', 'UTF-8',
            $pdfPath,
            '-', // write to stdout
        ]);
        $process->setTimeout(300);

        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'No se pudo ejecutar pdftotext. Verifica que Poppler esté instalado '
                . 'y que OCR_PDFTOTEXT_PATH sea correcto. ('.$e->getMessage().')'
            );
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'pdftotext falló: '.trim($process->getErrorOutput() ?: 'error desconocido')
            );
        }

        $out = $process->getOutput();
        $pages = explode("\f", $out);

        if (count($pages) > 1 && trim(end($pages)) === '') {
            array_pop($pages);
        }

        return array_values($pages);
    }

    /**
     * Rasterize a single page to PNG (pdftoppm), detect orientation (Tesseract
     * OSD), rotate upright if needed, then OCR it.
     *
     * @return array{text: string, rotation: int|null}
     */
    private function ocrPage(string $pdfPath, int $pageNumber): array
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'ocr_');
        @unlink($tmpBase);
        $imagePrefix = $tmpBase;

        try {
            $render = new Process([
                $this->pdftoppmBin,
                '-png',
                '-r', (string) $this->ocrDpi,
                '-f', (string) $pageNumber,
                '-l', (string) $pageNumber,
                '-singlefile',
                $pdfPath,
                $imagePrefix,
            ]);
            $render->setTimeout(300);
            $render->run();

            if (! $render->isSuccessful()) {
                throw new RuntimeException(
                    'pdftoppm falló en la página '.$pageNumber.': '
                    .trim($render->getErrorOutput() ?: 'error desconocido')
                );
            }

            $imagePath = $imagePrefix.'.png';
            if (! is_file($imagePath)) {
                $imagePath = $imagePrefix.'-'.$pageNumber.'.png';
            }
            if (! is_file($imagePath)) {
                throw new RuntimeException('No se generó la imagen para OCR (página '.$pageNumber.').');
            }

            try {
                // Detect orientation first; Tesseract's OSD reports rotation.
                $rotation = $this->detectRotation($imagePath);

                // Ask Tesseract to auto-rotate using OSD during recognition.
                // --psm 1 = automatic page segmentation WITH orientation/script detection.
                $ocr = new Process([
                    $this->tesseractBin,
                    $imagePath,
                    'stdout',
                    '-l', $this->language,
                    '--psm', '1',
                ]);
                $ocr->setTimeout(300);
                $ocr->run();

                $text = $ocr->isSuccessful() ? $ocr->getOutput() : '';

                // Fallback: if psm 1 produced nothing and OSD saw a rotation,
                // rotate the image explicitly and retry with plain psm 3.
                if (trim($text) === '' && $rotation !== null && $rotation !== 0) {
                    $rotated = $this->rotateImage($imagePath, $rotation);
                    if ($rotated !== null) {
                        try {
                            $retry = new Process([
                                $this->tesseractBin,
                                $rotated,
                                'stdout',
                                '-l', $this->language,
                                '--psm', '3',
                            ]);
                            $retry->setTimeout(300);
                            $retry->run();
                            if ($retry->isSuccessful()) {
                                $text = $retry->getOutput();
                            }
                        } finally {
                            @unlink($rotated);
                        }
                    }
                }

                return ['text' => $text, 'rotation' => $rotation];
            } finally {
                @unlink($imagePath);
            }
        } finally {
            foreach (glob($imagePrefix.'*') ?: [] as $stray) {
                @unlink($stray);
            }
        }
    }

    /**
     * Tesseract OSD: returns clockwise degrees the page is rotated, or null if
     * OSD is unavailable/failed. "Rotate: 90" in OSD output means the page must
     * be rotated 90 deg to become upright.
     */
    private function detectRotation(string $imagePath): ?int
    {
        try {
            $osd = new Process([
                $this->tesseractBin,
                $imagePath,
                'stdout',
                '--psm', '0', // OSD only
            ]);
            $osd->setTimeout(120);
            $osd->run();

            if (! $osd->isSuccessful()) {
                return null;
            }

            if (preg_match('/Rotate:\s*(\d+)/', $osd->getOutput(), $m)) {
                return (int) $m[1];
            }
        } catch (\Throwable) {
            // OSD needs the osd traineddata; if absent, just skip rotation.
        }

        return null;
    }

    /**
     * Rotate a PNG by the given clockwise degrees using GD. Returns the path to
     * a new temp file, or null if GD is unavailable. GD rotates counter-clockwise
     * for positive angles, so we negate.
     */
    private function rotateImage(string $imagePath, int $clockwiseDegrees): ?string
    {
        if (! function_exists('imagerotate') || ! function_exists('imagecreatefrompng')) {
            return null;
        }

        $src = @imagecreatefrompng($imagePath);
        if ($src === false) {
            return null;
        }

        $rotated = imagerotate($src, -$clockwiseDegrees, 0);
        imagedestroy($src);
        if ($rotated === false) {
            return null;
        }

        $out = tempnam(sys_get_temp_dir(), 'rot_').'.png';
        imagepng($rotated, $out);
        imagedestroy($rotated);

        return is_file($out) ? $out : null;
    }

    /** Join pages with the `--- PAGE n ---` markers the evidence contract expects. */
    private function assemble(array $pagesByNumber): string
    {
        ksort($pagesByNumber);
        $parts = [];

        foreach ($pagesByNumber as $number => $text) {
            $parts[] = "--- PAGE {$number} ---\n\n".rtrim($text);
        }

        return implode("\n\n", $parts)."\n";
    }
}
