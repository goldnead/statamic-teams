<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Four tables: the team, who is in it, who is invited, and the roles a team
 * defines on top of the configured ones.
 *
 * `user_id` is a string everywhere. Statamic's file repository keys users by
 * UUID, the Eloquent repository by integer, and a team must work with both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('type', 32)->default('team')->index();
            $table->string('owner_id', 191)->nullable()->index();
            $table->string('join_code', 32)->nullable()->unique();
            // invitation_only | join_code
            $table->string('join_method', 32)->default('invitation_only');
            $table->json('settings')->nullable();
            $table->json('billing')->nullable();
            $table->timestamps();
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('user_id', 191)->index();
            $table->string('role', 64);
            $table->json('meta')->nullable();
            // The team a user works in when a request names none.
            $table->boolean('is_current')->default(false);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
        });

        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 64);
            $table->json('meta')->nullable();
            // sha256 of the token. The token itself exists only in the mail.
            $table->string('token_hash', 64)->unique();
            $table->string('invited_by', 191)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_by', 191)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'email']);
            $table->index('email');
        });

        Schema::create('team_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('handle', 64);
            $table->string('label');
            $table->json('permissions')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'handle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_roles');
        Schema::dropIfExists('team_invitations');
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
    }
};
