<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves Swagger UI and the hand-written openapi.yaml.
 */
class DocsController extends Controller
{
    public function index(): Response
    {
        return response()->view('docs');
    }

    public function spec(): BinaryFileResponse
    {
        return response()->file(base_path('openapi.yaml'), [
            'Content-Type' => 'application/yaml',
        ]);
    }
}
