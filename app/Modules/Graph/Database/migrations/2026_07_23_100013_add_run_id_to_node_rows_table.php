<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * run_id marks rows a run has written but no version has adopted yet; null for committed rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_rows', function (Blueprint $table) {
            $table->uuid('run_id')->nullable()->after('row_hash');
            $table->index(['node_id', 'run_id']);
        });
    }

    public function down(): void
    {
        Schema::table('node_rows', function (Blueprint $table) {
            $table->dropIndex(['node_id', 'run_id']);
            $table->dropColumn('run_id');
        });
    }
};
