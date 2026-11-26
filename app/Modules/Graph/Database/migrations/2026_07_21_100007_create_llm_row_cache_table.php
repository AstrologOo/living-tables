<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No user_id: the key covers the row, the operation and the model, so answers are shared between users.
        Schema::create('llm_row_cache', function (Blueprint $table) {
            $table->char('cache_key', 64)->primary();
            $table->jsonb('output');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_row_cache');
    }
};
