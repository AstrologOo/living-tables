<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A row_key is unique within a version, while one stored row may appear under several keys. The plain index
 * keeps joins fast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_version_rows', function (Blueprint $table) {
            $table->dropUnique(['version_id', 'row_id']);
            $table->index(['version_id', 'row_id']);
            $table->unique(['version_id', 'row_key']);
        });
    }

    public function down(): void
    {
        Schema::table('node_version_rows', function (Blueprint $table) {
            $table->dropUnique(['version_id', 'row_key']);
            $table->dropIndex(['version_id', 'row_id']);
            $table->unique(['version_id', 'row_id']);
        });
    }
};
