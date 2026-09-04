<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // The prompts/schema this document is processed with. In the
            // prototype they arrive per-upload; storing them makes async
            // processing and reprocessing possible (the request is long gone
            // by the time the queue worker runs). A templates table supersedes
            // these columns later.
            $table->text('system_prompt')->nullable()->after('template_id');
            $table->text('extraction_prompt')->nullable()->after('system_prompt');
            $table->json('json_schema')->nullable()->after('extraction_prompt');

            // Timing for the works/jobs view.
            $table->timestamp('processing_started_at')->nullable()->after('duration_ms');
            $table->timestamp('processing_finished_at')->nullable()->after('processing_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'system_prompt',
                'extraction_prompt',
                'json_schema',
                'processing_started_at',
                'processing_finished_at',
            ]);
        });
    }
};
