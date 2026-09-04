<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'uuid',
        'original_filename',
        'stored_path',
        'file_size',
        'template_id',
        'system_prompt',
        'extraction_prompt',
        'json_schema',
        'status',
        'error_message',
        'model',
        'pages',
        'text_extraction',
        'ocr_used',
        'duration_ms',
        'processing_started_at',
        'processing_finished_at',
        'extracted_data',
        'evidence',
    ];

    protected $casts = [
        'extracted_data' => 'array',
        'evidence' => 'array',
        'json_schema' => 'array',
        'ocr_used' => 'boolean',
        'processing_started_at' => 'datetime',
        'processing_finished_at' => 'datetime',
    ];

    /**
     * Route-model binding uses the public uuid, never the numeric id (avoids
     * exposing sequential ids / IDOR surface).
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isQueued(): bool
    {
        return $this->status === 'queued';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /** True while the document is waiting or being worked on. */
    public function isPending(): bool
    {
        return in_array($this->status, ['queued', 'processing'], true);
    }

    /** Can this document be (re)processed right now? */
    public function canReprocess(): bool
    {
        return in_array($this->status, ['processed', 'failed'], true);
    }
}
