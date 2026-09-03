<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

// Prototype: no auth. Documents flow only.
Route::get('/', [DocumentController::class, 'index'])->name('documents.index');

Route::get('/documents/upload', [DocumentController::class, 'create'])
    ->name('documents.create');
Route::post('/documents', [DocumentController::class, 'store'])
    ->name('documents.store');

Route::get('/documents/{document}', [DocumentController::class, 'show'])
    ->name('documents.show');

// PDF streamed through the app (files are private, never served from /storage).
Route::get('/documents/{document}/pdf', [DocumentController::class, 'pdf'])
    ->name('documents.pdf');


    Route::get("phpinfo", function () {
        return phpinfo();
    });