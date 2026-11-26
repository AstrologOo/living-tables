<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only history of a node's pipelines, used for rollback.
        Schema::create('node_config_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->jsonb('dsl_config');
            $table->char('config_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index('node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_config_versions');
    }
};
