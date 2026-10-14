<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_claims', function (Blueprint $table) {
            $table->id();
            $table->string('resource_type', 64);
            $table->string('resource_key', 191);
            $table->string('site', 64);
            $table->string('claimant_id', 191)->index();
            $table->text('justification');
            $table->string('contact', 255);
            $table->string('status', 16)->default('pending')->index();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('decision_by', 191)->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            // Unique while pending, NULL after decision. This remains safe on
            // SQL engines without partial unique indexes.
            $table->string('pending_key', 64)->nullable()->unique();
            $table->timestamps();
            $table->index(['resource_type', 'resource_key', 'site']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_claims');
    }
};
