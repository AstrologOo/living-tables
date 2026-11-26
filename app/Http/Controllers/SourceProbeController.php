<?php

namespace App\Http\Controllers;

use App\Modules\Graph\Services\SourceProbeService;
use App\Modules\Shared\Exceptions\ResponseTooLargeException;
use App\Modules\Shared\Exceptions\SsrfBlockedException;
use App\Modules\Shared\Exceptions\TooManyRedirectsException;
use App\Support\PublicErrorMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Probes a user's source URL. Failures answer 422 with a fixed text, so internal hosts and addresses aren't
 * revealed.
 */
class SourceProbeController extends Controller
{
    public function probe(Request $request, SourceProbeService $probe): JsonResponse
    {
        $data = $request->validate([
            'url' => 'required|string|min:1',
        ]);

        try {
            return response()->json($probe->probe($data['url']));
        } catch (SsrfBlockedException|ResponseTooLargeException|TooManyRedirectsException|RequestException|ConnectionException $e) {
            Log::warning("Source probe failed: {$e->getMessage()}", [
                'user_id' => $request->user()->id,
                'exception' => $e::class,
            ]);

            return response()->json(['error' => PublicErrorMessage::forSource($e)], 422);
        }
    }
}
