<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // unique_field deduplicates on read; is_default marks the view built from a pipeline's trailing steps.
        Schema::table('node_views', function (Blueprint $table) {
            $table->string('unique_field')->nullable()->after('sort_order');
            $table->boolean('is_default')->default(false)->after('unique_field');
        });
    }

    public function down(): void
    {
        Schema::table('node_views', function (Blueprint $table) {
            $table->dropColumn(['unique_field', 'is_default']);
        });
    }
};
