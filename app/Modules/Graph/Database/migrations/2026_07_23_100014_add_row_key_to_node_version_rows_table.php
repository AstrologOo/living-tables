<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * row_key moves to the version's rows, since identical rows with different keys share one stored row. Older
 * versions keep null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_version_rows', function (Blueprint $table) {
            $table->string('row_key')->nullable()->after('row_id');
        });
    }

    public function down(): void
    {
        Schema::table('node_version_rows', function (Blueprint $table) {
            $table->dropColumn('row_key');
        });
    }
};
