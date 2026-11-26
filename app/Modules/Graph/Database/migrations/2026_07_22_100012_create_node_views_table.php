<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A view is saved filter and sort criteria, read live from the node, so it has no versions or status.
        Schema::create('node_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->string('name');
            $table->jsonb('filter')->nullable();
            $table->string('sort_field')->nullable();
            $table->string('sort_order')->nullable();
            $table->unsignedInteger('limit_count')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_views');
    }
};
