<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['source', 'transform']);
            $table->string('name');
            $table->jsonb('dsl_config');
            $table->jsonb('columns_schema')->nullable();
            $table->string('schedule')->nullable();
            $table->enum('status', ['fresh', 'stale', 'processing', 'failed', 'schema_drift'])->default('stale');
            $table->char('content_hash', 64)->nullable();
            $table->char('config_hash', 64);
            // The foreign key is added once node_versions exists.
            $table->uuid('current_version_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
