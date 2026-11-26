<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->foreignUuid('version_id')->nullable()->constrained('node_versions')->nullOnDelete();
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('tokens_spent')->default(0);
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_changed')->default(0);
            $table->string('status');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_stats');
    }
};
