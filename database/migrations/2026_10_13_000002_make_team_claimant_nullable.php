<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_claims', function (Blueprint $table) {
            $table->string('claimant_id', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('team_claims')->whereNull('claimant_id')->exists()) {
            throw new RuntimeException('Cannot make team_claims.claimant_id required while anonymized claim history exists.');
        }

        Schema::table('team_claims', function (Blueprint $table) {
            $table->string('claimant_id', 191)->nullable(false)->change();
        });
    }
};
