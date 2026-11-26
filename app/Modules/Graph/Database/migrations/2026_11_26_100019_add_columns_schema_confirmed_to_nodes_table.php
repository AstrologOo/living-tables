<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * columns_schema_confirmed: whether a user confirmed columns_schema, or it just follows the latest result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('columns_schema_confirmed')->default(false);
        });

        // Until now a schema was only ever saved by confirming it.
        DB::table('nodes')
            ->whereNotNull('columns_schema')
            ->whereRaw("CAST(columns_schema AS TEXT) NOT IN ('[]', '{}', 'null')")
            ->update(['columns_schema_confirmed' => true]);
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('columns_schema_confirmed');
        });
    }
};
