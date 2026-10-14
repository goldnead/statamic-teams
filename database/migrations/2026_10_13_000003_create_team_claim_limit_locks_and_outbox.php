<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_claim_limit_locks', function (Blueprint $table): void {
            $table->string('claimant_id', 191)->primary();
        });

        Schema::create('team_claim_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('claim_id')->constrained('team_claims')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->timestamp('created_at');
            $table->timestamp('processed_at')->nullable();
            // Consumer columns: a delivery job postpones blocked or erroring
            // rows by pushing available_at forward instead of starving on
            // them, counts its attempts and keeps the last failure code.
            $table->timestamp('available_at')->useCurrent();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->unique(['claim_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_claim_outbox');
        Schema::dropIfExists('team_claim_limit_locks');
    }
};
