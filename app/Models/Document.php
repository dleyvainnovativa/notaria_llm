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
        'status',
        'error_message',
        'model',
        'pages',
        'text_extraction',
        'ocr_used',
        'duration_ms',
        'extracted_data',
        'evidence',
    ];

    protected $casts = [
        'extracted_data' => 'array',
        'evidence' => 'array',
        'ocr_used' => 'boolean',
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
}
