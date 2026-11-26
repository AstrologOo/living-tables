<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_version_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('version_id')->constrained('node_versions')->cascadeOnDelete();
            $table->foreignUuid('row_id')->constrained('node_rows')->cascadeOnDelete();

            // Also serves as the index on version_id.
            $table->unique(['version_id', 'row_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_version_rows');
    }
};
