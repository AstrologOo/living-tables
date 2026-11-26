<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->string('row_key');
            $table->char('row_hash', 64);
            $table->jsonb('data');
            $table->timestamp('created_at')->useCurrent();

            $table->index('node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_rows');
    }
};
