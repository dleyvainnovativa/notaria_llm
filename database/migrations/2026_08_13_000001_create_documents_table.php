<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Original + stored file info. Files live on a private disk; the
            // stored name is a UUID so the original filename never drives a path.
            $table->string('original_filename');
            $table->string('stored_path');
            $table->unsignedBigInteger('file_size');

            // What template/prompt/schema this was processed with. In the
            // prototype these are sent per-upload; later they come from a
            // templates table.
            $table->string('template_id')->nullable();

            // Processing lifecycle. Kept as a simple string in the prototype;
            // becomes an enum-backed column when the full app is built.
            $table->string('status')->default('uploaded');
            $table->text('error_message')->nullable();

            // AI service results.
            $table->string('model')->nullable();
            $table->unsignedInteger('pages')->nullable();
            $table->string('text_extraction')->nullable(); // native | ocr | mixed
            $table->boolean('ocr_used')->default(false);
            $table->unsignedBigInteger('duration_ms')->nullable();

            // The clean extracted structure and the evidence map, stored as JSON.
            $table->json('extracted_data')->nullable();
            $table->json('evidence')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
