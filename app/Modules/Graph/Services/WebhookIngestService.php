<?php

namespace App\Modules\Graph\Services;

use App\Modules\Graph\Models\Node;
use Illuminate\Support\Facades\DB;

/**
 * Verifies and stores incoming webhook payloads.
 */
class WebhookIngestService
{
    /**
     * Checks the HMAC over the body exactly as received. This is the authentication; the URL secret only routes, as
     * URLs end up in logs.
     */
    public function verifySignature(Node $node, string $rawBody, ?string $signature): bool
    {
        if ($node->webhook_secret === null || $signature === null || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $node->webhook_secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Stores the payload and marks the node `stale`. A node being computed keeps its status, claim and updated_at
     * and only gets a new data_revision, so frequent payloads can't keep cancelling its run.
     */
    public function ingest(Node $node, array $payload): void
    {
        DB::update(<<<'SQL'
            UPDATE nodes
            SET webhook_payload = ?,
                data_revision = data_revision + 1,
                status = CASE WHEN status = 'processing' THEN status ELSE 'stale' END,
                updated_at = CASE WHEN status = 'processing' THEN updated_at ELSE ? END
            WHERE id = ?
            SQL, [json_encode($payload, JSON_THROW_ON_ERROR), now(), $node->id]);
    }
}
