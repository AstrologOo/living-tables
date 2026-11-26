<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tail: the trailing sort/unique/limit steps saved with a config, so rollback can restore the default view. Null in
 * entries older than this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_config_versions', function (Blueprint $table) {
            $table->jsonb('tail')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('node_config_versions', function (Blueprint $table) {
            $table->dropColumn('tail');
        });
    }
};
