<?php

use App\Http\Controllers\DocsController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/docs');

// Read-only graph viewer over the API.
Route::get('/graph', function () {
    return view('graph');
});

// Swagger UI for openapi.yaml.
Route::get('/docs', [DocsController::class, 'index']);
Route::get('/docs/openapi.yaml', [DocsController::class, 'spec']);

// The secret routes the request; the controller also requires a valid signature.
// A malformed id must not reach Postgres, which rejects it with an error.
Route::post('/webhooks/{node}/{secret}', [WebhookController::class, 'ingest'])->whereUuid('node');
