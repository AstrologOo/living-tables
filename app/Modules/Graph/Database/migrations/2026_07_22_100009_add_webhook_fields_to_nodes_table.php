<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            // How a source node is refreshed; null for other nodes.
            $table->enum('trigger', ['schedule', 'webhook'])->nullable();
            // Routes the webhook URL and keys the request signature.
            $table->string('webhook_secret')->nullable();
            // Latest accepted payload, as received.
            $table->jsonb('webhook_payload')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['trigger', 'webhook_secret', 'webhook_payload']);
        });
    }
};
