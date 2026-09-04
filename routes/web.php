<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

// Prototype: no auth. Documents flow only.
Route::get('/', [DocumentController::class, 'index'])->name('documents.index');

Route::get('/documents/upload', [DocumentController::class, 'create'])
    ->name('documents.create');
Route::post('/documents', [DocumentController::class, 'store'])
    ->name('documents.store');

// Works/jobs view and reprocess action.
Route::get('/works', [DocumentController::class, 'works'])
    ->name('documents.works');
Route::post('/documents/{document}/reprocess', [DocumentController::class, 'reprocess'])
    ->name('documents.reprocess');

Route::get('/documents/{document}', [DocumentController::class, 'show'])
    ->name('documents.show');

// PDF streamed through the app (files are private, never served from /storage).
Route::get('/documents/{document}/pdf', [DocumentController::class, 'pdf'])
    ->name('documents.pdf');