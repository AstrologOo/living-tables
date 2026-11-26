<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * claim_token: the current owner of a `processing` node. data_revision: bumped by each webhook payload.
 * consecutive_failures: failed runs since the last success.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->uuid('claim_token')->nullable();
            $table->unsignedBigInteger('data_revision')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['claim_token', 'data_revision', 'consecutive_failures']);
        });
    }
};
