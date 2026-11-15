<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * More than one role per member, and permissions for one member alone.
 *
 * `team_members.role` stays the main role and keeps every meaning it had
 * (owner, last owner, invitations). A row in `team_member_roles` adds a role
 * on top, a row in `team_member_permissions` adds one permission. What a
 * member may do in a team is the union of all three. Nobody who uses none of
 * this has a row here, and nothing changes for them.
 *
 * The member is named by `team_id` and `user_id`, like `team_members` does.
 * Removing the member removes these rows (done by the model), deleting the
 * team removes them through the foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_member_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('user_id', 191);
            $table->string('role', 64);
            $table->timestamps();

            $table->unique(['team_id', 'user_id', 'role']);
            $table->index('role');
        });

        Schema::create('team_member_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('user_id', 191);
            $table->string('permission', 128);
            $table->timestamps();

            $table->unique(['team_id', 'user_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_member_permissions');
        Schema::dropIfExists('team_member_roles');
    }
};
