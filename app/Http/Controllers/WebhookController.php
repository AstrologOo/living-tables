<?php

namespace App\Http\Controllers;

use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Services\WebhookIngestService;
use App\Modules\Graph\Services\WorkerWaker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Webhook entry point: routes the request, checks its signature and passes the payload on.
 */
class WebhookController extends Controller
{
    public function ingest(Request $request, string $node, string $secret, WebhookIngestService $ingestService, WorkerWaker $worker): Response
    {
        $node = Node::query()
            ->where('id', $node)
            ->where('type', 'source')
            ->where('trigger', 'webhook')
            ->where('webhook_secret', $secret)
            ->first();

        // The same answer for an unknown node, a wrong type or a wrong secret.
        if ($node === null) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        // Exactly the bytes the sender signed.
        $rawBody = $request->getContent();
        $signature = $request->header('X-Webhook-Signature');

        if (! $ingestService->verifySignature($node, $rawBody, $signature)) {
            // No details that would help an attacker.
            abort(HttpResponse::HTTP_FORBIDDEN);
        }

        $payload = $request->json()->all();

        $ingestService->ingest($node, $payload);

        $worker->wake($node->id);

        return response()->noContent();
    }
}
