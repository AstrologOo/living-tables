<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->foreignUuid('depends_on_id')->constrained('nodes')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['node_id', 'depends_on_id']);
            $table->index('depends_on_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edges');
    }
};
